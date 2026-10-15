<?php

declare(strict_types=1);

namespace Modulento\Abos;

use Closure;
use InvalidArgumentException;
use Modulento\Core\Payment\BankAccount;
use Modulento\Core\Payment\PaymentException;
use Modulento\Core\Payment\Payments;
use Modulento\Core\Support\Clock;
use Modulento\Core\Support\Settings;
use PDO;
use PDOException;

/**
 * How an account pays for a plan: by bank transfer to the operator's bank
 * account, confirmed by hand once the money is there, or by Stripe on the
 * platform's own account. The money of a subscription belongs to the
 * operator, so it never goes to a provider. Every payment gets its
 * invoice, and a reminder goes out before a transfer subscription ends.
 */
final class Billing
{
    private const BANK = 'abos.bank_account';
    /** Days before the end of a period in which the reminder goes out. */
    private const REMIND_DAYS = 7;

    /**
     * @param Closure(string, string, array<string, mixed>, string): bool $notify sends a mail: address, template, data, locale
     * @param Closure(string): string $url the absolute address of a path on the site
     */
    public function __construct(
        private PDO $db,
        private Settings $settings,
        private Plans $plans,
        private Subscriptions $subscriptions,
        private Payments $payments,
        private Invoices $invoices,
        private Closure $notify,
        private Closure $url
    ) {
    }

    // --- The operator's bank account ----------------------------------------------

    /** @return array{holder: string, iban: string, bic: string}|null */
    public function bank(): ?array
    {
        $stored = $this->settings->get(self::BANK);
        $bank = $stored !== '' ? json_decode($stored, true) : null;

        return is_array($bank) && is_string($bank['iban'] ?? null) ? $bank : null;
    }

    /** Empty fields remove the account. The BIC is optional; the IBAN is checked. */
    public function saveBank(string $holder, string $iban, string $bic): void
    {
        $holder = trim($holder);
        $iban = BankAccount::normalize($iban);
        $bic = BankAccount::normalize($bic);

        if ($holder === '' && $iban === '' && $bic === '') {
            $this->settings->set(self::BANK, '');

            return;
        }
        if ($holder === '' || mb_strlen($holder) > 100 || !BankAccount::isIban($iban) || ($bic !== '' && !BankAccount::isBic($bic))) {
            throw new InvalidArgumentException('bank: holder, iban or bic');
        }

        $this->settings->set(self::BANK, (string) json_encode(['holder' => $holder, 'iban' => $iban, 'bic' => $bic], JSON_UNESCAPED_UNICODE));
    }

    // --- Which ways are offered -----------------------------------------------------

    /** @return array{transfer: bool, stripe: bool} the ways an account can pay for a plan now */
    public function methods(): array
    {
        return [
            // Nothing is sold before the seller's details for the invoices are there.
            'transfer' => $this->payments->isEnabled(Payments::TRANSFER) && $this->bank() !== null && $this->invoices->issuer() !== null,
            'stripe' => $this->payments->isEnabled(Payments::STRIPE) && $this->payments->stripeConfigured() && $this->invoices->issuer() !== null,
        ];
    }

    // --- Orders -----------------------------------------------------------------------

    /** An order to pay by transfer; the page shows the account and the reference. $quantity only matters for a per_seat plan. */
    public function startTransfer(int $accountId, int $planId, int $quantity = 1): array
    {
        return $this->createOrder($accountId, $planId, 'transfer', $quantity);
    }

    /** An order paid by Stripe: the address of the checkout the buyer goes to. */
    public function startStripe(int $accountId, int $planId, string $successUrl, string $cancelUrl, int $quantity = 1): string
    {
        $order = $this->createOrder($accountId, $planId, 'stripe', $quantity);
        $plan = $this->plans->plan($planId);
        // The checkout line item is priced per seat; Stripe multiplies by
        // quantity itself and the two totals are kept equal by createOrder().
        $unitAmount = $plan['per_seat'] ? $plan['price_cents'] : $order['amount_cents'];

        try {
            $session = $this->payments->startSubscriptionCheckout($unitAmount, $order['currency'], $plan['period_months'], $plan['name'], $order['reference'], $successUrl, $cancelUrl, $order['quantity']);
        } catch (PaymentException $e) {
            $this->setOrderStatus((int) $order['id'], 'canceled');
            throw $e;
        }

        $this->db->prepare('UPDATE x_subscription_order SET stripe_session = :session WHERE id = :id')->execute(['session' => $session['id'], 'id' => $order['id']]);

        return $session['url'];
    }

