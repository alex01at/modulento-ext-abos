-- Plans an operator sells, and which plan an account has now. A plan lists
-- its features as a comma-separated list; an extension asks for one of
-- them by name through the core's Subscriptions facade. The history of an
-- account stays: a changed plan cancels the subscription it replaces.
--
-- app_id names the one "app"-type extension (freelancer/shop/auction/card)
-- a plan is scoped to, or NULL for a general plan available under any app.
-- No foreign key on purpose, same reasoning as the FAQ extension's own
-- extension_id column: a plan outlives whatever happens to the app it was
-- written for, and simply becomes general-looking (still scoped, just to
-- an app that is no longer installed) rather than breaking.
CREATE TABLE x_subscription_plan (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(40) NOT NULL,
    name VARCHAR(100) NOT NULL,
    app_id VARCHAR(32) NULL,
    price_cents INT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL,
    period_months TINYINT UNSIGNED NOT NULL,
    features VARCHAR(1000) NOT NULL DEFAULT '',
    offer_limits TEXT NOT NULL DEFAULT '{}',
    max_images_per_offer SMALLINT UNSIGNED NULL,
    per_seat TINYINT(1) NOT NULL DEFAULT 0,
    min_quantity SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    active TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_x_subscription_plan_slug (slug),
    KEY idx_x_subscription_plan_app (app_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The plan an account has now, or had. provider_ref is the Stripe
-- subscription id; quantity is the live, currently billed seat count for a
-- per-seat plan; stripe_item_ref is the Stripe subscription *item* id -
-- quantity lives there in Stripe's model, not on the subscription itself,
-- so it is captured separately. reminded_for is the end of the period a
-- renewal reminder was already sent for: one reminder per period.
CREATE TABLE x_subscription (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id INT UNSIGNED NOT NULL,
    plan_id INT UNSIGNED NOT NULL,
    status ENUM('trialing', 'active', 'past_due', 'canceled') NOT NULL,
    period_end DATETIME NULL,
    provider_ref VARCHAR(255) NULL,
    quantity SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    stripe_item_ref VARCHAR(255) NULL,
    reminded_for DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_x_subscription_account (account_id, status),
    KEY idx_x_subscription_provider_ref (provider_ref),
    CONSTRAINT fk_x_subscription_account FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE,
    CONSTRAINT fk_x_subscription_plan FOREIGN KEY (plan_id) REFERENCES x_subscription_plan (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- How an account pays for a plan: by bank transfer to the operator's
-- account, confirmed by hand once the money has arrived, or by Stripe on
-- the platform's own account. quantity is the quantity an order was
-- placed for, so its amount can be explained and a renewal or invoice can
-- still show it after the live quantity moves on.
CREATE TABLE x_subscription_order (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id INT UNSIGNED NOT NULL,
    plan_id INT UNSIGNED NOT NULL,
    quantity SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    method ENUM('transfer', 'stripe') NOT NULL,
    status ENUM('pending', 'paid', 'canceled') NOT NULL,
    reference VARCHAR(20) NOT NULL,
    amount_cents INT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL,
    stripe_session VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    paid_at DATETIME NULL,
    UNIQUE KEY uq_x_subscription_order_reference (reference),
    KEY idx_x_subscription_order_account (account_id),
    CONSTRAINT fk_x_subscription_order_account FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE,
    CONSTRAINT fk_x_subscription_order_plan FOREIGN KEY (plan_id) REFERENCES x_subscription_plan (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- An account's billing address, kept once and reused for its next orders.
CREATE TABLE x_subscription_billing_address (
    account_id INT UNSIGNED NOT NULL PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    street VARCHAR(200) NOT NULL,
    postal_code VARCHAR(20) NOT NULL,
    city VARCHAR(100) NOT NULL,
    country CHAR(2) NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_x_subscription_billing_address_account FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Invoices for subscriptions. The numbers run without gaps per year; an
-- invoice keeps the seller's and the buyer's details as they were when it
-- was issued, and it stays when the account is deleted (account_id is then
-- cleared). source_ref makes issuing idempotent: a second attempt for the
-- same payment (e.g. a retried webhook) returns the invoice that exists.
CREATE TABLE x_subscription_invoice (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    number VARCHAR(20) NOT NULL,
    year SMALLINT UNSIGNED NOT NULL,
    seq INT UNSIGNED NOT NULL,
    source_ref VARCHAR(80) NOT NULL,
    account_id INT UNSIGNED NULL,
    plan_name VARCHAR(100) NOT NULL,
    months TINYINT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL,
    net_cents INT UNSIGNED NOT NULL,
    tax_rate TINYINT UNSIGNED NOT NULL,
    tax_cents INT UNSIGNED NOT NULL,
    gross_cents INT UNSIGNED NOT NULL,
    buyer_name VARCHAR(150) NOT NULL,
    buyer_street VARCHAR(200) NOT NULL,
    buyer_postal_code VARCHAR(20) NOT NULL,
    buyer_city VARCHAR(100) NOT NULL,
    buyer_country CHAR(2) NOT NULL,
    issuer TEXT NOT NULL,
    issued_at DATETIME NOT NULL,
    UNIQUE KEY uq_x_subscription_invoice_number (number),
    UNIQUE KEY uq_x_subscription_invoice_seq (year, seq),
    UNIQUE KEY uq_x_subscription_invoice_source (source_ref),
    KEY idx_x_subscription_invoice_account (account_id),
    CONSTRAINT fk_x_subscription_invoice_account FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
