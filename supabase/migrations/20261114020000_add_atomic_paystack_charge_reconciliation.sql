-- Atomic Paystack charge reconciliation.
-- Only a server-verified Paystack transaction may update the expected payment.
-- This does not activate a subscription or grant plan entitlements.

create or replace function public.reconcile_paystack_charge_success(
  p_payload_sha256 text,
  p_reference text,
  p_verified_status text,
  p_amount_minor bigint,
  p_currency text,
  p_provider_transaction_id text,
  p_paid_at timestamptz
)
returns boolean
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_receipt private.billing_webhook_receipts%rowtype;
  v_transaction private.billing_transactions%rowtype;
begin
  if p_payload_sha256 is null
     or p_payload_sha256 !~ '^[0-9a-f]{64}$'
     or p_reference is null
     or char_length(p_reference) not between 8 and 120
     or p_verified_status is distinct from 'success'
     or p_amount_minor is null
     or p_amount_minor < 1
     or p_currency is null
     or p_currency not in ('NGN', 'USD')
     or p_provider_transaction_id is null
     or char_length(p_provider_transaction_id) not between 1 and 120
     or p_paid_at is null then
    raise exception 'invalid verified payment details';
  end if;

  select *
    into v_receipt
    from private.billing_webhook_receipts
   where provider = 'paystack'
     and payload_sha256 = p_payload_sha256
   for update;

  if not found then
    raise exception 'verified webhook receipt not found';
  end if;

  if v_receipt.event_type <> 'charge.success'
     or v_receipt.event_reference is distinct from p_reference then
    if v_receipt.processing_status <> 'processed' then
      update private.billing_webhook_receipts
         set processing_status = 'ignored',
             safe_error_code = 'event_not_reconcilable',
             processed_at = pg_catalog.now()
       where id = v_receipt.id;
    end if;
    return false;
  end if;

  if v_receipt.processing_status = 'processed' then
    return true;
  end if;

  if v_receipt.processing_status <> 'received' then
    update private.billing_webhook_receipts
       set processing_status = 'ignored',
           safe_error_code = 'event_not_reconcilable',
           processed_at = pg_catalog.now()
     where id = v_receipt.id;
    return false;
  end if;

  select *
    into v_transaction
    from private.billing_transactions
   where provider = 'paystack'
     and reference = p_reference
   for update;

  if not found then
    update private.billing_webhook_receipts
       set processing_status = 'ignored',
           safe_error_code = 'transaction_not_found',
           processed_at = pg_catalog.now()
     where id = v_receipt.id;
    return false;
  end if;

  if v_transaction.expected_amount_minor <> p_amount_minor
     or v_transaction.currency <> p_currency
     or (v_transaction.provider_transaction_id is not null
         and v_transaction.provider_transaction_id <> p_provider_transaction_id)
     or v_transaction.status in ('refunded', 'reversed', 'failed', 'abandoned') then
    update private.billing_webhook_receipts
       set processing_status = 'ignored',
           safe_error_code = 'payment_mismatch',
           processed_at = pg_catalog.now()
     where id = v_receipt.id;
    return false;
  end if;

  update private.billing_transactions
     set status = 'succeeded',
         actual_amount_minor = p_amount_minor,
         provider_transaction_id = p_provider_transaction_id,
         paid_at = coalesce(v_transaction.paid_at, p_paid_at),
         updated_at = pg_catalog.now()
   where id = v_transaction.id;

  update private.billing_webhook_receipts
     set processing_status = 'processed',
         processed_at = pg_catalog.now(),
         safe_error_code = null
   where id = v_receipt.id;

  return true;
end;
$$;

revoke all on function public.reconcile_paystack_charge_success(text, text, text, bigint, text, text, timestamptz)
  from public, anon, authenticated;
grant execute on function public.reconcile_paystack_charge_success(text, text, text, bigint, text, text, timestamptz)
  to service_role;
