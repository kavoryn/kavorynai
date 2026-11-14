-- Authenticated, read-only catalog of explicitly active plan and price rows.
-- No plan/price rows are seeded by this migration.
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
  'Returns only active public-facing plan and price rows for the authenticated user-selected currency.';
