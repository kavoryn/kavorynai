-- Expose only the signed-in user's own eligible Paystack subscription code.
-- The provider code is never accepted from the client and is not returned by
-- the general entitlement endpoint.
create or replace function public.get_my_paystack_subscription_code()
returns text
language sql
stable
security definer
set search_path = ''
as $$
  select s.provider_subscription_code
    from private.user_subscriptions s
   where s.user_id = auth.uid()
     and s.provider = 'paystack'
     and s.provider_subscription_code is not null
     and (
       (s.status = 'active'
         and (s.current_period_end is null or s.current_period_end > pg_catalog.now()))
       or
       (s.status = 'past_due'
         and s.grace_period_end is not null
         and s.grace_period_end > pg_catalog.now())
     )
   order by s.updated_at desc, s.created_at desc
   limit 1;
$$;

revoke all on function public.get_my_paystack_subscription_code() from public, anon, service_role;
grant execute on function public.get_my_paystack_subscription_code() to authenticated;

comment on function public.get_my_paystack_subscription_code() is
  'Returns only the authenticated user own eligible Paystack subscription code for hosted management-link generation.';
