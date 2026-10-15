<?php

declare(strict_types=1);

namespace Modulento\Abos;

use InvalidArgumentException;
use Modulento\Core\Payment\BankAccount;
use Modulento\Core\Support\Clock;
use Modulento\Core\Support\Settings;
use PDO;
use PDOException;

/**
 * The invoices for subscriptions: the seller's details, the buyer's address, and
 * the numbered invoices. Prices are gross; the tax is taken out of them at the
 * rate the operator sets (0 for none).
 */
final class Invoices
{
    private const ISSUER = 'abos.invoice_issuer';

    public function __construct(private PDO $db, private Settings $settings)
    {
    }

    // --- The seller ---------------------------------------------------------------

    /** @return array{name: string, street: string, postal_code: string, city: string, country: string, vat_id: string, tax_rate: int, note: string}|null */
    public function issuer(): ?array
    {
        $stored = $this->settings->get(self::ISSUER);
        $issuer = $stored !== '' ? json_decode($stored, true) : null;

        return is_array($issuer) && ($issuer['name'] ?? '') !== '' ? $issuer : null;
    }

    public function saveIssuer(array $input): void
    {
        $issuer = [
            'name' => $this->text($input['name'] ?? '', 150),
            'street' => $this->text($input['street'] ?? '', 200),
            'postal_code' => $this->text($input['postal_code'] ?? '', 20),
            'city' => $this->text($input['city'] ?? '', 100),
            'country' => strtoupper($this->text($input['country'] ?? '', 2)),
            'vat_id' => strtoupper(preg_replace('/\s+/', '', $this->text($input['vat_id'] ?? '', 20)) ?? ''),
            'tax_rate' => (int) ($input['tax_rate'] ?? -1),
            'note' => $this->text($input['note'] ?? '', 300),
        ];
        if ($issuer['name'] === '' || $issuer['street'] === '' || $issuer['postal_code'] === '' || $issuer['city'] === ''
            || preg_match('/^[A-Z]{2}$/', $issuer['country']) !== 1 || $issuer['tax_rate'] < 0 || $issuer['tax_rate'] > 30
            || ($issuer['vat_id'] !== '' && preg_match('/^[A-Z]{2}[A-Z0-9]{2,18}$/', $issuer['vat_id']) !== 1)) {
            throw new InvalidArgumentException('invoice: seller details');
        }

        $this->settings->set(self::ISSUER, (string) json_encode($issuer, JSON_UNESCAPED_UNICODE));
    }

    // --- The buyer ----------------------------------------------------------------

