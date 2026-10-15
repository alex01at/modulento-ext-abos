<?php

declare(strict_types=1);

namespace Modulento\Abos;

use Modulento\Core\App;
use Modulento\Core\Extension\Extension as ExtensionContract;
use Modulento\Core\Extension\Registrar;
use Modulento\Core\Support\Router;

/**
 * Subscription plans, billing (bank transfer and Stripe) and invoices - the
 * provider half of the core's Subscriptions seam
 * (Modulento\Core\Subscription\SubscriptionProvider), registered here so
 * that every feature check an extension makes (allows()/grants()/
 * offerLimit()/imageLimit()) is answered against a real plan an account
 * paid for, instead of defaulting open.
 */
final class Extension implements ExtensionContract
{
    public const PERMISSION = 'abos.subscriptions.manage';

    public function register(Registrar $registrar): void
    {
        $app = $registrar->app;

        $registrar->permission(self::PERMISSION, 'abos.permission.subscriptions_manage');

        $registrar->routes(function (Router $router): void {
            $router->get('/subscriptions', [SubscriptionController::class, 'overview'], Router::PUBLIC);
            $router->get('/subscriptions/{id}/checkout', [SubscriptionController::class, 'checkout']);
            $router->post('/subscriptions/{id}/checkout', [SubscriptionController::class, 'order']);
            $router->get('/subscriptions/orders/{id}', [SubscriptionController::class, 'orderPage']);
            $router->get('/account/subscription', [SubscriptionController::class, 'account']);
            $router->get('/account/subscription/invoices/{id}', [SubscriptionController::class, 'invoice']);
            $router->post('/account/subscription/cancel', [SubscriptionController::class, 'cancel']);

            $router->get('/admin/subscriptions', [AdminController::class, 'index'], self::PERMISSION);
            $router->post('/admin/subscriptions/plans', [AdminController::class, 'createPlan'], self::PERMISSION);
            $router->get('/admin/subscriptions/plans/{id}', [AdminController::class, 'plan'], self::PERMISSION);
            $router->post('/admin/subscriptions/plans/{id}', [AdminController::class, 'updatePlan'], self::PERMISSION);
            $router->post('/admin/subscriptions/plans/{id}/delete', [AdminController::class, 'deletePlan'], self::PERMISSION);
            $router->post('/admin/subscriptions/bank', [AdminController::class, 'saveBank'], self::PERMISSION);
            $router->post('/admin/subscriptions/invoice-settings', [AdminController::class, 'saveIssuer'], self::PERMISSION);
            $router->get('/admin/subscriptions/invoices/{id}', [AdminController::class, 'invoice'], self::PERMISSION);
            $router->post('/admin/subscriptions/orders/{id}/paid', [AdminController::class, 'confirmTransfer'], self::PERMISSION);
            $router->post('/admin/subscriptions/assign', [AdminController::class, 'assign'], self::PERMISSION);
        });

        $registrar->adminMenu('abos.admin.menu', '/admin/subscriptions', self::PERMISSION, 'system');
        $registrar->accountLink('abos.account.nav', '/account/subscription');

        $plans = Services::plans($app);
        $registrar->subscriptionProvider(new Provider($plans, Services::subscriptions($app, $plans), Services::billing($app)));

        // Closes over this same request's $app: a Stripe webhook is its own
        // HTTP request, so loadEnabled() (and this register()) runs fresh
        // for it too, with that request's own $app - exactly the one this
        // handler needs.
        $registrar->stripeWebhook(function (array $event) use ($app): void {
            Services::billing($app)->stripeEvent($event);
        });

        // Unlike stripeWebhook() above, a cron task runs in its own process
        // (bin/cron.php), with its own $app - so this uses the $app the
        // scheduler hands in here, not the one captured above.
        $registrar->task('abos.send-reminders', 1440, function (App $app): void {
            Services::billing($app)->sendReminders();
        });
    }

}
