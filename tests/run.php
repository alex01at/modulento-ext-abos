<?php

declare(strict_types=1);

// Plain-PHP checks of the Abos extension's own classes, on SQLite in memory:
//   MODULENTO_CORE=/path/to/modulento php tests/run.php
// The core is only needed for its classes (vendor/autoload.php); without
// the variable it is expected next to this repository, in ../modulento.
// Exit code 0 means every check passed.

use Modulento\Abos\AdminController;
use Modulento\Abos\Billing;
use Modulento\Abos\Extension;
use Modulento\Abos\Invoices;
use Modulento\Abos\Plans;
use Modulento\Abos\Provider;
use Modulento\Abos\Services;
use Modulento\Abos\SubscriptionController;
use Modulento\Abos\Subscriptions;
use Modulento\Core\App;
use Modulento\Core\Payment\Payments;
use Modulento\Core\Subscription\SubscriptionProvider;
use Modulento\Core\Support\Auth;
use Modulento\Core\Support\Clock;
use Modulento\Core\Support\Settings;

$here = dirname(__DIR__);
$core = getenv('MODULENTO_CORE') ?: dirname($here) . '/modulento';

if (!is_file($core . '/vendor/autoload.php')) {
    fwrite(STDERR, "No Modulento core with vendor/ found at {$core}.\n"
        . "Set MODULENTO_CORE to a working copy of the core in which \"composer install\" has run.\n");
    exit(1);
}

require $core . '/vendor/autoload.php';

date_default_timezone_set('UTC');

$failed = [];
$checks = 0;

function check(string $label, bool $condition): void
{
    global $failed, $checks;
    $checks++;
    if (!$condition) {
        $failed[] = $label;
    }
}

// --- Database --------------------------------------------------------------
$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA foreign_keys = ON');

$pdo->exec('CREATE TABLE setting (name TEXT PRIMARY KEY, value TEXT NOT NULL)');
$pdo->exec("CREATE TABLE extension (id TEXT PRIMARY KEY, version TEXT, enabled INTEGER, enabled_at TEXT)");
$pdo->exec("INSERT INTO extension VALUES ('abos', '0', 1, '2026-01-01 00:00:00')");
$pdo->exec("CREATE TABLE account (id INTEGER PRIMARY KEY, email TEXT UNIQUE, display_name TEXT, password_hash TEXT, status TEXT DEFAULT 'active',
    email_verified_at TEXT, terms_accepted_at TEXT, identity_status TEXT, locale TEXT DEFAULT 'de', created_at TEXT, last_login_at TEXT)");
$pdo->exec('CREATE TABLE provider (id INTEGER PRIMARY KEY, account_id INTEGER REFERENCES account (id) ON DELETE CASCADE, slug TEXT, status TEXT,
    decided_at TEXT, details_changed_at TEXT)');
$pdo->exec('CREATE TABLE provider_translation (provider_id INTEGER REFERENCES provider (id) ON DELETE CASCADE, locale TEXT, headline TEXT, description TEXT)');
$pdo->exec('CREATE TABLE page (id INTEGER PRIMARY KEY, status TEXT, role TEXT, in_header INTEGER, in_footer INTEGER, position INTEGER)');
$pdo->exec('CREATE TABLE page_translation (page_id INTEGER, locale TEXT, title TEXT, slug TEXT, meta_description TEXT, body TEXT)');
$pdo->exec('CREATE TABLE account_preference (account_id INTEGER, name TEXT, value TEXT, PRIMARY KEY (account_id, name))');
$pdo->exec('CREATE TABLE role (id INTEGER PRIMARY KEY, name TEXT)');
$pdo->exec('CREATE TABLE role_permission (role_id INTEGER REFERENCES role (id) ON DELETE CASCADE, permission TEXT)');
$pdo->exec('CREATE TABLE account_role (account_id INTEGER REFERENCES account (id) ON DELETE CASCADE, role_id INTEGER REFERENCES role (id) ON DELETE CASCADE)');
$pdo->exec("CREATE TABLE admin_log (id INTEGER PRIMARY KEY, created_at TEXT, actor_id INTEGER REFERENCES account (id) ON DELETE SET NULL, action TEXT, target_id INTEGER REFERENCES account (id) ON DELETE SET NULL, detail TEXT NOT NULL DEFAULT '')");

$pdo->exec('CREATE TABLE x_subscription_plan (id INTEGER PRIMARY KEY, slug TEXT UNIQUE, name TEXT NOT NULL, app_id TEXT NULL,
    price_cents INTEGER NOT NULL, currency TEXT NOT NULL, period_months INTEGER NOT NULL, features TEXT NOT NULL DEFAULT \'\',
    offer_limits TEXT NOT NULL DEFAULT \'{}\', max_images_per_offer INTEGER NULL, per_seat INTEGER NOT NULL DEFAULT 0,
    min_quantity INTEGER NOT NULL DEFAULT 1, active INTEGER NOT NULL DEFAULT 1)');
