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
select public.reconcile_paystack_charge_success(
  repeat('a', 64), 'kavoryn-wrong-reference-01', 'success', 250000, 'NGN',
  'paystack-txn-1001', '2026-11-14T00:00:00Z'
);
reset role;

do $
begin
  if (select count(*) from private.billing_webhook_receipts where payload_sha256 = repeat('a', 64)) <> 1 then
    raise exception 'duplicate webhook receipt was inserted';
  end if;
  if (select status from private.billing_transactions where reference = 'kavoryn-integration-ok-01') <> 'succeeded' then
    raise exception 'duplicate reconciliation changed payment state';
  end if;
end;
$$;

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

select 'Paystack reconciliation SQL integration tests passed.' as result;
