<?php

declare(strict_types=1);

namespace Modulento\Abos;

use Modulento\Core\App;

/**
 * Builds this addon's own data classes against the running request's App -
 * the one place that wires Plans/Subscriptions/Invoices/Billing together,
 * so Extension::register() (for the SubscriptionProvider) and both
 * controllers construct the very same thing the very same way.
 */
final class Services
{
    public static function plans(App $app): Plans
    {
        return new Plans($app->db, fn (): ?string => Plans::resolveActiveAppId($app));
    }

    public static function subscriptions(App $app, ?Plans $plans = null): Subscriptions
    {
        return new Subscriptions($app->db, $plans ?? self::plans($app));
    }

    public static function invoices(App $app): Invoices
    {
        return new Invoices($app->db, $app->settings);
    }

    public static function billing(App $app): Billing
    {
        $plans = self::plans($app);
        $subscriptions = self::subscriptions($app, $plans);
        $invoices = self::invoices($app);

        return new Billing(
            $app->db,
            $app->settings,
            $plans,
            $subscriptions,
            $app->payments,
            $invoices,
            fn (string $to, string $template, array $data, string $locale): bool => $app->mailer->send($to, $template, $data, $locale),
            fn (string $path): string => $app->url($path, null, true),
        );
    }
}
