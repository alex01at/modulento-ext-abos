<?php

declare(strict_types=1);

namespace Modulento\Abos;

use Closure;
use InvalidArgumentException;
use Modulento\Core\App;
use PDO;
use PDOException;

/**
 * Plans an operator sells. A plan can be scoped to one specific "app"-type
 * extension (freelancer, shop, auction, card - only one is ever active at
 * a time, enforced by the core) or left general, available under any app.
 *
 * The currently active app is resolved lazily, through the closure given
 * to the constructor, the moment a scoped list (plans()/activePlans()) is
 * actually asked for - never at registration time, since other extensions
 * may still be loading and $app->extensions->loaded() would be incomplete.
 * all() and plan() stay unscoped on purpose: the administration manages
 * every plan, including one written for an app that is not active right
 * now (or not installed any more) - same reasoning the FAQ extension's own
 * extension_id column already uses for its entries.
 */
final class Plans
{
    /** @param Closure(): ?string $activeAppId */
    public function __construct(private PDO $db, private Closure $activeAppId)
    {
    }

    /**
     * The one "app"-type extension currently loaded, or null (none, or -
     * in principle unreachable since the core enforces exactly one -
     * several). Only ever meaningful once every extension has registered,
     * so this must not be called from Extension::register() itself.
     */
    public static function resolveActiveAppId(App $app): ?string
    {
        foreach ($app->extensions->loaded() as $id => $manifest) {
            if ($manifest->type === 'app') {
                return $id;
            }
        }

        return null;
    }

    /** Whether a plan (as plan()/all() return it) is visible under the app that is active right now. */
    public function isVisible(array $plan): bool
    {
        return $plan['app_id'] === null || $plan['app_id'] === ($this->activeAppId)();
    }

    /** One plan, any app, active or not - for the administration, and for internal lookups that check visibility themselves. */
    public function plan(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM x_subscription_plan WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->cast($row);
    }

