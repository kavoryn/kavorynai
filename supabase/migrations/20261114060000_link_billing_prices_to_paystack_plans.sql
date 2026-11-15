-- Associate each local subscription price with its merchant-created Paystack plan.
-- Checkout remains unavailable until the merchant configures a valid plan code.
alter table private.billing_prices
  add column if not exists paystack_plan_code text null;

alter table private.billing_prices
  drop constraint if exists billing_prices_paystack_plan_code_check;
alter table private.billing_prices
  add constraint billing_prices_paystack_plan_code_check
  check (
    paystack_plan_code is null
    or paystack_plan_code ~ '^PLN_[A-Za-z0-9_-]{6,120}$'
  );

create unique index if not exists billing_prices_paystack_plan_code_idx
  on private.billing_prices (paystack_plan_code)
  where paystack_plan_code is not null;

comment on column private.billing_prices.paystack_plan_code is
  'Merchant-created Paystack plan code. Never exposed in the public catalog; required before a subscription price is purchasable.';

create or replace function public.get_active_billing_catalog(p_currency text default 'NGN')
returns jsonb
language plpgsql
stable
security definer
set search_path = ''
as $$
declare
  v_user_id uuid := auth.uid();
  v_catalog jsonb;
begin
  if v_user_id is null then
    raise exception 'authentication required';
  end if;
  if p_currency is null or p_currency not in ('NGN', 'USD') then
    raise exception 'unsupported currency';
  end if;

  select coalesce(
    pg_catalog.jsonb_agg(
      pg_catalog.jsonb_build_object(
        'plan_key', p.plan_key,
        'version', p.version,
        'display_name', p.display_name,
        'entitlements', p.entitlements,
        'prices', coalesce((
          select pg_catalog.jsonb_agg(
            pg_catalog.jsonb_build_object(
              'price_id', bp.id,
              'currency', bp.currency,
              'amount_minor', bp.amount_minor,
              'interval_unit', bp.interval_unit,
              'interval_count', bp.interval_count
            ) order by bp.interval_unit, bp.interval_count
          )
          from private.billing_prices bp
          where bp.plan_id = p.id
            and bp.active = true
            and bp.currency = p_currency
            and bp.paystack_plan_code is not null
        ), '[]'::jsonb)
      )
      order by case p.plan_key when 'free' then 0 when 'plus' then 1 else 2 end
    ),
    '[]'::jsonb
  )
  into v_catalog
  from private.billing_plans p
  where p.active = true;

  return pg_catalog.jsonb_build_object('currency', p_currency, 'plans', v_catalog);
end;
$$;

revoke all on function public.get_active_billing_catalog(text) from public, anon, service_role;
grant execute on function public.get_active_billing_catalog(text) to authenticated;

comment on function public.get_active_billing_catalog(text) is
  'Returns active plan and price rows only when a matching merchant-created Paystack plan is configured; never exposes provider plan codes.';