$pdo->exec("CREATE TABLE x_subscription (id INTEGER PRIMARY KEY, account_id INTEGER REFERENCES account (id) ON DELETE CASCADE,
    plan_id INTEGER REFERENCES x_subscription_plan (id), status TEXT NOT NULL, period_end TEXT NULL, provider_ref TEXT NULL,
    quantity INTEGER NOT NULL DEFAULT 1, stripe_item_ref TEXT NULL, reminded_for TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)");
$pdo->exec("CREATE TABLE x_subscription_order (id INTEGER PRIMARY KEY, account_id INTEGER REFERENCES account (id) ON DELETE CASCADE,
    plan_id INTEGER REFERENCES x_subscription_plan (id), quantity INTEGER NOT NULL DEFAULT 1, method TEXT NOT NULL, status TEXT NOT NULL,
    reference TEXT UNIQUE NOT NULL, amount_cents INTEGER NOT NULL, currency TEXT NOT NULL, stripe_session TEXT NULL,
    created_at TEXT NOT NULL, paid_at TEXT NULL)");
$pdo->exec('CREATE TABLE x_subscription_billing_address (account_id INTEGER PRIMARY KEY REFERENCES account (id) ON DELETE CASCADE,
    name TEXT NOT NULL, street TEXT NOT NULL, postal_code TEXT NOT NULL, city TEXT NOT NULL, country TEXT NOT NULL, updated_at TEXT NOT NULL)');
$pdo->exec("CREATE TABLE x_subscription_invoice (id INTEGER PRIMARY KEY, number TEXT UNIQUE NOT NULL, year INTEGER NOT NULL, seq INTEGER NOT NULL,
    source_ref TEXT UNIQUE NOT NULL, account_id INTEGER NULL REFERENCES account (id) ON DELETE SET NULL, plan_name TEXT NOT NULL,
    months INTEGER NOT NULL, currency TEXT NOT NULL, net_cents INTEGER NOT NULL, tax_rate INTEGER NOT NULL, tax_cents INTEGER NOT NULL,
    gross_cents INTEGER NOT NULL, buyer_name TEXT NOT NULL, buyer_street TEXT NOT NULL, buyer_postal_code TEXT NOT NULL,
    buyer_city TEXT NOT NULL, buyer_country TEXT NOT NULL, issuer TEXT NOT NULL, issued_at TEXT NOT NULL,
    UNIQUE (year, seq))");

// The migration and the schema above must name the same columns.
foreach ([
    'x_subscription_plan' => '001_abos.sql', 'x_subscription' => '001_abos.sql', 'x_subscription_order' => '001_abos.sql',
    'x_subscription_billing_address' => '001_abos.sql', 'x_subscription_invoice' => '001_abos.sql',
] as $table => $file) {
    $migration = (string) file_get_contents($here . '/migrations/' . $file);
    preg_match('/CREATE TABLE ' . $table . ' \((.*?)\n\) ENGINE/s', $migration, $body);
    preg_match_all('/^\s{4}([a-z_]+) [A-Z]/m', $body[1] ?? '', $columns);
    $inTest = array_column($pdo->query("PRAGMA table_info({$table})")->fetchAll(), 'name');
    sort($columns[1]);
    sort($inTest);
    check("schema: {$table} has the same columns in the migration and in the tests", $columns[1] !== [] && $columns[1] === $inTest);
}

// --- The extension folder, symlinked next to two bare "app" stand-ins ------
$uploads = sys_get_temp_dir() . '/modulento-abos-test-' . bin2hex(random_bytes(4));
mkdir($uploads . '/extensions', 0777, true);
symlink($here, $uploads . '/extensions/abos');

