# Abos

An addon for [Modulento](https://github.com/alex01at/modulento) that adds
subscription plans and their billing: the provider behind the core's
`Modulento\Core\Subscription\Subscriptions` facade
(`Registrar::subscriptionProvider()`). Without this addon enabled, every
feature a plan would gate stays open and nothing is granted or limited -
installing it is what turns "an extension sells a feature" into something
that actually has to be paid for.

- **Plans**: a name, a price, a period, the features it includes, limits
  on offers and pictures per offer, and - for a per-seat plan such as a
  team subscription - a floor quantity. A plan can be **scoped to one
  installed "app"** (freelancer, shop, auction, card - only one is ever
  active at a time) or left **general**, visible under any of them; a plan
  written for an app that later gets swapped out simply stops being
  offered rather than breaking.
- **`/subscriptions`**: the public plan overview, checkout (billing address,
  bank transfer or Stripe), the account's own orders and invoices.
  **Administration → Abos** (permission `abos.subscriptions.manage`): plan
  CRUD, the operator's bank account and invoice issuer details, confirming
  a pending transfer, assigning a plan to an account by hand, and the
  newest subscriptions and invoices.
- **Billing**: numbered, gapless-per-year invoices (`RE-{year}-{seq}`); a
  Stripe subscription's checkout, renewal, failed payment and cancellation
  are handled through the core's Stripe webhook hook; a daily cron task
  reminds an account by e-mail a week before a bank-transfer subscription
  ends.
- Another extension asks whether an account's plan grants it a feature
  through the core alone - `$app->subscriptions->allows($accountId, 'x')` -
  never through this addon directly, so it still compiles and behaves (every
  feature open) with this addon not installed.

## Installing

Clone or symlink this repository into `extensions/abos` of a Modulento
installation, then enable it under Administration → Extensions. Afterwards,
set the operator's bank account and invoice issuer details under
Administration → Abos before any plan can actually be booked.

## Running its tests

```
MODULENTO_CORE=/path/to/modulento php tests/run.php
```
