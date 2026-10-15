<?php

declare(strict_types=1);

namespace Modulento\Abos;

use Modulento\Core\Subscription\SubscriptionProvider;

/**
 * The other half of the core's Subscriptions facade: a thin adapter that
 * answers every question the core (and another extension, through
 * subscriptionFeature()) asks, by delegating to this addon's own Plans,
 * Subscriptions and Billing classes. No state of its own.
 */
final class Provider implements SubscriptionProvider
{
    public function __construct(private Plans $plans, private Subscriptions $subscriptions, private Billing $billing)
    {
    }

    public function allows(int $accountId, string $feature): bool
    {
        $current = $this->subscriptions->current($accountId);

        return $current !== null && in_array($feature, $current['features'], true);
    }

    public function grants(int $accountId, string $feature): bool
    {
        return $this->allows($accountId, $feature);
    }

    public function offerLimit(int $accountId, string $offerType): ?int
    {
        $current = $this->subscriptions->current($accountId);

        return $current === null ? 0 : ($current['offer_limits'][$offerType] ?? null);
    }

    public function imageLimit(int $accountId): ?int
    {
        $current = $this->subscriptions->current($accountId);

        return $current === null ? 0 : $current['max_images_per_offer'];
    }

    public function accountIdsWithFeature(string $feature): array
    {
        return $this->subscriptions->accountIdsWithFeature($feature);
    }

    public function activePlans(): array
    {
        return $this->plans->activePlans();
    }

    public function plans(): array
    {
        return $this->plans->plans();
    }

    public function current(int $accountId): ?array
    {
        return $this->subscriptions->current($accountId);
    }

    public function setQuantity(int $accountId, int $quantity): void
    {
        $this->billing->setQuantity($accountId, $quantity);
    }
}