// Two minimal "app"-type extensions, so the active one can be resolved
// through $app->extensions->loaded() exactly as the real freelancer/shop/
// auction/card extensions would be - the single most important new
// behaviour of this addon (a plan's app_id scoping) can only be proven
// against a real "app" manifest, not a made-up string.
foreach (['shop', 'freelancer'] as $appId) {
    $dir = $uploads . '/extensions/' . $appId;
    mkdir($dir . '/src', 0777, true);
    file_put_contents($dir . '/extension.json', json_encode([
        'id' => $appId, 'name' => ucfirst($appId), 'version' => '0.1.0', 'api' => 1, 'type' => 'app',
        'namespace' => 'Modulento\\Test' . ucfirst($appId),
    ]));
    file_put_contents($dir . '/src/Extension.php', '<?php
declare(strict_types=1);
namespace Modulento\\Test' . ucfirst($appId) . ';
final class Extension implements \\Modulento\\Core\\Extension\\Extension {
    public function register(\\Modulento\\Core\\Extension\\Registrar $registrar): void {}
}
');
}

$baseConfig = [
    'app' => [
        'env' => 'dev', 'url' => 'https://abos.example', 'name' => 'Testseite', 'root' => $core,
        'extensions' => $uploads . '/extensions', 'uploads' => $uploads, 'secret_key' => $uploads . '/secret.key',
    ],
    'update' => ['token' => ''],
    'packages' => ['sources' => []],
];

/**
 * A fresh App with exactly the given extensions enabled (besides abos,
 * always on) - the active "app" is resolved lazily from $app->extensions
 * ->loaded(), which is only populated once loadEnabled() runs, so a
 * different active app means a different App instance, same as a
 * different real HTTP request would see.
 *
 * @param string[] $enabledAppIds
 */
function buildApp(array $enabledAppIds, PDO $pdo, array $baseConfig, string $core): App
{
    $pdo->exec("UPDATE extension SET enabled = 0 WHERE id <> 'abos'");
    foreach ($enabledAppIds as $id) {
        $pdo->prepare("INSERT INTO extension (id, version, enabled, enabled_at) VALUES (:id, '0', 1, '2026-01-01 00:00:00')
            ON CONFLICT(id) DO UPDATE SET enabled = 1")->execute(['id' => $id]);
    }

    $app = new App($baseConfig, $pdo, 'de');
    $app->translator->load($core . '/core/lang', 'core');
    $app->extensions->loadEnabled($app);

    return $app;
}

$app = buildApp([], $pdo, $baseConfig, $core);
check('extension: the core loads it as an addon', isset($app->extensions->loaded()['abos']) && $app->extensions->loaded()['abos']->type === 'addon');
check('extension: permission, admin menu entry and account link are announced', ($app->permissions()['abos.subscriptions.manage'] ?? null) === 'abos.permission.subscriptions_manage'
    && array_filter($app->adminMenu(), fn (array $e) => $e['path'] === '/admin/subscriptions' && $e['permission'] === 'abos.subscriptions.manage') !== []
    && array_filter($app->accountLinks(), fn (array $e) => $e['path'] === '/account/subscription') !== []);
check('extension: the core\'s Subscriptions facade now has a provider', $app->subscriptions->hasProvider());

// --- Plans: CRUD -------------------------------------------------------------
$plans = Services::plans($app);

$generalId = $plans->createPlan('starter', 'Starter', null, 990, 'EUR', 1, ['abos.feature.x']);
check('Plans::createPlan(): stores a general plan (app_id null)', $plans->plan($generalId)['app_id'] === null && $plans->plan($generalId)['name'] === 'Starter');

try {
    $plans->createPlan('starter', 'Zweiter', null, 500, 'EUR', 1, []);
    check('Plans::createPlan(): a taken slug is refused', false);
} catch (InvalidArgumentException) {
    check('Plans::createPlan(): a taken slug is refused', true);
}

$shopId = $plans->createPlan('shop-pro', 'Shop Pro', 'shop', 1990, 'EUR', 1, ['shop.feature.x']);
$freelancerId = $plans->createPlan('freelancer-pro', 'Freelancer Pro', 'freelancer', 1990, 'EUR', 1, ['freelancer.feature.x']);
check('Plans::createPlan(): plans can be scoped to an app', $plans->plan($shopId)['app_id'] === 'shop' && $plans->plan($freelancerId)['app_id'] === 'freelancer');

$plans->updatePlan($generalId, 'Starter Plus', null, 1200, 'EUR', 1, ['abos.feature.x', 'abos.feature.y'], true, ['freelancer.service' => 3], 5, false, 1);
check('Plans::updatePlan(): changes stick', $plans->plan($generalId)['name'] === 'Starter Plus' && $plans->plan($generalId)['price_cents'] === 1200
    && $plans->plan($generalId)['offer_limits'] === ['freelancer.service' => 3] && $plans->plan($generalId)['max_images_per_offer'] === 5);

check('Plans::all(): unscoped, lists every plan regardless of app', count($plans->all()) === 3);

// --- Plans: app-scoped visibility - the single most important new behaviour ----
$appShop = buildApp(['shop'], $pdo, $baseConfig, $core);
$shopPlans = Services::plans($appShop);
check('app scoping: with "shop" active, the shop-scoped plan and the general one are visible, not the freelancer one',
    in_array($shopId, array_column($shopPlans->activePlans(), 'id'), true)
    && in_array($generalId, array_column($shopPlans->activePlans(), 'id'), true)
    && !in_array($freelancerId, array_column($shopPlans->activePlans(), 'id'), true));

$appFreelancer = buildApp(['freelancer'], $pdo, $baseConfig, $core);
$freelancerPlans = Services::plans($appFreelancer);
check('app scoping: with "freelancer" active, the freelancer-scoped plan and the general one are visible, not the shop one',
    in_array($freelancerId, array_column($freelancerPlans->activePlans(), 'id'), true)
    && in_array($generalId, array_column($freelancerPlans->activePlans(), 'id'), true)
    && !in_array($shopId, array_column($freelancerPlans->activePlans(), 'id'), true));

$appNone = buildApp([], $pdo, $baseConfig, $core);
$nonePlans = Services::plans($appNone);
check('app scoping: with no app active, only the general plan is visible',
    array_column($nonePlans->activePlans(), 'id') === [$generalId]);

check('app scoping: plans() (every plan, active or not) is scoped the same way as activePlans()',
    in_array($shopId, array_column($shopPlans->plans(), 'id'), true) && !in_array($freelancerId, array_column($shopPlans->plans(), 'id'), true));

check('Plans::resolveActiveAppId(): finds the one loaded "app"-type manifest', Plans::resolveActiveAppId($appShop) === 'shop' && Plans::resolveActiveAppId($appNone) === null);

// deletePlan() - refused once a plan has a subscription, allowed otherwise
try {
    $plans->deletePlan($shopId);
    check('Plans::deletePlan(): a free-standing plan can be deleted', $plans->plan($shopId) === null);
} catch (InvalidArgumentException) {
    check('Plans::deletePlan(): a free-standing plan can be deleted', false);
}
$shopId = $plans->createPlan('shop-pro-2', 'Shop Pro', 'shop', 1990, 'EUR', 1, ['shop.feature.x']);

// --- Subscriptions: assign/current/updateQuantity/extend/byProviderRef ------
$subscriptions = Services::subscriptions($app, $plans);
$accounts = $app->accounts;
$pw = password_hash('secret12', PASSWORD_DEFAULT);
$aliceId = $accounts->create('alice@example.test', 'secret12', 'de', true);
$bobId = $accounts->create('bob@example.test', 'secret12', 'de', true);

check('Subscriptions::current(): null without a subscription', $subscriptions->current($aliceId) === null);

$subscriptions->assign($aliceId, $generalId, Subscriptions::addMonths(Clock::now(), 1));
$current = $subscriptions->current($aliceId);
check('Subscriptions::assign()/current(): the account now has the plan, with its features', $current !== null && $current['name'] === 'Starter Plus'
    && $current['features'] === ['abos.feature.x', 'abos.feature.y'] && $current['status'] === 'active');

try {
    $subscriptions->assign($bobId, 999999, null);
    check('Subscriptions::assign(): an unknown plan is refused', false);
} catch (InvalidArgumentException) {
    check('Subscriptions::assign(): an unknown plan is refused', true);
}

$subscriptions->updateQuantity($current['id'], 7);
check('Subscriptions::updateQuantity(): the live seat count changes', $subscriptions->current($aliceId)['quantity'] === 7);

$subscriptions->assign($aliceId, $shopId, null, 'sub_stripe_123', 2);
$current = $subscriptions->current($aliceId);
check('Subscriptions::assign(): a new plan cancels the one it replaces, open-ended works, provider_ref/quantity are kept', $current['name'] === 'Shop Pro'
    && $current['period_end'] === null && $current['provider_ref'] === 'sub_stripe_123' && $current['quantity'] === 2);

$subscriptions->setStripeItemRefByProviderRef('sub_stripe_123', 'si_abc');
check('Subscriptions::setStripeItemRefByProviderRef(): captured for later quantity syncs', $subscriptions->current($aliceId)['stripe_item_ref'] === 'si_abc');

$byRef = $subscriptions->byProviderRef('sub_stripe_123');
check('Subscriptions::byProviderRef(): finds the subscription by its Stripe id, with its plan\'s months', $byRef !== null && $byRef['account_id'] === $aliceId && $byRef['months'] === 1);

$subscriptions->extend($byRef['id'], 1);
check('Subscriptions::extend(): runs the subscription on from now for the plan\'s months', $subscriptions->current($aliceId)['period_end'] !== null && $subscriptions->current($aliceId)['status'] === 'active');

$subscriptions->setStatusByProviderRef('sub_stripe_123', 'past_due');
check('Subscriptions::setStatusByProviderRef(): past_due still counts as current()', $subscriptions->current($aliceId) !== null && $subscriptions->current($aliceId)['status'] === 'past_due');
$subscriptions->setStatusByProviderRef('sub_stripe_123', 'canceled');
check('Subscriptions::setStatusByProviderRef(): canceled no longer counts', $subscriptions->current($aliceId) === null);

check('Subscriptions::accountIdsWithFeature(): empty once cancelled, populated for an active one', $subscriptions->accountIdsWithFeature('shop.feature.x') === []);
$subscriptions->assign($bobId, $shopId, null);
check('Subscriptions::accountIdsWithFeature(): lists accounts whose active plan grants the feature', $subscriptions->accountIdsWithFeature('shop.feature.x') === [$bobId]);

// --- Provider: the SubscriptionProvider implementation, exercised directly ----
$provider = new Provider($plans, $subscriptions, Services::billing($app));
check('Provider implements the core\'s contract', $provider instanceof SubscriptionProvider);
check('Provider::allows()/grants(): true for a feature the current plan lists', $provider->allows($bobId, 'shop.feature.x') && $provider->grants($bobId, 'shop.feature.x'));
check('Provider::allows(): false for a feature nobody\'s plan lists', !$provider->allows($bobId, 'nonsense'));
check('Provider::offerLimit()/imageLimit(): 0 without an active plan, the plan\'s own numbers with one', $provider->offerLimit($aliceId, 'freelancer.service') === 0
    && $provider->imageLimit($aliceId) === 0);
$subscriptions->assign($aliceId, $generalId, null);
check('Provider::offerLimit()/imageLimit(): read through to the plan', $provider->offerLimit($aliceId, 'freelancer.service') === 3 && $provider->imageLimit($aliceId) === 5);
check('Provider::current(): same shape Subscriptions::current() returns', $provider->current($aliceId)['name'] === 'Starter Plus');

// --- End to end, through the core's own facade (the registered Provider) ------
// Proves the SAME wiring core/tests/run.php proves against its own
// TestSubscriptionProvider stub, here from this addon's own side: a real
// App, loadEnabled(), and $app->subscriptions - not this addon's own
// classes directly.
check('end to end: $app->subscriptions->allows()/offerLimit()/imageLimit()/current() answer through the registered Provider',
    $app->subscriptions->allows($aliceId, 'abos.feature.x') && $app->subscriptions->offerLimit($aliceId, 'freelancer.service') === 3
    && $app->subscriptions->imageLimit($aliceId) === 5 && $app->subscriptions->current($aliceId)['name'] === 'Starter Plus');
check('end to end: $app->subscriptions->activePlans()/plans() go through Plans, app-scoped for the active app of THIS app instance',
    $app->subscriptions->activePlans() !== [] && $app->subscriptions->plans() !== []);

// --- setQuantity(): the contract card's Organizations::syncSeats() relies on ---
$noSub = $accounts->create('nosub@example.test', 'secret12', 'de', true);
try {
    $app->subscriptions->setQuantity($noSub, 3);
    check('setQuantity(): throws InvalidArgumentException with no active subscription', false);
} catch (InvalidArgumentException) {
    check('setQuantity(): throws InvalidArgumentException with no active subscription', true);
}
$app->subscriptions->setQuantity($aliceId, 1); // Starter Plus is not per_seat, min_quantity 1: clamps, no crash.
check('setQuantity(): on a flat (non per-seat) plan, clamps to its min_quantity without error', $subscriptions->current($aliceId)['quantity'] === 1);

// Make the shop plan per-seat with a floor of 3, to test clamping for real.
$plans->updatePlan($shopId, 'Shop Pro', 'shop', 1990, 'EUR', 1, ['shop.feature.x'], true, [], null, true, 3);
$subscriptions->assign($bobId, $shopId, null);
$app->subscriptions->setQuantity($bobId, 1);
check('setQuantity(): clamped up to the plan\'s min_quantity', $subscriptions->current($bobId)['quantity'] === 3);
$app->subscriptions->setQuantity($bobId, 8);
check('setQuantity(): a higher count is accepted as given', $subscriptions->current($bobId)['quantity'] === 8);

// --- Invoices: issuer, billing address, numbering (gapless per year, idempotent) ---
$invoices = Services::invoices($app);
check('Invoices::issuer(): none set yet', $invoices->issuer() === null);
try {
    $invoices->saveIssuer(['name' => '', 'street' => '', 'postal_code' => '', 'city' => '', 'country' => '', 'tax_rate' => -1]);
    check('Invoices::saveIssuer(): incomplete details are refused', false);
} catch (InvalidArgumentException) {
    check('Invoices::saveIssuer(): incomplete details are refused', true);
}
$invoices->saveIssuer(['name' => 'Beispiel GmbH', 'street' => 'Hauptstr. 1', 'postal_code' => '10115', 'city' => 'Berlin', 'country' => 'de', 'vat_id' => 'DE123456789', 'tax_rate' => 19, 'note' => '']);
check('Invoices::saveIssuer()/issuer(): stored and read back, country upper-cased', $invoices->issuer()['country'] === 'DE' && $invoices->issuer()['name'] === 'Beispiel GmbH');

check('Invoices::billingAddress(): none yet', $invoices->billingAddress($aliceId) === null);
$saved = $invoices->saveBillingAddress($aliceId, ['name' => 'Alice Beispiel', 'street' => 'Nebenweg 2', 'postal_code' => '20095', 'city' => 'Hamburg', 'country' => 'de']);
check('Invoices::saveBillingAddress(): stored, country upper-cased', $saved['country'] === 'DE' && $invoices->billingAddress($aliceId)['city'] === 'Hamburg');
$invoices->saveBillingAddress($aliceId, ['name' => 'Alice B.', 'street' => 'Nebenweg 2', 'postal_code' => '20095', 'city' => 'Hamburg', 'country' => 'de']);
check('Invoices::saveBillingAddress(): a second save updates in place, not a second row', $invoices->billingAddress($aliceId)['name'] === 'Alice B.'
    && (int) $pdo->query("SELECT COUNT(*) FROM x_subscription_billing_address WHERE account_id = {$aliceId}")->fetchColumn() === 1);

$year = (int) gmdate('Y');
$inv1 = $invoices->issue($aliceId, 'order:TEST-1', 'Starter Plus', 1, 1190, 'EUR');
check('Invoices::issue(): numbered RE-{year}-{seq:06d}, net/tax split from the 19% rate, gross unchanged', $inv1['number'] === sprintf('RE-%d-%06d', $year, 1)
    && $inv1['gross_cents'] === 1190 && $inv1['tax_rate'] === 19 && $inv1['net_cents'] + $inv1['tax_cents'] === 1190);
$inv2 = $invoices->issue($aliceId, 'order:TEST-2', 'Starter Plus', 1, 1190, 'EUR');
check('Invoices::issue(): numbers run without gaps, per year', $inv2['number'] === sprintf('RE-%d-%06d', $year, 2));
$inv1Again = $invoices->issue($aliceId, 'order:TEST-1', 'Starter Plus', 1, 1190, 'EUR');
check('Invoices::issue(): idempotent per source_ref - the same source never gets a second number', $inv1Again['id'] === $inv1['id'] && $inv1Again['number'] === $inv1['number']
    && (int) $pdo->query("SELECT COUNT(*) FROM x_subscription_invoice WHERE source_ref = 'order:TEST-1'")->fetchColumn() === 1);

try {
    $invoices->issue(null, 'order:no-address', 'Starter Plus', 1, 1000, 'EUR');
    check('Invoices::issue(): a missing buyer address is refused', false);
} catch (InvalidArgumentException) {
    check('Invoices::issue(): a missing buyer address is refused', true);
}

check('Invoices::ofAccount(): both of the account\'s invoices, newest first', array_column($invoices->ofAccount($aliceId), 'id') === [$inv2['id'], $inv1['id']]);
check('Invoices::find(): scoped to an account when one is given', $invoices->find($inv1['id'], $bobId) === null && $invoices->find($inv1['id'], $aliceId) !== null);
check('Invoices::recent(): the newest invoices across every account', count($invoices->recent(50)) === 2);

// --- Billing: methods(), bank account, orders, confirmTransfer, cancelOwn, reminders ---
$billing = Services::billing($app);
check('Billing::methods(): neither way to pay before a bank account or Stripe config exists', $billing->methods() === ['transfer' => false, 'stripe' => false]);

try {
    $billing->saveBank('Firma', 'not-an-iban', '');
    check('Billing::saveBank(): an invalid IBAN is refused', false);
} catch (InvalidArgumentException) {
    check('Billing::saveBank(): an invalid IBAN is refused', true);
}
$billing->saveBank('Beispiel GmbH', 'DE89 3704 0044 0532 0130 00', 'COBADEFFXXX');
check('Billing::saveBank()/bank(): stored, IBAN normalised', $billing->bank()['iban'] === 'DE89370400440532013000');

// Transfer is still off: Payments::isEnabled() defaults a payment method
// off until the administration switches it on explicitly.
check('Billing::methods(): still off, the "transfer" payment method itself is not switched on yet', $billing->methods()['transfer'] === false);
$app->payments->setEnabled(Payments::TRANSFER, true);
check('Billing::methods(): on once bank + issuer + the payment method are all there', Services::billing($app)->methods()['transfer'] === true);

$carol = $accounts->create('carol@example.test', 'secret12', 'de', true);
try {
    $billing->startTransfer($carol, $generalId, 1);
    check('Billing::startTransfer(): refused without a billing address', false);
} catch (InvalidArgumentException) {
    check('Billing::startTransfer(): refused without a billing address', true);
}
$invoices->saveBillingAddress($carol, ['name' => 'Carol', 'street' => 'Weg 3', 'postal_code' => '80331', 'city' => 'München', 'country' => 'de']);
$order = $billing->startTransfer($carol, $generalId, 1);
check('Billing::startTransfer(): a pending order with the plan\'s amount and an ABO- reference', $order['status'] === 'pending' && $order['amount_cents'] === 1200
    && str_starts_with($order['reference'], 'ABO-'));
check('Billing::orderOf(): finds the account\'s own order, not another\'s', $billing->orderOf($carol, $order['id']) !== null && $billing->orderOf($aliceId, $order['id']) === null);
check('Billing::pendingTransfers(): lists it', in_array($order['id'], array_column($billing->pendingTransfers(), 'id'), true));

$mailed = [];
$billingWithMail = new Billing($pdo, new Settings($pdo), $plans, $subscriptions, $app->payments, $invoices,
    function (string $to, string $template, array $data, string $locale) use (&$mailed): bool {
        $mailed[] = ['to' => $to, 'template' => $template, 'data' => $data];
        return true;
    },
    fn (string $path): string => $app->url($path, null, true),
);
$billingWithMail->confirmTransfer($order['id']);
check('Billing::confirmTransfer(): the account now has the plan, an invoice was issued and mailed', $subscriptions->current($carol) !== null && $subscriptions->current($carol)['name'] === 'Starter Plus'
    && count($mailed) === 1 && $mailed[0]['template'] === '@abos/emails/subscription_invoice.txt.twig');
try {
    $billingWithMail->confirmTransfer($order['id']);
    check('Billing::confirmTransfer(): a second confirmation of the same order is refused', false);
} catch (InvalidArgumentException) {
    check('Billing::confirmTransfer(): a second confirmation of the same order is refused', true);
}

check('Billing::cancelOwn(): false without a Stripe-backed subscription', $billing->cancelOwn($carol) === false);

// --- Billing::stripeEvent(): the four event types ----------------------------
$dave = $accounts->create('dave@example.test', 'secret12', 'de', true);
$invoices->saveBillingAddress($dave, ['name' => 'Dave', 'street' => 'Allee 4', 'postal_code' => '50667', 'city' => 'Köln', 'country' => 'de']);
$daveOrder = $billing->startTransfer($dave, $generalId, 1); // method is "transfer" here only to obtain a pending reference; the event below is Stripe's own notification.
$pdo->prepare("UPDATE x_subscription_order SET method = 'stripe' WHERE id = :id")->execute(['id' => $daveOrder['id']]);

$billingWithMail->stripeEvent([
    'type' => 'checkout.session.completed',
    'data' => ['object' => [
        'mode' => 'subscription', 'client_reference_id' => $daveOrder['reference'], 'payment_status' => 'paid',
        'amount_total' => $daveOrder['amount_cents'], 'currency' => strtolower($daveOrder['currency']), 'subscription' => 'sub_dave_1',
    ]],
]);
check('stripeEvent(checkout.session.completed): pays the order and assigns the plan with the Stripe subscription id', $subscriptions->current($dave) !== null
    && $subscriptions->current($dave)['provider_ref'] === 'sub_dave_1');

$billingWithMail->stripeEvent(['type' => 'invoice.payment_failed', 'data' => ['object' => ['subscription' => 'sub_dave_1']]]);
check('stripeEvent(invoice.payment_failed): the subscription becomes past_due, still counts as current', $subscriptions->current($dave) !== null
    && $subscriptions->current($dave)['status'] === 'past_due');

$beforeExtend = $subscriptions->byProviderRef('sub_dave_1');
$billingWithMail->stripeEvent([
    'type' => 'invoice.paid',
    'data' => ['object' => ['billing_reason' => 'subscription_cycle', 'subscription' => 'sub_dave_1', 'amount_paid' => 1200, 'currency' => 'eur', 'id' => 'in_dave_1']],
]);
check('stripeEvent(invoice.paid): a renewal re-activates the subscription and runs it on', $subscriptions->current($dave) !== null && $subscriptions->current($dave)['status'] === 'active'
    && $subscriptions->current($dave)['period_end'] > ($beforeExtend['period_end'] ?? ''));
check('stripeEvent(invoice.paid): the renewal gets its own invoice', $invoices->find($invoices->ofAccount($dave)[0]['id']) !== null && count($invoices->ofAccount($dave)) >= 1);

$billingWithMail->stripeEvent(['type' => 'customer.subscription.deleted', 'data' => ['object' => ['id' => 'sub_dave_1']]]);
check('stripeEvent(customer.subscription.deleted): the subscription is cancelled, no longer current', $subscriptions->current($dave) === null);

// --- sendReminders(): one reminder per period, only for transfer subscriptions ---
$erin = $accounts->create('erin@example.test', 'secret12', 'de', true);
$subscriptions->assign($erin, $generalId, Clock::now(5 * 86400)); // ends in 5 days: inside the 7-day window
$sent = $billingWithMail->sendReminders();
check('Billing::sendReminders(): mails the reminder once', $sent === 1 && end($mailed)['template'] === '@abos/emails/subscription_ending.txt.twig' && end($mailed)['to'] === 'erin@example.test');
$sentAgain = $billingWithMail->sendReminders();
check('Billing::sendReminders(): not sent twice for the same period', $sentAgain === 0);

// --- AdminController: app picker only lists "app"-type extensions, never addons ---
$_SESSION = [];
$pwHash = $pdo->query("SELECT password_hash FROM account WHERE id = {$aliceId}")->fetchColumn();
$_SESSION['account_id'] = $aliceId;
$_SESSION['auth_stamp'] = Auth::stamp((string) $pwHash);
$_POST = ['slug' => 'app-choice-check', 'name' => 'App Choice Check', 'app_id' => 'abos', 'price' => '5,00', 'currency' => 'EUR', 'period_months' => '1', 'features' => ''];
ob_start();
(new AdminController($appShop))->createPlan([]);
ob_end_clean();
$justCreated = $plans->plan((int) $pdo->query("SELECT id FROM x_subscription_plan WHERE slug = 'app-choice-check'")->fetchColumn());
check('AdminController: an app_id naming an addon (not an "app"-type extension) is not accepted, the plan still saves as general', $justCreated !== null && $justCreated['app_id'] === null);
$_POST = ['slug' => 'app-choice-check-2', 'name' => 'Real App', 'app_id' => 'shop', 'price' => '5,00', 'currency' => 'EUR', 'period_months' => '1', 'features' => ''];
ob_start();
(new AdminController($appShop))->createPlan([]);
ob_end_clean();
$justCreated2 = $plans->plan((int) $pdo->query("SELECT id FROM x_subscription_plan WHERE slug = 'app-choice-check-2'")->fetchColumn());
check('AdminController: a real "app"-type extension id is accepted', $justCreated2 !== null && $justCreated2['app_id'] === 'shop');
$_SESSION = [];
$_POST = [];

// --- A real request end to end through the public and admin routes -----------
ob_start();
(new SubscriptionController($appShop))->overview([]);
$overviewHtml = ob_get_clean();
check('SubscriptionController::overview(): renders, lists the active plans visible for the active app, without a fatal error',
    str_contains($overviewHtml, 'Starter Plus') && str_contains($overviewHtml, 'Shop Pro') && !str_contains($overviewHtml, 'Freelancer Pro'));

$_SESSION['account_id'] = $aliceId;
$_SESSION['auth_stamp'] = Auth::stamp((string) $pwHash);
ob_start();
(new SubscriptionController($app))->account([]);
$accountHtml = ob_get_clean();
check('SubscriptionController::account(): shows the signed-in account\'s own current plan', str_contains($accountHtml, 'Starter Plus'));
$_SESSION = [];

// Give Alice the admin permission to view the administration pages. A
// fresh App here, not the $app used above: Auth caches account/permissions
// for its own lifetime (one real request would never grant a role to
// itself mid-flight), and $app's Auth already answered can() once, through
// base.twig's own check, before this role existed.
$pdo->exec("INSERT INTO role (id, name) VALUES (1, 'Admin')");
$pdo->exec("INSERT INTO role_permission (role_id, permission) VALUES (1, 'abos.subscriptions.manage')");
$pdo->exec("INSERT INTO account_role (account_id, role_id) VALUES ({$aliceId}, 1)");
$appAdmin = buildApp([], $pdo, $baseConfig, $core);
$_SESSION['account_id'] = $aliceId;
$_SESSION['auth_stamp'] = Auth::stamp((string) $pwHash);
check('Auth::can(): the permission actually took effect', $appAdmin->auth->can('abos.subscriptions.manage'));
ob_start();
(new AdminController($appAdmin))->index([]);
$adminHtml = ob_get_clean();
check('AdminController::index(): renders the dashboard with plans, bank and issuer sections, no fatal error', str_contains($adminHtml, 'Starter Plus') && str_contains($adminHtml, 'Beispiel GmbH'));
$_SESSION = [];

// --- Clean up ----------------------------------------------------------------
$remove = function (string $dir) use (&$remove): void {
    foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
        is_dir("{$dir}/{$entry}") && !is_link("{$dir}/{$entry}") ? $remove("{$dir}/{$entry}") : unlink("{$dir}/{$entry}");
    }
    rmdir($dir);
};
$remove($uploads);

// --- Language files ----------------------------------------------------------
$de = require $here . '/lang/de.php';
$en = require $here . '/lang/en.php';
check('lang: de and en have the same keys', array_keys($de) === array_keys($en));
check('lang: every key starts with "abos."', array_filter(array_keys($de), fn (string $key) => !str_starts_with($key, 'abos.')) === []);
$used = [];
foreach ([...glob($here . '/templates/{,*/,*/*/}*.twig', GLOB_BRACE), ...glob($here . '/src/*.php')] as $file) {
    preg_match_all("/'(abos\.[a-z0-9_.]+[a-z0-9])'/", (string) file_get_contents($file), $found);
    $used = array_merge($used, $found[1]);
}
// Not language keys: Billing/Invoices' own setting() row names, which happen to share the "abos." prefix convention.
$unknown = array_diff(array_unique($used), array_keys($de), ['abos.subscriptions.manage', 'abos.bank_account', 'abos.invoice_issuer']);
check('lang: every key used in templates and code exists: ' . implode(', ', $unknown), $unknown === []);

if ($failed !== []) {
    echo "FAILED:\n";
    foreach ($failed as $label) {
        echo "  - {$label}\n";
    }
    echo count($failed) . " of {$checks} checks failed\n";
    exit(1);
}

echo "OK ({$checks} checks)\n";
