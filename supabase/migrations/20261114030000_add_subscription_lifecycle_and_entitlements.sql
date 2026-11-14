-- Subscription lifecycle, refund/reversal handling, and server-authoritative entitlements.
-- Grace duration is supplied by server configuration; no commercial policy is hardcoded.

alter table private.user_subscriptions
  add column if not exists grace_period_end timestamptz null,
  add column if not exists last_payment_failed_at timestamptz null;

alter table private.billing_transactions
  drop constraint if exists billing_transactions_status_check;
alter table private.billing_transactions
  add constraint billing_transactions_status_check
  check (status in (
    'initialized', 'pending', 'succeeded', 'failed', 'abandoned',
    'reversal_pending', 'refunded', 'reversed'
  ));

create index if not exists user_subscriptions_grace_lookup_idx
  on private.user_subscriptions (user_id, grace_period_end)
  where status = 'past_due' and grace_period_end is not null;

-- Handle subscription and refund lifecycle events only after a signed webhook
-- has been recorded. This function never trusts client-supplied user/plan IDs.
create or replace function public.apply_paystack_lifecycle_event(
  p_payload_sha256 text,
  p_event_type text,
  p_reference text default null,
  p_subscription_code text default null,
  p_provider_status text default null,
  p_grace_period_hours integer default null
)
returns boolean
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_receipt private.billing_webhook_receipts%rowtype;
  v_transaction private.billing_transactions%rowtype;
  v_subscription private.user_subscriptions%rowtype;
  v_refund_status text;
begin
  if p_payload_sha256 is null or p_payload_sha256 !~ '^[0-9a-f]{64}$'
     or p_event_type not in (
       'invoice.payment_failed', 'subscription.not_renew',
       'subscription.disable', 'refund.pending', 'refund.processing',
       'refund.processed', 'refund.failed'
     ) then
    raise exception 'invalid lifecycle event';
  end if;

  if p_event_type = 'invoice.payment_failed'
     and (p_grace_period_hours is null or p_grace_period_hours not between 1 and 720) then
    raise exception 'grace period configuration required';
  end if;

  select * into v_receipt
    from private.billing_webhook_receipts
   where provider = 'paystack' and payload_sha256 = p_payload_sha256
   for update;
  if not found then raise exception 'webhook receipt not found'; end if;

  if v_receipt.event_type <> p_event_type then
    return false;
  end if;
  if v_receipt.processing_status = 'processed' then return true; end if;
  if v_receipt.processing_status <> 'received' then return false; end if;

  if p_event_type in ('refund.pending','refund.processing','refund.processed','refund.failed') then
    if p_reference is null or v_receipt.event_reference is distinct from p_reference then
      update private.billing_webhook_receipts
         set processing_status='ignored', safe_error_code='event_reference_mismatch',
             processed_at=pg_catalog.now()
       where id=v_receipt.id;
      return false;
    end if;

    select * into v_transaction
      from private.billing_transactions
     where provider='paystack' and reference=p_reference
     for update;
    if not found then
      update private.billing_webhook_receipts
         set processing_status='ignored', safe_error_code='transaction_not_found',
             processed_at=pg_catalog.now()
       where id=v_receipt.id;
      return false;
    end if;

    v_refund_status := case p_event_type
      when 'refund.pending' then 'reversal_pending'
      when 'refund.processing' then 'reversal_pending'
      when 'refund.processed' then 'reversed'
      when 'refund.failed' then 'succeeded'
    end;

    if v_transaction.status not in ('succeeded','reversal_pending','reversed','refunded') then
      update private.billing_webhook_receipts
         set processing_status='ignored', safe_error_code='transaction_not_paid',
             processed_at=pg_catalog.now()
       where id=v_receipt.id;
      return false;
    end if;

    update private.billing_transactions
       set status=v_refund_status, updated_at=pg_catalog.now()
     where id=v_transaction.id;

    if p_event_type in ('refund.processed') and v_transaction.subscription_id is not null then
      update private.user_subscriptions
         set status='expired', grace_period_end=null, updated_at=pg_catalog.now()
       where id=v_transaction.subscription_id;
    end if;

  else
    if p_subscription_code is null or char_length(p_subscription_code) > 160 then
      update private.billing_webhook_receipts
         set processing_status='ignored', safe_error_code='subscription_code_missing',
             processed_at=pg_catalog.now()
       where id=v_receipt.id;
      return false;
    end if;

    select * into v_subscription
      from private.user_subscriptions
     where provider='paystack' and provider_subscription_code=p_subscription_code
     for update;
    if not found then
      update private.billing_webhook_receipts
         set processing_status='ignored', safe_error_code='subscription_not_found',
             processed_at=pg_catalog.now()
       where id=v_receipt.id;
      return false;
    end if;

    if p_event_type='invoice.payment_failed' then
      update private.user_subscriptions
         set status='past_due',
             last_payment_failed_at=pg_catalog.now(),
             grace_period_end=pg_catalog.now() + pg_catalog.make_interval(hours => p_grace_period_hours),
             updated_at=pg_catalog.now()
       where id=v_subscription.id;
    elsif p_event_type='subscription.not_renew' then
      -- Paystack says the current period remains active until its end.
      update private.user_subscriptions
         set cancel_at_period_end=true, updated_at=pg_catalog.now()
       where id=v_subscription.id;
    elsif p_event_type='subscription.disable' then
      update private.user_subscriptions
         set status=case when p_provider_status='complete' then 'expired' else 'cancelled' end,
             cancel_at_period_end=false,
             cancelled_at=coalesce(cancelled_at,pg_catalog.now()),
             grace_period_end=null,
             updated_at=pg_catalog.now()
       where id=v_subscription.id;
    end if;
  end if;

  update private.billing_webhook_receipts
     set processing_status='processed', processed_at=pg_catalog.now(), safe_error_code=null
   where id=v_receipt.id;
  return true;