    /** Every plan, any app, active or not - for the administration's own plan list. */
    public function all(): array
    {
        $rows = $this->db->query('SELECT * FROM x_subscription_plan ORDER BY price_cents, id')->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn (array $row) => $this->cast($row), $rows);
    }

    /** Every plan visible under the app that is active now, active or not - what SubscriptionProvider::plans() answers. */
    public function plans(): array
    {
        return array_values(array_filter($this->all(), fn (array $plan) => $this->isVisible($plan)));
    }

    /** The plans that can be booked now, visible under the active app - the public overview, and SubscriptionProvider::activePlans(). */
    public function activePlans(): array
    {
        return array_values(array_filter($this->plans(), fn (array $plan) => $plan['active']));
    }

    /** @param list<string> $features */
    public function createPlan(string $slug, string $name, ?string $appId, int $priceCents, string $currency, int $periodMonths, array $features): int
    {
        if (preg_match('/^[a-z0-9-]{1,40}$/', $slug) !== 1) {
            throw new InvalidArgumentException('plan: slug');
        }
        $values = $this->validated($name, $priceCents, $currency, $periodMonths, $features, [], null, false, 1);

        try {
            $stmt = $this->db->prepare(
                'INSERT INTO x_subscription_plan (slug, name, app_id, price_cents, currency, period_months, features, offer_limits, max_images_per_offer)
                 VALUES (:slug, :name, :app_id, :price, :currency, :period, :features, :offer_limits, :max_images_per_offer)'
            );
            $stmt->execute(['slug' => $slug, 'app_id' => $appId]
                + array_intersect_key($values, array_flip(['name', 'price', 'currency', 'period', 'features', 'offer_limits', 'max_images_per_offer'])));
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new InvalidArgumentException('plan: slug taken', 0, $e);
            }
            throw $e;
        }

        return (int) $this->db->lastInsertId();
    }

    /**
     * Changes a plan. The key and the app it is scoped to stay; a plan
     * that is no longer active is not offered any more, but the accounts
     * that have it keep their features until it ends.
     *
     * @param list<string> $features
     * @param array<string, int> $offerLimits offer type id => how many; a type left out is not allowed at all
     * @param bool $perSeat whether price_cents is the price of one seat, not the whole plan
     * @param int $minQuantity the floor an account is always billed for, even with fewer seats actually in use
     */
    public function updatePlan(int $id, string $name, ?string $appId, int $priceCents, string $currency, int $periodMonths, array $features, bool $active, array $offerLimits, ?int $maxImagesPerOffer, bool $perSeat, int $minQuantity): void
    {
        $values = $this->validated($name, $priceCents, $currency, $periodMonths, $features, $offerLimits, $maxImagesPerOffer, $perSeat, $minQuantity);
        $stmt = $this->db->prepare(
            'UPDATE x_subscription_plan SET name = :name, app_id = :app_id, price_cents = :price, currency = :currency, period_months = :period,
                features = :features, offer_limits = :offer_limits, max_images_per_offer = :max_images_per_offer,
                per_seat = :per_seat, min_quantity = :min_quantity, active = :active WHERE id = :id'
        );
        $stmt->execute($values + ['app_id' => $appId, 'active' => $active ? 1 : 0, 'id' => $id]);
    }

    /** Deletes a plan that no account has had. A plan with a history can only be switched off. */
    public function deletePlan(int $id): void
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM x_subscription WHERE plan_id = :id');
        $stmt->execute(['id' => $id]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new InvalidArgumentException('plan: in use');
        }
        $this->db->prepare('DELETE FROM x_subscription_plan WHERE id = :id')->execute(['id' => $id]);
    }

    /** How many accounts have each plan right now - plan id => count, plans with none left out. Unscoped: a count of real subscriptions, whatever app is active now. */
    public function subscriberCounts(): array
    {
        $stmt = $this->db->prepare(
            "SELECT plan_id, COUNT(*) AS n FROM x_subscription
             WHERE status IN ('trialing', 'active', 'past_due') AND (period_end IS NULL OR period_end > :now)
             GROUP BY plan_id"
        );
        $stmt->execute(['now' => \Modulento\Core\Support\Clock::now()]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    public function planIsActive(int $planId): bool
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM x_subscription_plan WHERE id = :id AND active = 1');
        $stmt->execute(['id' => $planId]);

        return (int) $stmt->fetchColumn() === 1;
    }

    /**
     * @param list<string> $features
     * @param array<string, int> $offerLimits
     * @return array{name: string, price: int, currency: string, period: int, features: string, offer_limits: string, max_images_per_offer: int|null, per_seat: int, min_quantity: int}
     */
    private function validated(string $name, int $priceCents, string $currency, int $periodMonths, array $features, array $offerLimits, ?int $maxImagesPerOffer, bool $perSeat, int $minQuantity): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new InvalidArgumentException('plan: name');
        }
        if ($priceCents < 0 || preg_match('/^[A-Z]{3}$/', $currency) !== 1 || $periodMonths < 1 || $periodMonths > 36) {
            throw new InvalidArgumentException('plan: price, currency or period');
        }
        if (count($features) > 20 || array_filter($features, fn ($f) => !is_string($f) || preg_match('/^[a-z0-9._-]{1,60}$/', $f) !== 1) !== []) {
            throw new InvalidArgumentException('plan: features');
        }
        if (array_filter($offerLimits, fn ($n) => !is_int($n) || $n < 0) !== [] || ($maxImagesPerOffer !== null && $maxImagesPerOffer < 0)) {
            throw new InvalidArgumentException('plan: limits');
        }
        if ($minQuantity < 1 || $minQuantity > 10000) {
            throw new InvalidArgumentException('plan: min quantity');
        }

        return [
            'name' => $name, 'price' => $priceCents, 'currency' => $currency, 'period' => $periodMonths,
            'features' => implode(',', array_values(array_unique($features))),
            'offer_limits' => json_encode($offerLimits, JSON_FORCE_OBJECT),
            'max_images_per_offer' => $maxImagesPerOffer,
            'per_seat' => $perSeat ? 1 : 0,
            'min_quantity' => $minQuantity,
        ];
    }

    private function cast(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'slug' => $row['slug'],
            'name' => $row['name'],
            'app_id' => $row['app_id'],
            'price_cents' => (int) $row['price_cents'],
            'currency' => $row['currency'],
            'period_months' => (int) $row['period_months'],
            'features' => $this->features($row['features']),
            'offer_limits' => $this->offerLimits($row['offer_limits']),
            'max_images_per_offer' => $row['max_images_per_offer'] !== null ? (int) $row['max_images_per_offer'] : null,
            'per_seat' => (bool) $row['per_seat'],
            'min_quantity' => (int) $row['min_quantity'],
            'active' => (bool) $row['active'],
        ];
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
