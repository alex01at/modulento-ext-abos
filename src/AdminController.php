<?php

declare(strict_types=1);

namespace Modulento\Abos;

use InvalidArgumentException;
use Modulento\Core\Controller\Controller;
use Modulento\Core\Support\Countries;
use Modulento\Core\Support\Money;
use Modulento\Core\Support\Session;

/**
 * Plans and their grants, in the administration: the dashboard, plan CRUD
 * (each plan optionally scoped to one "app"-type extension), the bank
 * account and issuer details for invoices, confirming transfers, and
 * assigning a plan to an account by hand.
 */
final class AdminController extends Controller
{
    public function index(array $params): void
    {
        $app = $this->app;
        $plans = Services::plans($app);
        $billing = Services::billing($app);

        $this->render('@abos/admin/subscriptions.twig', [
            'plans' => $plans->all(),
            'subscriber_counts' => $plans->subscriberCounts(),
            'offer_types' => array_map(fn ($type) => ['id' => $type->id(), 'label_key' => $type->labelKey()], $app->offers->types()),
            'recent' => Services::subscriptions($app)->recent(50),
            'periods' => [1, 3, 6, 12],
            'bank' => $billing->bank(),
            'transfers' => $billing->pendingTransfers(),
            'methods' => $billing->methods(),
            'issuer' => Services::invoices($app)->issuer(),
            'invoices' => Services::invoices($app)->recent(50),
            'countries' => Countries::CODES,
            'apps' => $this->appChoices(),
        ]);
    }