end;
$$;

revoke all on function public.apply_paystack_lifecycle_event(text,text,text,text,text,integer)
  from public, anon, authenticated;
grant execute on function public.apply_paystack_lifecycle_event(text,text,text,text,text,integer)
  to service_role;

-- Entitlement decision is computed from private server-side rows and the
-- authenticated identity. Past-due access is limited to its configured grace.
create or replace function public.get_my_billing_entitlements()
returns jsonb
language sql
stable
security definer
set search_path = ''
as $$
  select coalesce((
    select pg_catalog.jsonb_build_object(
      'plan_key', p.plan_key,
      'plan_version', p.version,
      'entitlements', p.entitlements,
      'subscription_status', s.status,
      'current_period_end', s.current_period_end,
      'grace_period_end', s.grace_period_end,
      'cancel_at_period_end', s.cancel_at_period_end
    )
    from private.user_subscriptions s
    join private.billing_plans p on p.id=s.plan_id
    where s.user_id=auth.uid()
      and (
        (s.status in ('active','trialing') and
          (s.current_period_end is null or s.current_period_end > pg_catalog.now()))
        or
        (s.status='past_due' and s.grace_period_end is not null and s.grace_period_end > pg_catalog.now())
      )
    order by
      case p.plan_key when 'pro' then 3 when 'plus' then 2 else 1 end desc,
      s.created_at desc
    limit 1
  ), pg_catalog.jsonb_build_object(
    'plan_key','free',
    'plan_version',null,
    'entitlements','{}'::jsonb,
    'subscription_status','free',
    'current_period_end',null,
    'grace_period_end',null,
    'cancel_at_period_end',false
  ));
$$;

revoke all on function public.get_my_billing_entitlements() from public, anon;
grant execute on function public.get_my_billing_entitlements() to authenticated, service_role;

comment on function public.apply_paystack_lifecycle_event(text,text,text,text,text,integer) is
  'Processes authenticated Paystack subscription/refund events idempotently; grace duration must be configured server-side.';
comment on function public.get_my_billing_entitlements() is
  'Returns only the authenticated user entitlement derived from private subscription state.';
