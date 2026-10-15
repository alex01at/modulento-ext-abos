<?php

declare(strict_types=1);

namespace Modulento\Abos;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Modulento\Core\Support\Clock;
use PDO;
use PDOException;

/**
 * The plan an account has now, or had. Plan definitions themselves live in
 * Plans; this class only ever touches x_subscription (and, through a join,
 * reads a plan's columns for the one that counts for an account).
 */
final class Subscriptions
{
    public function __construct(private PDO $db, private Plans $plans)
    {
    }

    /**
     * Gives the account a plan from now on, or none with null. The
     * subscription it had is cancelled, not deleted. Without an end the
     * plan is open-ended, as when an operator grants it by hand.
     */
    public function assign(int $accountId, ?int $planId, ?string $periodEnd = null, ?string $providerRef = null, int $quantity = 1): void
    {
        if ($periodEnd !== null && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $periodEnd) !== 1) {
            throw new InvalidArgumentException('subscription: period end');
        }
        if ($planId !== null && !$this->plans->planIsActive($planId)) {
            throw new InvalidArgumentException('subscription: unknown or inactive plan');
        }

        $now = Clock::now();
        // Inside a transaction of the caller (an order settled by its payment), this one joins it.
        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            $this->db->prepare("UPDATE x_subscription SET status = 'canceled', updated_at = :now WHERE account_id = :account AND status <> 'canceled'")
                ->execute(['now' => $now, 'account' => $accountId]);
            if ($planId !== null) {
                $this->db->prepare("INSERT INTO x_subscription (account_id, plan_id, status, period_end, provider_ref, quantity, created_at, updated_at) VALUES (:account, :plan, 'active', :end, :ref, :quantity, :created, :updated)")
                    ->execute(['account' => $accountId, 'plan' => $planId, 'end' => $periodEnd, 'ref' => $providerRef, 'quantity' => max(1, $quantity), 'created' => $now, 'updated' => $now]);
            }
            if ($own) {
                $this->db->commit();
            }
        } catch (PDOException $e) {
            if ($own) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** The newest subscriptions of all accounts, for the administration. */
    public function recent(int $limit): array
    {
        $rows = $this->db->query(
            'SELECT s.id, s.status, s.period_end, s.created_at, a.email, p.name AS plan_name
             FROM x_subscription s JOIN account a ON a.id = s.account_id JOIN x_subscription_plan p ON p.id = s.plan_id
             ORDER BY s.id DESC LIMIT ' . max(1, $limit)
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn (array $row) => [
            'id' => (int) $row['id'],
            'status' => $row['status'],
            'period_end' => $row['period_end'],
            'created_at' => $row['created_at'],
            'email' => $row['email'],
            'plan_name' => $row['plan_name'],
        ], $rows);
    }

    /** The subscription that counts for the account now, or null. */
    public function current(int $accountId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT s.id, s.status, s.period_end, s.provider_ref, s.quantity, s.stripe_item_ref,
                    p.slug, p.name, p.features, p.offer_limits, p.max_images_per_offer, p.per_seat, p.min_quantity
             FROM x_subscription s JOIN x_subscription_plan p ON p.id = s.plan_id
             WHERE s.account_id = :account AND s.status IN ('trialing', 'active', 'past_due')
               AND (s.period_end IS NULL OR s.period_end > :now)
             ORDER BY s.id DESC LIMIT 1"
        );
        $stmt->execute(['account' => $accountId, 'now' => Clock::now()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : [
            'id' => (int) $row['id'],
            'status' => $row['status'],
            'period_end' => $row['period_end'],
            'provider_ref' => $row['provider_ref'],
            'quantity' => (int) $row['quantity'],
            'stripe_item_ref' => $row['stripe_item_ref'],
            'slug' => $row['slug'],
            'name' => $row['name'],
            'features' => $this->features($row['features']),
            'offer_limits' => $this->offerLimits($row['offer_limits']),
            'max_images_per_offer' => $row['max_images_per_offer'] !== null ? (int) $row['max_images_per_offer'] : null,
            'per_seat' => (bool) $row['per_seat'],
            'min_quantity' => (int) $row['min_quantity'],
        ];
    }

    /** The live seat count, for a plan priced per_seat - written by Billing::setQuantity(), which also keeps Stripe in sync. */
    public function updateQuantity(int $subscriptionId, int $quantity): void
    {
        $this->db->prepare('UPDATE x_subscription SET quantity = :quantity, updated_at = :now WHERE id = :id')
            ->execute(['quantity' => max(1, $quantity), 'now' => Clock::now(), 'id' => $subscriptionId]);
    }

    /** Captures the Stripe subscription *item* id once, right after a Stripe checkout completes - quantity lives there in Stripe's model. */
    public function setStripeItemRefByProviderRef(string $providerRef, string $itemRef): void
    {
        $this->db->prepare('UPDATE x_subscription SET stripe_item_ref = :item WHERE provider_ref = :ref')
            ->execute(['item' => $itemRef, 'ref' => $providerRef]);
    }

    /**
     * Every account whose plan currently grants a feature - for a listing
     * that wants to place them first, not just answer "may this one
     * account". The feature is sold regardless of which app is active, so
     * this intentionally does not apply app-scoping - an account that paid
     * for a feature keeps it even while another app is the active one.
     *
     * @return int[]
     */
    public function accountIdsWithFeature(string $feature): array
    {
        $stmt = $this->db->prepare(
            "SELECT s.account_id, p.features FROM x_subscription s JOIN x_subscription_plan p ON p.id = s.plan_id
             WHERE s.status IN ('trialing', 'active', 'past_due') AND (s.period_end IS NULL OR s.period_end > :now)"
        );
        $stmt->execute(['now' => Clock::now()]);

        $ids = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (in_array($feature, $this->features($row['features']), true)) {
                $ids[] = (int) $row['account_id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /** The subscription a Stripe subscription id belongs to, with the months of its plan. */
    public function byProviderRef(string $ref): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT s.id, s.account_id, s.status, s.period_end, p.period_months, p.name AS plan_name, p.currency
             FROM x_subscription s JOIN x_subscription_plan p ON p.id = s.plan_id WHERE s.provider_ref = :ref ORDER BY s.id DESC LIMIT 1'
        );
        $stmt->execute(['ref' => $ref]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : ['id' => (int) $row['id'], 'account_id' => (int) $row['account_id'], 'status' => $row['status'], 'period_end' => $row['period_end'], 'months' => (int) $row['period_months'], 'plan_name' => $row['plan_name']];
    }

    /** A paid renewal: the subscription runs on for the months of its plan, from its end or from now. */
    public function extend(int $subscriptionId, int $months): void
    {
        $stmt = $this->db->prepare('SELECT period_end FROM x_subscription WHERE id = :id');
        $stmt->execute(['id' => $subscriptionId]);
        $end = $stmt->fetchColumn();
        $from = is_string($end) && $end > Clock::now() ? $end : Clock::now();

        $this->db->prepare("UPDATE x_subscription SET status = 'active', period_end = :end, updated_at = :now WHERE id = :id")
            ->execute(['end' => self::addMonths($from, $months), 'now' => Clock::now(), 'id' => $subscriptionId]);
    }

    /** Stripe reports a change of the subscription's state (past due, cancelled). */
    public function setStatusByProviderRef(string $ref, string $status): void
    {
        $this->db->prepare('UPDATE x_subscription SET status = :status, updated_at = :now WHERE provider_ref = :ref AND status <> :canceled')
            ->execute(['status' => $status, 'now' => Clock::now(), 'ref' => $ref, 'canceled' => 'canceled']);
    }

    /** A UTC time of the form Y-m-d H:i:s, moved by whole months. */
    public static function addMonths(string $from, int $months): string
    {
        return (new DateTimeImmutable($from, new DateTimeZone('UTC')))->modify('+' . $months . ' months')->format('Y-m-d H:i:s');
    }

    /** @return list<string> */
    private function features(string $list): array
    {
        return array_values(array_filter(explode(',', $list), fn (string $f) => $f !== ''));
    }

    /** @return array<string, int> */
    private function offerLimits(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? array_map('intval', $decoded) : [];
    }
}
