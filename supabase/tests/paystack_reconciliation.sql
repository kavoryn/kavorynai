-- Disposable PostgreSQL integration test for Paystack charge reconciliation.
-- Run after the two billing migrations, with auth.users and Supabase roles bootstrapped.

insert into auth.users (id)
values ('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')
on conflict (id) do nothing;

insert into private.billing_plans (id, plan_key, version, display_name, active)
values ('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'plus', 1, 'Plus', true)
on conflict (id) do nothing;

insert into private.billing_prices (id, plan_id, currency, amount_minor, interval_unit, active)
values ('cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'NGN', 250000, 'month', true)
on conflict (id) do nothing;

insert into private.billing_transactions (
  id, user_id, price_id, provider, reference, expected_amount_minor, currency, status
) values
  ('dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
   'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'paystack', 'kavoryn-integration-ok-01', 250000, 'NGN', 'pending'),
  ('eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
   'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'paystack', 'kavoryn-integration-mismatch', 250000, 'NGN', 'pending'),
  ('ffffffff-ffff-4fff-8fff-ffffffffffff', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
   'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'paystack', 'kavoryn-integration-failed-01', 250000, 'NGN', 'pending');

set role service_role;
select public.record_paystack_webhook_receipt(
  '11111111-1111-4111-8111-111111111111',
  repeat('a', 64), 'charge.success', 'kavoryn-integration-ok-01'
);
select public.reconcile_paystack_charge_success(
  repeat('a', 64), 'kavoryn-integration-ok-01', 'success', 250000, 'NGN',
  'paystack-txn-1001', '2026-11-14T00:00:00Z'
);
reset role;

do $$
begin
  if (select status from private.billing_transactions where reference = 'kavoryn-integration-ok-01') <> 'succeeded' then
    raise exception 'successful verified payment was not reconciled';
  end if;
  if (select processing_status from private.billing_webhook_receipts where payload_sha256 = repeat('a', 64)) <> 'processed' then
    raise exception 'successful receipt was not marked processed';
  end if;
end;
$$;

set role service_role;
select public.record_paystack_webhook_receipt(
  '22222222-2222-4222-8222-222222222222',
  repeat('a', 64), 'charge.success', 'kavoryn-integration-ok-01'
);
select public.reconcile_paystack_charge_success(
  repeat('a', 64), 'kavoryn-integration-ok-01', 'success', 250000, 'NGN',
  'paystack-txn-1001', '2026-11-14T00:00:00Z'
);
do $test$
declare
  v_reconciled boolean;
begin
  select public.reconcile_paystack_charge_success(
    repeat('a', 64), 'kavoryn-wrong-reference-01', 'success', 250000, 'NGN',
    'paystack-txn-1001', '2026-11-14T00:00:00Z'
  ) into v_reconciled;
  if v_reconciled then
    raise exception 'duplicate receipt accepted a mismatched reference';
  end if;
end;
$test$;
reset role;

do $test$
begin
  if (select count(*) from private.billing_webhook_receipts where payload_sha256 = repeat('a', 64)) <> 1 then
    raise exception 'duplicate webhook receipt was inserted';
  end if;
  if (select status from private.billing_transactions where reference = 'kavoryn-integration-ok-01') <> 'succeeded' then
    raise exception 'duplicate reconciliation changed payment state';
  end if;
end;
$test$;

set role service_role;
select public.record_paystack_webhook_receipt(
  '33333333-3333-4333-8333-333333333333',
  repeat('b', 64), 'charge.success', 'kavoryn-integration-mismatch'
);
select public.reconcile_paystack_charge_success(
  repeat('b', 64), 'kavoryn-integration-mismatch', 'success', 249999, 'NGN',
  'paystack-txn-1002', '2026-11-14T00:00:00Z'
);
reset role;

do $$
begin
  if (select status from private.billing_transactions where reference = 'kavoryn-integration-mismatch') <> 'pending' then
    raise exception 'mismatched payment changed transaction state';
  end if;
  if (select processing_status from private.billing_webhook_receipts where payload_sha256 = repeat('b', 64)) <> 'ignored' then
    raise exception 'mismatched payment receipt was not safely ignored';
  end if;
end;
$$;

set role service_role;
select public.record_paystack_webhook_receipt(
  '44444444-4444-4444-8444-444444444444',
  repeat('c', 64), 'charge.success', 'kavoryn-integration-failed-01'
);
do $$
begin
  begin
    perform public.reconcile_paystack_charge_success(
      repeat('c', 64), 'kavoryn-integration-failed-01', 'failed', 250000, 'NGN',
      'paystack-txn-1003', '2026-11-14T00:00:00Z'
    );
    raise exception 'failed verification was incorrectly accepted';
  exception
    when others then
      if sqlerrm = 'failed verification was incorrectly accepted' then
        raise;
      end if;
  end;
end;
$$;
reset role;

do $$
begin
  if (select status from private.billing_transactions where reference = 'kavoryn-integration-failed-01') <> 'pending' then
    raise exception 'failed payment changed transaction state';
  end if;
  if (select processing_status from private.billing_webhook_receipts where payload_sha256 = repeat('c', 64)) <> 'received' then
    raise exception 'failed verification incorrectly consumed webhook receipt';
  end if;
end;
$$;


-- Recurring payment: only a reconciled payment advances the subscription period.
insert into private.user_subscriptions (
  id, user_id, plan_id, price_id, provider, provider_subscription_code, status
) values (
  '99999999-9999-4999-8999-999999999999',
  'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
  'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
  'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
  'paystack', 'SUB_test_kavoryn_01', 'pending'
);

set role service_role;
select public.record_paystack_webhook_receipt(
  '15151515-1515-4515-8515-151515151515',
  repeat('d', 64), 'invoice.create', 'kavoryn-renewal-test-01'
);
select public.apply_paystack_lifecycle_event(
  repeat('d', 64), 'invoice.create', 'kavoryn-renewal-test-01',
  'SUB_test_kavoryn_01', 'success', null, null, 250000, 'NGN'
);
select public.record_paystack_webhook_receipt(
  '55555555-5555-4555-8555-555555555555',
  repeat('e', 64), 'charge.success', 'kavoryn-renewal-test-01'
);
select public.reconcile_paystack_charge_success(
  repeat('e', 64), 'kavoryn-renewal-test-01', 'success', 250000, 'NGN',
  'paystack-txn-renewal-01', '2026-11-14T00:00:00Z'
);
reset role;

do $$
begin
  if (select status from private.user_subscriptions where id='99999999-9999-4999-8999-999999999999') <> 'active' then
    raise exception 'verified renewal did not activate subscription';
  end if;
  if (select current_period_end from private.user_subscriptions where id='99999999-9999-4999-8999-999999999999')
     <> timestamptz '2026-12-14T00:00:00Z' then
    raise exception 'verified renewal did not advance one monthly period';
  end if;
end;
$$;

-- Failed invoice uses the server-configured grace duration.
set role service_role;
select public.record_paystack_webhook_receipt(
  '66666666-6666-4666-8666-666666666666',
  repeat('f', 64), 'invoice.payment_failed', null
);
select public.apply_paystack_lifecycle_event(
  repeat('f', 64), 'invoice.payment_failed', null, 'SUB_test_kavoryn_01',
  'attention', 72
);
reset role;

do $$
begin
  if (select status from private.user_subscriptions where id='99999999-9999-4999-8999-999999999999') <> 'past_due' then
    raise exception 'failed invoice did not mark subscription past_due';
  end if;
  if (select grace_period_end from private.user_subscriptions where id='99999999-9999-4999-8999-999999999999')
      <= now() then
    raise exception 'failed invoice did not establish grace period';
  end if;
end;
$$;

-- Authenticated user can read only their server-computed Plus entitlement.
set role authenticated;
select set_config('request.jwt.claim.sub', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', false);
do $$
declare
  v_entitlements jsonb;
begin
  v_entitlements := public.get_my_billing_entitlements();
  if v_entitlements->>'plan_key' <> 'plus'
     or v_entitlements->>'subscription_status' <> 'past_due' then
    raise exception 'grace-period Plus entitlement was not returned';
  end if;
end;
$$;
reset role;

-- Cancellation request from Paystack preserves paid access until period end.
set role service_role;
select public.record_paystack_webhook_receipt(
  '77777777-7777-4777-8777-777777777777',
  repeat('1', 64), 'subscription.not_renew', null
);
select public.apply_paystack_lifecycle_event(
  repeat('1', 64), 'subscription.not_renew', null, 'SUB_test_kavoryn_01',
  'non-renewing', null
);
reset role;

do $$
begin
  if not (select cancel_at_period_end from private.user_subscriptions where id='99999999-9999-4999-8999-999999999999') then
    raise exception 'subscription not-renew event did not schedule cancellation';
  end if;
end;
$$;

-- Refund pending blocks access only once processed; failed refunds restore success.
insert into private.billing_transactions (
  id, user_id, subscription_id, price_id, provider, reference, expected_amount_minor,
  actual_amount_minor, currency, status, provider_transaction_id, paid_at
) values (
  '12121212-1212-4212-8212-121212121212',
  'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
  '99999999-9999-4999-8999-999999999999',
  'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
  'paystack', 'kavoryn-refund-test-01', 250000, 250000, 'NGN',
  'succeeded', 'paystack-refund-txn-01', now()
);

set role service_role;
select public.record_paystack_webhook_receipt(
  '13131313-1313-4313-8313-131313131313',
  repeat('2', 64), 'refund.pending', 'kavoryn-refund-test-01'
);
select public.apply_paystack_lifecycle_event(
  repeat('2', 64), 'refund.pending', 'kavoryn-refund-test-01', null, 'pending', null, 10000
);
reset role;

do $$
begin
  if (select status from private.billing_transactions where reference='kavoryn-refund-test-01') <> 'reversal_pending' then
    raise exception 'pending refund did not set reversal_pending';
  end if;
end;
$$;

set role service_role;
select public.record_paystack_webhook_receipt(
  '14141414-1414-4414-8414-141414141414',
  repeat('3', 64), 'refund.failed', 'kavoryn-refund-test-01'
);
select public.apply_paystack_lifecycle_event(
  repeat('3', 64), 'refund.failed', 'kavoryn-refund-test-01', null, 'failed', null, 10000
);
reset role;

do $$
begin
  if (select status from private.billing_transactions where reference='kavoryn-refund-test-01') <> 'succeeded' then
    raise exception 'failed refund did not restore successful transaction state';
  end if;
  if (select current_period_end from private.user_subscriptions where id='99999999-9999-4999-8999-999999999999')
     <> timestamptz '2026-12-14T00:00:00Z' then
    raise exception 'failed refund incorrectly advanced the subscription period';
  end if;
end;
$$;

-- Partial processed refund reverses only the refunded transaction amount and
-- must not revoke the subscription; full refund does revoke it.
set role service_role;
select public.record_paystack_webhook_receipt(
  '15151515-1616-4515-8515-151515151515',
  repeat('4', 64), 'refund.processed', 'kavoryn-refund-test-01'
);
select public.apply_paystack_lifecycle_event(
  repeat('4', 64), 'refund.processed', 'kavoryn-refund-test-01', null, 'processed', null, 10000
);
reset role;

do $$
begin
  if (select status from private.user_subscriptions where id='99999999-9999-4999-8999-999999999999') <> 'past_due' then
    raise exception 'partial refund incorrectly revoked subscription entitlement';
  end if;
end;
$$;

set role service_role;
select public.record_paystack_webhook_receipt(
  '16161616-1616-4616-8616-161616161616',
  repeat('5', 64), 'refund.processed', 'kavoryn-refund-test-01'
);
select public.apply_paystack_lifecycle_event(
  repeat('5', 64), 'refund.processed', 'kavoryn-refund-test-01', null, 'processed', null, 250000
);
reset role;

do $$
begin
  if (select status from private.user_subscriptions where id='99999999-9999-4999-8999-999999999999') <> 'expired' then
    raise exception 'full processed refund did not revoke subscription entitlement';
  end if;
end;
$$;

-- Expiration housekeeping removes stale active access after the period end.
insert into private.user_subscriptions (
  id, user_id, plan_id, price_id, provider, provider_subscription_code,
  status, current_period_start, current_period_end
) values (
  '17171717-1717-4717-8717-171717171717',
  'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
  'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
  'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
  'paystack', 'SUB_test_expired_01', 'active',
  now() - interval '2 months', now() - interval '1 day'
);

set role service_role;
select public.expire_due_billing_subscriptions();
reset role;

do $$
begin
  if (select status from private.user_subscriptions where id='17171717-1717-4717-8717-171717171717') <> 'expired' then
    raise exception 'expired billing period was not transitioned to expired';
  end if;
end;
$$;

select 'Paystack reconciliation and lifecycle SQL integration tests passed.' as result;