    /** @return array{name: string, street: string, postal_code: string, city: string, country: string}|null */
    public function billingAddress(int $accountId): ?array
    {
        $stmt = $this->db->prepare('SELECT name, street, postal_code, city, country FROM x_subscription_billing_address WHERE account_id = :account');
        $stmt->execute(['account' => $accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @return array{name: string, street: string, postal_code: string, city: string, country: string} the address as it is kept */
    public function saveBillingAddress(int $accountId, array $input): array
    {
        $address = [
            'name' => $this->text($input['name'] ?? '', 150),
            'street' => $this->text($input['street'] ?? '', 200),
            'postal_code' => $this->text($input['postal_code'] ?? '', 20),
            'city' => $this->text($input['city'] ?? '', 100),
            'country' => strtoupper($this->text($input['country'] ?? '', 2)),
        ];
        if (in_array('', $address, true) || preg_match('/^[A-Z]{2}$/', $address['country']) !== 1) {
            throw new InvalidArgumentException('address: incomplete');
        }

        $values = ['account' => $accountId, 'name' => $address['name'], 'street' => $address['street'], 'postal' => $address['postal_code'], 'city' => $address['city'], 'country' => $address['country'], 'now' => Clock::now()];
        // Decided by what is stored, not by the rows an UPDATE touched (MariaDB counts changed rows only).
        if ($this->billingAddress($accountId) === null) {
            $this->db->prepare('INSERT INTO x_subscription_billing_address (account_id, name, street, postal_code, city, country, updated_at) VALUES (:account, :name, :street, :postal, :city, :country, :now)')->execute($values);
        } else {
            $this->db->prepare('UPDATE x_subscription_billing_address SET name = :name, street = :street, postal_code = :postal, city = :city, country = :country, updated_at = :now WHERE account_id = :account')->execute($values);
        }

        return $address;
    }

    // --- The invoices -------------------------------------------------------------

    /**
     * Issues the invoice for a payment, once: a second call with the same source
     * returns the invoice that exists.
     *
     * @param int $grossCents the price paid, tax included
     */
    public function issue(?int $accountId, string $source, string $planName, int $months, int $grossCents, string $currency): array
    {
        $existing = $this->bySource($source);
        if ($existing !== null) {
            return $existing;
        }

        $issuer = $this->issuer() ?? throw new InvalidArgumentException('invoice: seller details missing');
        $buyer = $accountId !== null ? $this->billingAddress($accountId) : null;
        if ($buyer === null) {
            throw new InvalidArgumentException('invoice: buyer address missing');
        }

        $rate = (int) $issuer['tax_rate'];
        $net = (int) round($grossCents * 100 / (100 + $rate));
        $tax = $grossCents - $net;
        $year = (int) gmdate('Y');

        for ($try = 0; ; $try++) {
            $stmt = $this->db->prepare('SELECT COALESCE(MAX(seq), 0) + 1 FROM x_subscription_invoice WHERE year = :year');
            $stmt->execute(['year' => $year]);
            $seq = (int) $stmt->fetchColumn();
            try {
                $this->db->prepare(
                    'INSERT INTO x_subscription_invoice (number, year, seq, source_ref, account_id, plan_name, months, currency, net_cents, tax_rate, tax_cents, gross_cents,
                        buyer_name, buyer_street, buyer_postal_code, buyer_city, buyer_country, issuer, issued_at)
                     VALUES (:number, :year, :seq, :source, :account, :plan, :months, :currency, :net, :rate, :tax, :gross,
                        :bname, :bstreet, :bpostal, :bcity, :bcountry, :issuer, :now)'
                )->execute([
                    'number' => sprintf('RE-%d-%06d', $year, $seq), 'year' => $year, 'seq' => $seq, 'source' => $source,
                    'account' => $accountId, 'plan' => $planName, 'months' => $months, 'currency' => $currency,
                    'net' => $net, 'rate' => $rate, 'tax' => $tax, 'gross' => $grossCents,
                    'bname' => $buyer['name'], 'bstreet' => $buyer['street'], 'bpostal' => $buyer['postal_code'], 'bcity' => $buyer['city'], 'bcountry' => $buyer['country'],
                    'issuer' => (string) json_encode($issuer, JSON_UNESCAPED_UNICODE), 'now' => Clock::now(),
                ]);
                break;
            } catch (PDOException $e) {
                // Two invoices at once took the same number: the next one is taken.
                if ($e->getCode() !== '23000' || $try >= 4) {
                    throw $e;
                }
                if ($this->bySource($source) !== null) {
                    break;
                }
            }
        }

        return $this->bySource($source) ?? throw new InvalidArgumentException('invoice: not stored');
    }

    /** @return list<array<string, mixed>> the account's invoices, newest first */
    public function ofAccount(int $accountId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM x_subscription_invoice WHERE account_id = :account ORDER BY id DESC');
        $stmt->execute(['account' => $accountId]);

        return array_map([$this, 'invoice'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function find(int $id, ?int $accountId = null): ?array
    {
        $sql = 'SELECT * FROM x_subscription_invoice WHERE id = :id' . ($accountId !== null ? ' AND account_id = :account' : '');
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id] + ($accountId !== null ? ['account' => $accountId] : []));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->invoice($row);
    }

    /** @return list<array<string, mixed>> the newest invoices of all accounts, for the administration */
    public function recent(int $limit): array
    {
        return array_map([$this, 'invoice'], $this->db->query('SELECT * FROM x_subscription_invoice ORDER BY id DESC LIMIT ' . max(1, $limit))->fetchAll(PDO::FETCH_ASSOC));
    }

    private function bySource(string $source): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM x_subscription_invoice WHERE source_ref = :source');
        $stmt->execute(['source' => $source]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->invoice($row);
    }

    private function invoice(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'number' => $row['number'],
            'issued_at' => $row['issued_at'],
            'plan_name' => $row['plan_name'],
            'months' => (int) $row['months'],
            'currency' => $row['currency'],
            'net_cents' => (int) $row['net_cents'],
            'tax_rate' => (int) $row['tax_rate'],
            'tax_cents' => (int) $row['tax_cents'],
            'gross_cents' => (int) $row['gross_cents'],
            'buyer' => ['name' => $row['buyer_name'], 'street' => $row['buyer_street'], 'postal_code' => $row['buyer_postal_code'], 'city' => $row['buyer_city'], 'country' => $row['buyer_country']],
            'issuer' => json_decode($row['issuer'], true) ?: [],
            'account_id' => $row['account_id'] !== null ? (int) $row['account_id'] : null,
        ];
    }

    private function text(mixed $value, int $max): string
    {
        $text = trim(is_string($value) ? $value : '');

        return mb_substr($text, 0, $max);
    }
}
