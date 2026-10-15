<?php

declare(strict_types=1);

namespace Modulento\Abos;

use InvalidArgumentException;
use Modulento\Core\Controller\Controller;
use Modulento\Core\Payment\PaymentException;
use Modulento\Core\Payment\Payments;
use Modulento\Core\Support\Countries;
use Modulento\Core\Support\Session;

/**
 * What customers see of subscriptions and do with them: the plans on offer, ordering
 * one by transfer or Stripe, the order's page, and ending a Stripe subscription.
 */
final class SubscriptionController extends Controller
{
    public function overview(array $params): void
    {
        $app = $this->app;
        $plans = Services::plans($app);
        $billing = Services::billing($app);

        $this->render('@abos/subscriptions/index.twig', [
            'plans' => array_map(fn (array $plan) => $plan + ['feature_labels' => $this->labels($plan['features'])], $plans->activePlans()),
            'methods' => $billing->methods(),
            'signed_in' => $app->auth->account() !== null,
        ]);
    }

    public function account(array $params): void
    {
        $app = $this->app;
        $accountId = (int) $app->auth->account()['id'];
        $subscriptions = Services::subscriptions($app);
        $current = $subscriptions->current($accountId);

        $this->render('@abos/account_subscription.twig', [
            'current' => $current === null ? null : $current + ['feature_labels' => $this->labels($current['features'])],
            'invoices' => Services::invoices($app)->ofAccount($accountId),
        ]);
    }

    /** The page before paying: the billing address (kept for later orders) and the ways to pay. */
    public function checkout(array $params): void
    {
        $app = $this->app;
        $plans = Services::plans($app);
        $plan = $plans->plan((int) $params['id']);
        if ($plan === null || !$plan['active'] || !$plans->isVisible($plan)) {
            http_response_code(404);
            $this->render('error.twig', ['status' => 404, 'message_key' => 'core.error.not_found']);
            return;
        }

        $this->render('@abos/subscriptions/checkout.twig', [
            'plan' => $plan + ['feature_labels' => $this->labels($plan['features'])],
            'address' => Services::invoices($app)->billingAddress((int) $app->auth->account()['id']) ?? [],
            'methods' => Services::billing($app)->methods(),
            'countries' => Countries::CODES,
        ]);
    }

    /** The buyer chooses a way to pay: a transfer shows the bank details, Stripe leads to its checkout. */
    public function order(array $params): void
    {
        $app = $this->app;
        $accountId = (int) $app->auth->account()['id'];
        $planId = (int) $params['id'];
        $plans = Services::plans($app);
        $plan = $plans->plan($planId);
        $billing = Services::billing($app);
        $method = (string) ($_POST['method'] ?? '');
        // A flat plan is always one unit; only a per-seat plan reads its own
        // floor and the buyer's requested count.
        $quantity = $plan !== null && $plan['per_seat'] ? max($plan['min_quantity'], (int) ($_POST['quantity'] ?? $plan['min_quantity'])) : 1;
        $offered = $billing->methods();
        $back = '/subscriptions/' . $planId . '/checkout';

        try {
            Services::invoices($app)->saveBillingAddress($accountId, [
                'name' => $_POST['name'] ?? '',
                'street' => $_POST['street'] ?? '',
                'postal_code' => $_POST['postal_code'] ?? '',
                'city' => $_POST['city'] ?? '',
                'country' => $_POST['country'] ?? '',
            ]);

            if ($method === 'transfer' && $offered['transfer']) {
                $order = $billing->startTransfer($accountId, $planId, $quantity);
                $this->redirect('/subscriptions/orders/' . $order['id']);
                return;
            }
            if ($method === 'stripe' && $offered['stripe']) {
                $url = $billing->startStripe(
                    $accountId,
                    $planId,
                    $app->url('/account/subscription', null, true),
                    $app->url('/subscriptions', null, true),
                    $quantity
                );
                header('Location: ' . $url);
                return;
            }
            Session::flash('error', $this->trans('abos.error.method'));
        } catch (PaymentException $e) {
            Session::flash('error', $this->trans(Payments::report($e)));
        } catch (InvalidArgumentException $e) {
            Session::flash('error', $this->trans(str_contains($e->getMessage(), 'address')
                ? 'abos.error.address'
                : 'abos.error.unavailable'));
        }

        $this->redirect($back);
    }

    /** One of the account's own orders: the bank details while the transfer is open, the state afterwards. */
    public function orderPage(array $params): void
    {
        $app = $this->app;
        $billing = Services::billing($app);
        $order = $billing->orderOf((int) $app->auth->account()['id'], (int) $params['id']);
        if ($order === null) {
            http_response_code(404);
            $this->render('error.twig', ['status' => 404, 'message_key' => 'core.error.not_found']);
            return;
        }

        $this->render('@abos/subscriptions/order.twig', [
            'order' => $order,
            'bank' => $order['method'] === 'transfer' && $order['status'] === 'pending' ? $billing->bank() : null,
        ]);
    }

    /** One of the account's own invoices, to print or keep. */
    public function invoice(array $params): void
    {
        $app = $this->app;
        $invoice = Services::invoices($app)->find((int) $params['id'], (int) $app->auth->account()['id']);
        if ($invoice === null) {
            http_response_code(404);
            $this->render('error.twig', ['status' => 404, 'message_key' => 'core.error.not_found']);
            return;
        }

        $this->render('@abos/subscriptions/invoice.twig', ['invoice' => $invoice, 'back' => '/account/subscription']);
    }

    public function cancel(array $params): void
    {
        $app = $this->app;
        try {
            if (Services::billing($app)->cancelOwn((int) $app->auth->account()['id'])) {
                Session::flash('success', $this->trans('abos.account.cancelled'));
            } else {
                Session::flash('error', $this->trans('abos.account.cancel_none'));
            }
        } catch (PaymentException $e) {
            Session::flash('error', $this->trans(Payments::report($e)));
        }

        $this->redirect('/account/subscription');
    }

    /** The names of the features as the site shows them; a key nobody declared is shown as it is. */
    private function labels(array $features): array
    {
        $declared = $this->app->subscriptions->declaredFeatures();

        return array_map(fn (string $key) => isset($declared[$key]) ? $this->trans($declared[$key]) : $key, $features);
    }
}
