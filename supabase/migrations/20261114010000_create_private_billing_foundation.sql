-- Kavoryn billing foundation.
-- Schema only: no plan prices, quotas, active subscriptions, Paystack calls,
-- remote migration, deployment, or feature-flag changes are included.

create table if not exists private.billing_plans (
  id uuid primary key,
  plan_key text not null check (plan_key in ('free', 'plus', 'pro')),
  version integer not null check (version > 0),
  display_name text not null check (char_length(display_name) between 1 and 80),
  active boolean not null default false,
  entitlements jsonb not null default '{}'::jsonb
    check (pg_catalog.jsonb_typeof(entitlements) = 'object'),
  created_at timestamptz not null default pg_catalog.now(),
  unique (plan_key, version),
  unique (id, plan_key)
);

create unique index if not exists billing_plans_one_active_version_idx
  on private.billing_plans (plan_key)
  where active;

create table if not exists private.billing_prices (
  id uuid primary key,
  plan_id uuid not null references private.billing_plans(id) on delete restrict,
  currency text not null check (currency in ('NGN', 'USD')),
  amount_minor bigint not null check (amount_minor > 0),
  interval_unit text not null check (interval_unit in ('month', 'year')),
  interval_count integer not null default 1 check (interval_count between 1 and 12),
  active boolean not null default false,
  created_at timestamptz not null default pg_catalog.now(),
  unique (plan_id, currency, interval_unit, interval_count),
  unique (id, plan_id)
);

create index if not exists billing_prices_active_lookup_idx
  on private.billing_prices (plan_id, currency, interval_unit)
  where active;

create table if not exists private.user_subscriptions (
  id uuid primary key,
  user_id uuid not null references auth.users(id) on delete cascade,
  plan_id uuid not null references private.billing_plans(id) on delete restrict,
  price_id uuid null,
  provider text not null check (provider = 'paystack'),
  provider_customer_code text null check (
    provider_customer_code is null or char_length(provider_customer_code) <= 120
  ),
  provider_subscription_code text null check (
    provider_subscription_code is null or char_length(provider_subscription_code) <= 160
  ),
  status text not null check (status in (
    'pending', 'trialing', 'active', 'past_due', 'paused',
    'cancelled', 'expired', 'incomplete'
  )),
  current_period_start timestamptz null,
  current_period_end timestamptz null,
  cancel_at_period_end boolean not null default false,
  cancelled_at timestamptz null,
  created_at timestamptz not null default pg_catalog.now(),
  updated_at timestamptz not null default pg_catalog.now(),
  check (
    current_period_start is null or current_period_end is null
    or current_period_end > current_period_start
  ),
  foreign key (price_id, plan_id)
    references private.billing_prices(id, plan_id) on delete restrict
);

create index if not exists user_subscriptions_owner_history_idx
  on private.user_subscriptions (user_id, created_at desc, id desc);

create unique index if not exists user_subscriptions_paystack_code_idx
  on private.user_subscriptions (provider_subscription_code)
  where provider_subscription_code is not null;

create index if not exists user_subscriptions_entitlement_lookup_idx
  on private.user_subscriptions (user_id, status, current_period_end desc);

create table if not exists private.billing_transactions (
  id uuid primary key,
  user_id uuid not null references auth.users(id) on delete cascade,
  subscription_id uuid null references private.user_subscriptions(id) on delete set null,
  price_id uuid not null references private.billing_prices(id) on delete restrict,
  provider text not null check (provider = 'paystack'),
  reference text not null unique check (char_length(reference) between 8 and 120),
  expected_amount_minor bigint not null check (expected_amount_minor > 0),
  currency text not null check (currency in ('NGN', 'USD')),
  actual_amount_minor bigint null check (actual_amount_minor is null or actual_amount_minor >= 0),
  status text not null check (status in (
    'initialized', 'pending', 'succeeded', 'failed',
    'abandoned', 'refunded', 'reversed'
  )),
  provider_transaction_id text null check (
    provider_transaction_id is null or char_length(provider_transaction_id) <= 120
  ),
  paid_at timestamptz null,
  created_at timestamptz not null default pg_catalog.now(),
  updated_at timestamptz not null default pg_catalog.now()
);

create unique index if not exists billing_transactions_provider_id_idx
  on private.billing_transactions (provider_transaction_id)
  where provider_transaction_id is not null;

create index if not exists billing_transactions_owner_history_idx
  on private.billing_transactions (user_id, created_at desc, id desc);

create table if not exists private.billing_webhook_receipts (
  id uuid primary key,
  provider text not null check (provider = 'paystack'),
  payload_sha256 text not null check (payload_sha256 ~ '^[0-9a-f]{64}$'),
  event_type text not null check (char_length(event_type) between 1 and 120),
  event_reference text null check (
    event_reference is null or char_length(event_reference) <= 120
  ),
  received_at timestamptz not null default pg_catalog.now(),
  processed_at timestamptz null,
  processing_status text not null default 'received'
    check (processing_status in ('received', 'processing', 'processed', 'ignored', 'failed')),
  safe_error_code text null check (
    safe_error_code is null or char_length(safe_error_code) <= 80
  ),
  unique (provider, payload_sha256)
);

create index if not exists billing_webhook_receipts_pending_idx
  on private.billing_webhook_receipts (received_at, id)
  where processing_status in ('received', 'processing', 'failed');

create table if not exists private.billing_audit_events (
  id uuid primary key,
  actor_user_id uuid null references auth.users(id) on delete set null,
  action text not null check (char_length(action) between 1 and 100),
  target_type text not null check (char_length(target_type) between 1 and 80),
  target_id uuid null,
  metadata jsonb not null default '{}'::jsonb
    check (pg_catalog.jsonb_typeof(metadata) = 'object'),
  created_at timestamptz not null default pg_catalog.now()
);

create index if not exists billing_audit_events_created_idx
  on private.billing_audit_events (created_at desc, id desc);

alter table private.billing_plans enable row level security;
alter table private.billing_prices enable row level security;
alter table private.user_subscriptions enable row level security;
alter table private.billing_transactions enable row level security;
alter table private.billing_webhook_receipts enable row level security;
alter table private.billing_audit_events enable row level security;

revoke all on table private.billing_plans from public, anon, authenticated, service_role;
revoke all on table private.billing_prices from public, anon, authenticated, service_role;
revoke all on table private.user_subscriptions from public, anon, authenticated, service_role;
revoke all on table private.billing_transactions from public, anon, authenticated, service_role;
revoke all on table private.billing_webhook_receipts from public, anon, authenticated, service_role;
revoke all on table private.billing_audit_events from public, anon, authenticated, service_role;

comment on table private.billing_plans is
  'Versioned plan definitions. Entitlement values remain empty until product quotas are approved.';
comment on table private.billing_prices is
  'Explicit merchant-approved Paystack price points stored in currency minor units; no prices are seeded.';
comment on table private.user_subscriptions is
  'Private subscription lifecycle state; clients never set plan or status directly.';
comment on table private.billing_transactions is
  'Server-created payment expectations reconciled against Paystack verification and signed webhooks.';
comment on table private.billing_webhook_receipts is
  'Webhook deduplication and processing ledger; raw request bodies and signatures are not stored.';
comment on table private.billing_audit_events is
  'Append-only audit trail for privileged billing and subscription actions.';
