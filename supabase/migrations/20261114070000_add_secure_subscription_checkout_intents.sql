-- Server-created subscription checkout intents and verified subscription.create linkage.
-- No live Paystack requests or customer access are enabled by this migration.

create or replace function public.create_my_billing_checkout_intent(
  p_user_id uuid,
  p_price_id uuid,
  p_reference text,
  p_transaction_id uuid,
  p_subscription_id uuid
)
returns jsonb
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_user auth.users%rowtype;
  v_price private.billing_prices%rowtype;
  v_plan private.billing_plans%rowtype;
begin
  if p_user_id is null or p_price_id is null or p_transaction_id is null
     or p_subscription_id is null
     or p_reference is null
     or p_reference !~ '^[A-Za-z0-9._=-]{8,120}$' then
    raise exception 'invalid checkout intent';
  end if;

  select * into v_user from auth.users where id = p_user_id;
  if not found or v_user.email is null or v_user.email_confirmed_at is null then
    raise exception 'verified account email required';
  end if;

  -- Expire stale abandoned checkout intents before enforcing one open attempt.
  update private.billing_transactions
     set status='abandoned', updated_at=pg_catalog.now()
   where user_id=p_user_id and status='initialized'
     and created_at <= pg_catalog.now() - interval '30 minutes';

  update private.user_subscriptions
     set status='incomplete', updated_at=pg_catalog.now()
   where user_id=p_user_id and status='pending'
     and created_at <= pg_catalog.now() - interval '30 minutes';

  if exists (
    select 1 from private.user_subscriptions
     where user_id=p_user_id and status='pending'
       and created_at > pg_catalog.now() - interval '30 minutes'
  ) then
    raise exception 'checkout already pending';
  end if;

  select bp.* into v_price
    from private.billing_prices bp
    join private.billing_plans bp2 on bp2.id=bp.plan_id
   where bp.id=p_price_id
     and bp.active=true
     and bp.paystack_plan_code is not null
     and bp2.active=true
     and bp2.plan_key in ('plus','pro')
   for share of bp, bp2;
  if not found then raise exception 'price unavailable'; end if;

  select * into v_plan from private.billing_plans where id=v_price.plan_id;
  if not found then raise exception 'plan unavailable'; end if;

  if exists (
    select 1 from private.user_subscriptions s
    join private.billing_plans p on p.id=s.plan_id
    where s.user_id=p_user_id
      and s.status in ('active','trialing','past_due')
      and p.active=true
      and (
        (s.status in ('active','trialing') and
          (s.current_period_end is null or s.current_period_end > pg_catalog.now()))
        or (s.status='past_due' and s.grace_period_end > pg_catalog.now())
      )
  ) then
    raise exception 'active subscription already exists';
  end if;

  insert into private.user_subscriptions (
    id,user_id,plan_id,price_id,provider,status
  ) values (
    p_subscription_id,p_user_id,v_plan.id,v_price.id,'paystack','pending'
  );

  insert into private.billing_transactions (
    id,user_id,subscription_id,price_id,provider,reference,
    expected_amount_minor,currency,status
  ) values (
    p_transaction_id,p_user_id,p_subscription_id,v_price.id,'paystack',p_reference,
    v_price.amount_minor,v_price.currency,'initialized'
  );

  return pg_catalog.jsonb_build_object(
    'reference',p_reference,
    'amount_minor',v_price.amount_minor,
    'currency',v_price.currency,
    'email',v_user.email,
    'plan_key',v_plan.plan_key,
    'plan_name',v_plan.display_name,
    'plan_code',v_price.paystack_plan_code
  );
end;
$$;

revoke all on function public.create_my_billing_checkout_intent(uuid,uuid,text,uuid,uuid)
  from public, anon, authenticated;
grant execute on function public.create_my_billing_checkout_intent(uuid,uuid,text,uuid,uuid)
  to service_role;

create or replace function public.apply_paystack_subscription_created(
  p_payload_sha256 text,
  p_subscription_code text,
  p_customer_email text,
  p_plan_code text,
  p_customer_code text default null
)
returns boolean
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_receipt private.billing_webhook_receipts%rowtype;
  v_subscription_id uuid;
  v_count integer;
begin
  if p_payload_sha256 is null or p_payload_sha256 !~ '^[0-9a-f]{64}$'
     or p_subscription_code is null
     or p_subscription_code !~ '^SUB_[A-Za-z0-9_-]{6,160}$'
     or p_customer_email is null or length(p_customer_email) > 320
     or p_plan_code is null or p_plan_code !~ '^PLN_[A-Za-z0-9_-]{6,120}$'
     or (p_customer_code is not null and length(p_customer_code) > 120) then
    raise exception 'invalid subscription creation details';
  end if;

  select * into v_receipt
    from private.billing_webhook_receipts
   where provider='paystack' and payload_sha256=p_payload_sha256
   for update;
  if not found or v_receipt.event_type <> 'subscription.create' then
    raise exception 'verified subscription receipt not found';
  end if;
  if v_receipt.processing_status='processed' then return true; end if;
  if v_receipt.processing_status <> 'received' then return false; end if;

  select (pg_catalog.array_agg(s.id))[1], count(*) into v_subscription_id, v_count
    from private.user_subscriptions s
    join auth.users u on u.id=s.user_id
    join private.billing_prices bp on bp.id=s.price_id and bp.plan_id=s.plan_id
    join private.billing_plans p on p.id=s.plan_id
   where lower(u.email)=lower(p_customer_email)
     and bp.paystack_plan_code=p_plan_code
     and bp.active=true and p.active=true
     and s.provider='paystack'
     and s.provider_subscription_code is null
     and s.status in ('pending','active')
     and s.created_at > pg_catalog.now() - interval '30 minutes';

  if v_count <> 1 then
    update private.billing_webhook_receipts
       set processing_status='ignored',
           safe_error_code=case when v_count=0 then 'checkout_intent_not_found' else 'checkout_intent_ambiguous' end,
           processed_at=pg_catalog.now()
     where id=v_receipt.id;
    return false;
  end if;

  update private.user_subscriptions
     set provider_subscription_code=p_subscription_code,
         provider_customer_code=p_customer_code,
         updated_at=pg_catalog.now()
   where id=v_subscription_id
     and provider_subscription_code is null;

  if not found then return false; end if;

  update private.billing_webhook_receipts
     set processing_status='processed', processed_at=pg_catalog.now(), safe_error_code=null
   where id=v_receipt.id;
  return true;
end;
$$;

revoke all on function public.apply_paystack_subscription_created(text,text,text,text,text)
  from public, anon, authenticated;
grant execute on function public.apply_paystack_subscription_created(text,text,text,text,text)
  to service_role;

comment on function public.create_my_billing_checkout_intent(uuid,uuid,text,uuid,uuid) is
  'Creates a server-priced, one-at-a-time pending subscription checkout using an authenticated user ID supplied only by the trusted API.';
comment on function public.apply_paystack_subscription_created(text,text,text,text,text) is
  'Links a signed Paystack subscription.create event to exactly one recent pending checkout by verified account email and merchant plan code; does not grant access.';