    /** One order of the account, or null if it is not the account's. */
    public function orderOf(int $accountId, int $orderId): ?array
    {
        $stmt = $this->db->prepare($this->orderSql() . ' WHERE o.id = :id AND o.account_id = :account');
        $stmt->execute(['id' => $orderId, 'account' => $accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->order($row);
    }

    /** The transfers still waiting for their money, for the administration. */
    public function pendingTransfers(): array
    {
        return array_map(fn (array $row) => $this->order($row), $this->db->query($this->orderSql() . " WHERE o.method = 'transfer' AND o.status = 'pending' ORDER BY o.id")->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * The operator confirms that the money of a transfer has arrived. The plan
     * runs for its months: from now, or from the end of a plan the account still
     * has, so that an early renewal loses no days.
     */
    public function confirmTransfer(int $orderId): void
    {
        $order = $this->orderById($orderId);
        if ($order === null || $order['method'] !== 'transfer' || $order['status'] !== 'pending') {
            throw new InvalidArgumentException('order: not a pending transfer');
        }

        $this->db->beginTransaction();
        try {
            if ($this->setOrderStatus($orderId, 'paid') !== 1) {
                $this->db->rollBack();
                throw new InvalidArgumentException('order: already settled');
            }
            $current = $this->subscriptions->current($order['account_id']);
            $from = $current !== null && $current['period_end'] !== null && $current['period_end'] > Clock::now() ? $current['period_end'] : Clock::now();
            $this->subscriptions->assign($order['account_id'], $order['plan_id'], Subscriptions::addMonths($from, $order['months']), null, $order['quantity']);
            $this->db->commit();
        } catch (PDOException $e) {
            $this->db->rollBack();
            throw $e;
        }

        $this->invoiceAndMail($order, 'order:' . $order['reference']);
    }

    /**
     * Changes the seat count of an account's running per-seat subscription -
     * the entry point an extension calls when a member joins or leaves a
     * team it bills for (see the card extension's Organizations class).
     * Clamped to the plan's floor, so removing members never bills for
     * fewer seats than the plan guarantees. Live on Stripe too, where a
     * checkout has already captured the subscription item id; otherwise
     * (a transfer subscription, or no change) only the local count moves.
     */
    public function setQuantity(int $accountId, int $quantity): void
    {
        $current = $this->subscriptions->current($accountId);
        if ($current === null) {
            throw new InvalidArgumentException('subscription: none active');
        }

        $quantity = max($quantity, $current['min_quantity']);
        if ($quantity === $current['quantity']) {
            return;
        }

        $this->subscriptions->updateQuantity($current['id'], $quantity);
        if ($current['stripe_item_ref'] !== null) {
            $this->payments->updateSubscriptionItemQuantity($current['stripe_item_ref'], $quantity);
        }
    }

    /** Stops the renewal of the account's Stripe subscription; it runs to the end of the period. */
    public function cancelOwn(int $accountId): bool
    {
        $current = $this->subscriptions->current($accountId);
        if ($current === null || $current['provider_ref'] === null) {
            return false;
        }

        $this->payments->cancelStripeSubscription($current['provider_ref']);

        return true;
    }

    // --- Stripe's notifications -------------------------------------------------------

    /** Called with every verified notification. Only the ones about subscriptions are acted on. */
    public function stripeEvent(array $event): void
    {
        $type = $event['type'] ?? null;
        $object = is_array($event['data']['object'] ?? null) ? $event['data']['object'] : [];

        if ($type === 'checkout.session.completed' && ($object['mode'] ?? null) === 'subscription') {
            $this->stripeSessionPaid($object);
        } elseif ($type === 'invoice.paid' && ($object['billing_reason'] ?? null) === 'subscription_cycle' && is_string($object['subscription'] ?? null)) {
            // The first invoice is the checkout's payment, handled above.
            $this->stripeRenewal($object);
        } elseif ($type === 'invoice.payment_failed' && is_string($object['subscription'] ?? null)) {
            $this->subscriptions->setStatusByProviderRef($object['subscription'], 'past_due');
        } elseif ($type === 'customer.subscription.deleted' && is_string($object['id'] ?? null)) {
            $this->subscriptions->setStatusByProviderRef($object['id'], 'canceled');
        }
    }

    private function stripeSessionPaid(array $session): void
    {
        $reference = $session['metadata']['subscription_order'] ?? $session['client_reference_id'] ?? null;
        $order = is_string($reference) ? $this->orderByReference($reference) : null;
        if ($order === null || $order['method'] !== 'stripe' || $order['status'] !== 'pending' || ($session['payment_status'] ?? null) !== 'paid') {
            return;
        }
        $currency = strtoupper((string) ($session['currency'] ?? ''));
        if (($session['amount_total'] ?? null) !== $order['amount_cents'] || $currency !== $order['currency']) {
            error_log('Subscription order ' . $order['reference'] . ' was reported with another amount or currency and is not recorded as paid');

            return;
        }

        $this->db->beginTransaction();
        try {
            if ($this->setOrderStatus((int) $order['id'], 'paid') !== 1) {
                $this->db->rollBack();

                return;
            }
            $subscription = is_string($session['subscription'] ?? null) ? $session['subscription'] : null;
            $this->subscriptions->assign($order['account_id'], $order['plan_id'], Subscriptions::addMonths(Clock::now(), $order['months']), $subscription, $order['quantity']);
            $this->db->commit();
        } catch (PDOException $e) {
            $this->db->rollBack();
            throw $e;
        }

        // Quantity lives on Stripe's subscription *item*, not the
        // subscription itself - captured once here so a later seat-count
        // change (setQuantity()) can update it without asking Stripe first.
        // Best-effort: a failure here never undoes the payment just recorded.
        if ($subscription !== null && $order['quantity'] > 1) {
            try {
                $itemId = $this->payments->subscriptionItemId($subscription);
                if ($itemId !== null) {
                    $this->subscriptions->setStripeItemRefByProviderRef($subscription, $itemId);
                }
            } catch (PaymentException $e) {
                error_log('Could not capture the Stripe subscription item id for ' . $order['reference'] . ': ' . $e->getMessage());
            }
        }

        $this->invoiceAndMail($order, 'order:' . $order['reference']);
    }

    /** A renewal Stripe charged: the subscription runs on, and the renewal gets its invoice. */
    private function stripeRenewal(array $invoice): void
    {
        $row = $this->subscriptions->byProviderRef($invoice['subscription']);
        if ($row === null) {
            return;
        }

        $this->subscriptions->extend($row['id'], $row['months']);
        $this->invoiceAndMail([
            'account_id' => $row['account_id'],
            'plan_name' => $row['plan_name'],
            'months' => $row['months'],
            'amount_cents' => (int) ($invoice['amount_paid'] ?? 0),
            'currency' => strtoupper((string) ($invoice['currency'] ?? '')),
        ], 'stripe:' . (string) ($invoice['id'] ?? $invoice['subscription']));
    }

    /**
     * Mails the reminder for each transfer subscription that ends within the next
     * week. One reminder per period: the end of the period is remembered.
     *
     * @return int the number of reminders sent
     */
    public function sendReminders(): int
    {
        $now = Clock::now();
        $stmt = $this->db->prepare(
            "SELECT s.id, s.period_end, p.name AS plan_name, a.id AS account_id, a.email, a.locale
             FROM x_subscription s JOIN x_subscription_plan p ON p.id = s.plan_id JOIN account a ON a.id = s.account_id
             WHERE s.status = 'active' AND s.provider_ref IS NULL AND s.period_end > :now AND s.period_end <= :until
               AND (s.reminded_for IS NULL OR s.reminded_for <> s.period_end)"
        );
        $stmt->execute(['now' => $now, 'until' => Clock::now(self::REMIND_DAYS * 86400)]);

        $sent = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['locale'] = $row['locale'] !== null && $row['locale'] !== '' ? $row['locale'] : 'de';
            $this->db->prepare('UPDATE x_subscription SET reminded_for = :end WHERE id = :id')->execute(['end' => $row['period_end'], 'id' => $row['id']]);
            $sent += ($this->notify)($row['email'], '@abos/emails/subscription_ending.txt.twig', [
                'plan' => $row['plan_name'],
                'until' => substr((string) $row['period_end'], 0, 10),
                'link' => ($this->url)('/subscriptions'),
            ], $row['locale']) ? 1 : 0;
        }

        return $sent;
    }

    // --- internals -----------------------------------------------------------------------

    /**
     * Issues the invoice for a payment and mails its link. A missing seller or buyer
     * address is logged: the payment itself stands.
     *
     * @param array{account_id: int, plan_name: string, months: int, amount_cents: int, currency: string} $payment
     */
    private function invoiceAndMail(array $payment, string $source): void
    {
        try {
            $invoice = $this->invoices->issue((int) $payment['account_id'], $source, $payment['plan_name'], (int) $payment['months'], (int) $payment['amount_cents'], $payment['currency']);
        } catch (InvalidArgumentException $e) {
            error_log('Subscription invoice for ' . $source . ' was not issued: ' . $e->getMessage());

            return;
        }

        $account = $this->db->prepare('SELECT email, locale FROM account WHERE id = :id');
        $account->execute(['id' => $payment['account_id']]);
        $who = $account->fetch(PDO::FETCH_ASSOC);
        if ($who === false) {
            return;
        }
        // The column defaults to German; an empty value is treated the same way.
        $who['locale'] = $who['locale'] !== null && $who['locale'] !== '' ? $who['locale'] : 'de';

        ($this->notify)($who['email'], '@abos/emails/subscription_invoice.txt.twig', [
            'number' => $invoice['number'],
            'plan' => $payment['plan_name'],
            'amount' => \Modulento\Core\Support\Money::format($invoice['gross_cents'], $invoice['currency'], $who['locale']),
            'link' => ($this->url)('/account/subscription/invoices/' . $invoice['id']),
        ], $who['locale']);
    }

    private function createOrder(int $accountId, int $planId, string $method, int $quantity = 1): array
    {
        $plan = $this->plans->plan($planId);
        if ($plan === null || !$plan['active'] || !$this->plans->isVisible($plan)) {
            throw new InvalidArgumentException('order: unknown or inactive plan');
        }
        if ($this->invoices->billingAddress($accountId) === null) {
            throw new InvalidArgumentException('order: address missing');
        }
        // A running Stripe subscription would be charged twice.
        $current = $this->subscriptions->current($accountId);
        if ($current !== null && $current['provider_ref'] !== null) {
            throw new InvalidArgumentException('order: a Stripe subscription is running');
        }

        // A flat plan is always one unit; a per-seat plan is never ordered
        // for fewer than its floor, even if a smaller number was passed in.
        $quantity = $plan['per_seat'] ? max($quantity, $plan['min_quantity']) : 1;
        $amount = $plan['price_cents'] * $quantity;

        for ($try = 0; ; $try++) {
            $reference = 'ABO-' . strtoupper(bin2hex(random_bytes(4)));
            try {
                $this->db->prepare("INSERT INTO x_subscription_order (account_id, plan_id, quantity, method, status, reference, amount_cents, currency, created_at) VALUES (:account, :plan, :quantity, :method, 'pending', :reference, :amount, :currency, :now)")
                    ->execute(['account' => $accountId, 'plan' => $planId, 'quantity' => $quantity, 'method' => $method, 'reference' => $reference, 'amount' => $amount, 'currency' => $plan['currency'], 'now' => Clock::now()]);
                break;
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000' || $try >= 4) {
                    throw $e;
                }
            }
        }

        return $this->orderById((int) $this->db->lastInsertId());
    }

    private function setOrderStatus(int $orderId, string $status): int
    {
        $stmt = $this->db->prepare("UPDATE x_subscription_order SET status = :status, paid_at = :paid WHERE id = :id AND status = 'pending'");
        $stmt->execute(['status' => $status, 'paid' => $status === 'paid' ? Clock::now() : null, 'id' => $orderId]);

        return $stmt->rowCount();
    }

    private function orderById(int $id): ?array
    {
        $stmt = $this->db->prepare($this->orderSql() . ' WHERE o.id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->order($row);
    }

    private function orderByReference(string $reference): ?array
    {
        $stmt = $this->db->prepare($this->orderSql() . ' WHERE o.reference = :reference');
        $stmt->execute(['reference' => $reference]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->order($row);
    }

    private function orderSql(): string
    {
        return 'SELECT o.id, o.account_id, o.plan_id, o.quantity, o.method, o.status, o.reference, o.amount_cents, o.currency, o.created_at, o.paid_at,
                       a.email, p.name AS plan_name, p.period_months, p.slug
                FROM x_subscription_order o
                JOIN account a ON a.id = o.account_id
                JOIN x_subscription_plan p ON p.id = o.plan_id';
    }

    private function order(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'account_id' => (int) $row['account_id'],
            'plan_id' => (int) $row['plan_id'],
            'quantity' => (int) $row['quantity'],
            'method' => $row['method'],
            'status' => $row['status'],
            'reference' => $row['reference'],
            'amount_cents' => (int) $row['amount_cents'],
            'currency' => $row['currency'],
            'created_at' => $row['created_at'],
            'paid_at' => $row['paid_at'],
            'email' => $row['email'],
            'plan_name' => $row['plan_name'],
            'months' => (int) $row['period_months'],
        ];
    }
}