    public function createPlan(array $params): void
    {
        $app = $this->app;
        $price = Money::parse((string) ($_POST['price'] ?? ''));
        $features = preg_split('/[\s,]+/', (string) ($_POST['features'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $appId = $this->postedAppId();

        try {
            if ($price === null) {
                throw new InvalidArgumentException('plan: price');
            }
            Services::plans($app)->createPlan(
                trim((string) ($_POST['slug'] ?? '')),
                (string) ($_POST['name'] ?? ''),
                $appId,
                $price,
                strtoupper(trim((string) ($_POST['currency'] ?? ''))),
                (int) ($_POST['period_months'] ?? 0),
                $features
            );
            // A new plan starts without limits (unlimited); fine-tune those
            // afterwards in the edit form, same as with features.
            $app->adminLog->record($app->auth->account()['id'], 'abos_subscription_plan', null, trim((string) $_POST['slug']));
            Session::flash('success', $this->trans('abos.admin.flash.plan_created'));
        } catch (InvalidArgumentException $e) {
            Session::flash('error', $this->trans(str_contains($e->getMessage(), 'taken')
                ? 'abos.admin.error.slug_taken'
                : 'abos.admin.error.plan'));
        }

        $this->redirect('/admin/subscriptions');
    }

    /** One plan: its settings, the features it includes, and deleting it when nobody has had it. */
    public function plan(array $params): void
    {
        $app = $this->app;
        $plan = Services::plans($app)->plan((int) $params['id']);
        if ($plan === null) {
            http_response_code(404);
            $this->render('error.twig', ['status' => 404, 'message_key' => 'core.error.not_found']);
            return;
        }

        // Declared features (core's or an extension's own, fixed keys) as checkboxes;
        // whatever key a plan kept beyond those is the admin's own, free-typed one,
        // shown as an editable row - renaming it is just changing its text.
        $declared = $app->subscriptions->declaredFeatures();
        $custom = array_values(array_diff($plan['features'], array_keys($declared)));
        $this->render('@abos/admin/subscription_plan.twig', [
            'plan' => $plan,
            'price' => Money::input($plan['price_cents'], $app->translator->locale()),
            'features' => array_map(fn (string $key, string $labelKey) => ['key' => $key, 'label_key' => $labelKey], array_keys($declared), $declared),
            'custom_features' => $custom,
            'periods' => [1, 3, 6, 12],
            // Every offer type an installed extension registered, for one limit field each.
            'offer_types' => array_map(fn ($type) => ['id' => $type->id(), 'label_key' => $type->labelKey()], $app->offers->types()),
            'apps' => $this->appChoices(),
        ]);
    }

    public function updatePlan(array $params): void
    {
        $app = $this->app;
        $id = (int) $params['id'];
        $plans = Services::plans($app);
        $price = Money::parse((string) ($_POST['price'] ?? ''));
        // The boxes ticked, plus each non-empty row of the admin's own
        // keys - a row's whole value is replaced by what it reads now, so
        // editing a row is renaming, and clearing it is removing.
        $features = array_values(array_filter(is_array($_POST['features'] ?? null) ? $_POST['features'] : [], 'is_string'));
        $custom = array_values(array_filter(array_map(
            fn ($v) => trim((string) $v),
            is_array($_POST['custom_features'] ?? null) ? $_POST['custom_features'] : []
        ), fn (string $v) => $v !== ''));
        $features = array_values(array_unique([...$features, ...$custom]));
        // A blank field leaves that type out - offerLimit() then reads it as
        // no limit, not as 0; only a typed number restricts it.
        $offerLimits = [];
        foreach (array_keys($app->offers->types()) as $typeId) {
            $raw = trim((string) ($_POST['offer_limits'][$typeId] ?? ''));
            if ($raw !== '') {
                $offerLimits[$typeId] = max(0, (int) $raw);
            }
        }
        $rawImages = trim((string) ($_POST['max_images_per_offer'] ?? ''));
        $maxImagesPerOffer = $rawImages !== '' ? max(0, (int) $rawImages) : null;
        $perSeat = isset($_POST['per_seat']);
        $minQuantity = max(1, (int) ($_POST['min_quantity'] ?? 1));
        $appId = $this->postedAppId();

        try {
            if ($price === null || $plans->plan($id) === null) {
                throw new InvalidArgumentException('plan: price');
            }
            $plans->updatePlan(
                $id,
                (string) ($_POST['name'] ?? ''),
                $appId,
                $price,
                strtoupper(trim((string) ($_POST['currency'] ?? ''))),
                (int) ($_POST['period_months'] ?? 0),
                $features,
                isset($_POST['active']),
                $offerLimits,
                $maxImagesPerOffer,
                $perSeat,
                $minQuantity
            );
            $app->adminLog->record($app->auth->account()['id'], 'abos_subscription_plan_change', $id, (string) $id);
            Session::flash('success', $this->trans('abos.admin.flash.plan_saved'));
        } catch (InvalidArgumentException) {
            Session::flash('error', $this->trans('abos.admin.error.plan'));
        }

        $this->redirect('/admin/subscriptions/plans/' . $id);
    }

    public function deletePlan(array $params): void
    {
        $app = $this->app;
        $id = (int) $params['id'];
        try {
            Services::plans($app)->deletePlan($id);
            $app->adminLog->record($app->auth->account()['id'], 'abos_subscription_plan_delete', $id, (string) $id);
            Session::flash('success', $this->trans('abos.admin.flash.plan_deleted'));
            $this->redirect('/admin/subscriptions');
        } catch (InvalidArgumentException) {
            Session::flash('error', $this->trans('abos.admin.error.plan_in_use'));
            $this->redirect('/admin/subscriptions/plans/' . $id);
        }
    }

    /** The operator's bank account, to which the transfers for subscriptions are paid. */
    public function saveBank(array $params): void
    {
        $app = $this->app;
        try {
            Services::billing($app)->saveBank(
                (string) ($_POST['holder'] ?? ''),
                (string) ($_POST['iban'] ?? ''),
                (string) ($_POST['bic'] ?? '')
            );
            $app->adminLog->record($app->auth->account()['id'], 'abos_bank', null, '');
            Session::flash('success', $this->trans('abos.admin.flash.bank_saved'));
        } catch (InvalidArgumentException) {
            Session::flash('error', $this->trans('abos.admin.error.bank'));
        }

        $this->redirect('/admin/subscriptions');
    }

    /** The seller's details that go on every invoice for a subscription, and the tax rate. */
    public function saveIssuer(array $params): void
    {
        $app = $this->app;
        try {
            Services::invoices($app)->saveIssuer($_POST);
            $app->adminLog->record($app->auth->account()['id'], 'abos_issuer', null, '');
            Session::flash('success', $this->trans('abos.admin.flash.issuer_saved'));
        } catch (InvalidArgumentException) {
            Session::flash('error', $this->trans('abos.admin.error.issuer'));
        }

        $this->redirect('/admin/subscriptions');
    }

    /** An invoice as the buyer has it, for the administration: support can see what was sent. */
    public function invoice(array $params): void
    {
        $invoice = Services::invoices($this->app)->find((int) $params['id']);
        if ($invoice === null) {
            http_response_code(404);
            $this->render('error.twig', ['status' => 404, 'message_key' => 'core.error.not_found']);
            return;
        }

        $this->render('@abos/subscriptions/invoice.twig', ['invoice' => $invoice, 'back' => '/admin/subscriptions']);
    }

    /** The money of a transfer has arrived: the plan is given to the account from now. */
    public function confirmTransfer(array $params): void
    {
        $app = $this->app;
        $id = (int) $params['id'];
        try {
            Services::billing($app)->confirmTransfer($id);
            $app->adminLog->record($app->auth->account()['id'], 'abos_subscription_transfer', $id, (string) $id);
            Session::flash('success', $this->trans('abos.admin.flash.transfer_confirmed'));
        } catch (InvalidArgumentException) {
            Session::flash('error', $this->trans('abos.admin.error.transfer'));
        }

        $this->redirect('/admin/subscriptions');
    }

    /** Gives an account a plan, or takes its plan away. The end date is the last day, included. */
    public function assign(array $params): void
    {
        $app = $this->app;
        $account = $app->accounts->findByEmail(trim((string) ($_POST['email'] ?? '')));
        $until = trim((string) ($_POST['until'] ?? ''));
        $planId = ($_POST['plan'] ?? '') === '' ? null : (int) $_POST['plan'];

        if ($account === null) {
            Session::flash('error', $this->trans('abos.admin.error.account'));
        } elseif ($until !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $until) !== 1) {
            Session::flash('error', $this->trans('abos.admin.error.until'));
        } else {
            try {
                Services::subscriptions($app)->assign((int) $account['id'], $planId, $until === '' ? null : $until . ' 23:59:59');
                $app->adminLog->record($app->auth->account()['id'], 'abos_subscription_assign', (int) $account['id'], $planId === null ? 'none' : (string) $planId);
                Session::flash('success', $this->trans('abos.admin.flash.assigned', ['email' => $account['email']]));
            } catch (InvalidArgumentException) {
                Session::flash('error', $this->trans('abos.admin.error.plan'));
            }
        }

        $this->redirect($this->safeReturn('/admin/subscriptions'));
    }

    /** @return array<string, string> app-extension id => name, every discovered "app"-type manifest, sorted - the picker a plan is scoped from. */
    private function appChoices(): array
    {
        $choices = [];
        foreach ($this->app->extensions->discover() as $id => $manifest) {
            if ($manifest->type === 'app') {
                $choices[$id] = $manifest->name;
            }
        }

        return $choices;
    }

    /** The app a plan form posted, kept only if it names a real "app"-type extension - same convention as FAQ's extension picker. */
    private function postedAppId(): ?string
    {
        $appId = (string) ($_POST['app_id'] ?? '');

        return $appId !== '' && isset($this->appChoices()[$appId]) ? $appId : null;
    }
}
