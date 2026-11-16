#!/usr/bin/env bash
set -euo pipefail

: "${PGHOST:=127.0.0.1}"
: "${PGPORT:=5432}"
: "${PGUSER:=postgres}"
: "${PGDATABASE:=postgres}"
export PGHOST PGPORT PGUSER PGDATABASE

# Recreate only the minimal Supabase Auth surface required by the chat migrations.
psql -v ON_ERROR_STOP=1 <<'SQL'
create schema if not exists auth;
create table if not exists auth.users (
  id uuid primary key,
  email text,
  created_at timestamptz not null default now()
);
do $roles$
begin
  if not exists (select 1 from pg_roles where rolname='anon') then create role anon nologin; end if;
  if not exists (select 1 from pg_roles where rolname='authenticated') then create role authenticated nologin; end if;
  if not exists (select 1 from pg_roles where rolname='service_role') then create role service_role nologin bypassrls; end if;
end
$roles$;
SQL

psql -v ON_ERROR_STOP=1 -f supabase/migrations/20261114002000_create_private_chat_storage.sql
psql -v ON_ERROR_STOP=1 -f supabase/migrations/20261114003000_add_private_chat_operations.sql
psql -v ON_ERROR_STOP=1 -f supabase/migrations/20261116010000_add_bounded_chat_context_rpc.sql
psql -v ON_ERROR_STOP=1 -f supabase/migrations/20261116020000_add_atomic_chat_turn_idempotency.sql

psql -v ON_ERROR_STOP=1 <<'SQL'
insert into auth.users(id,email) values
 ('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa','chat-owner@example.test'),
 ('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb','chat-other@example.test')
on conflict (id) do nothing;

do $privileges$
begin
  if has_function_privilege('anon','public.get_chat_conversation(uuid,uuid)','EXECUTE')
     or has_function_privilege('authenticated','public.get_chat_conversation(uuid,uuid)','EXECUTE')
     or has_function_privilege('anon','public.save_chat_message(uuid,uuid,uuid,uuid,text,text,text,text,text,integer,integer)','EXECUTE')
     or has_function_privilege('authenticated','public.save_chat_message(uuid,uuid,uuid,uuid,text,text,text,text,text,integer,integer)','EXECUTE')
     or has_function_privilege('anon','public.get_recent_chat_context(uuid,uuid,integer,integer)','EXECUTE')
     or has_function_privilege('authenticated','public.get_recent_chat_context(uuid,uuid,integer,integer)','EXECUTE')
     or has_function_privilege('anon','public.get_chat_conversation_metadata(uuid,uuid)','EXECUTE')
     or has_function_privilege('authenticated','public.get_chat_conversation_metadata(uuid,uuid)','EXECUTE')
     or has_function_privilege('anon','public.claim_chat_turn(uuid,uuid,uuid,uuid,uuid,text,text)','EXECUTE')
     or has_function_privilege('authenticated','public.claim_chat_turn(uuid,uuid,uuid,uuid,uuid,text,text)','EXECUTE')
     or has_function_privilege('anon','public.complete_chat_turn(uuid,uuid,uuid,uuid,text,text,text)','EXECUTE')
     or has_function_privilege('authenticated','public.complete_chat_turn(uuid,uuid,uuid,uuid,text,text,text)','EXECUTE')
     or has_function_privilege('anon','public.fail_chat_turn(uuid,uuid,uuid,text)','EXECUTE')
     or has_function_privilege('authenticated','public.fail_chat_turn(uuid,uuid,uuid,text)','EXECUTE') then
    raise exception 'Untrusted roles must not execute private chat RPCs';
  end if;
  if has_table_privilege('service_role','private.chat_conversations','SELECT')
     or has_table_privilege('service_role','private.chat_messages','SELECT')
     or has_table_privilege('service_role','private.chat_provider_consents','SELECT')
     or has_table_privilege('service_role','private.chat_turn_requests','SELECT') then
    raise exception 'Service role must use the server-only RPC boundary, not direct table access';
  end if;
  if not has_function_privilege('service_role','public.get_chat_conversation(uuid,uuid)','EXECUTE')
     or not has_function_privilege('service_role','public.save_chat_message(uuid,uuid,uuid,uuid,text,text,text,text,text,integer,integer)','EXECUTE')
     or not has_function_privilege('service_role','public.get_recent_chat_context(uuid,uuid,integer,integer)','EXECUTE')
     or not has_function_privilege('service_role','public.get_chat_conversation_metadata(uuid,uuid)','EXECUTE')
     or not has_function_privilege('service_role','public.claim_chat_turn(uuid,uuid,uuid,uuid,uuid,text,text)','EXECUTE')
     or not has_function_privilege('service_role','public.complete_chat_turn(uuid,uuid,uuid,uuid,text,text,text)','EXECUTE')
     or not has_function_privilege('service_role','public.fail_chat_turn(uuid,uuid,uuid,text)','EXECUTE') then
    raise exception 'service_role must be able to execute the chat RPCs';
  end if;
end
$privileges$;

set role service_role;
select public.record_chat_provider_consent(
 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
 'openai','chat-policy-test'
);
select public.create_chat_conversation(
 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
 'Owner private conversation','openai','en'
);
select public.create_chat_conversation(
 'd3d3d3d3-d3d3-43d3-83d3-d3d3d3d3d3d3',
 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
 'OpenAI idempotency conversation','openai','en'
);
select public.create_chat_conversation(
 'd2d2d2d2-d2d2-42d2-82d2-d2d2d2d2d2d2',
 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
 'Gemini single-turn conversation','gemini','en'
);
select public.record_chat_provider_consent(
 'c2c2c2c2-c2c2-42c2-82c2-c2c2c2c2c2c2',
 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
 'gemini','chat-policy-test'
);
select public.save_chat_message(
 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
 'ffffffff-ffff-4fff-8fff-ffffffffffff',
 'user','private owner message','completed','openai'
);
select public.save_chat_message(
 '12121212-1212-4212-8212-121212121212',
 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
 'ffffffff-ffff-4fff-8fff-ffffffffffff',
 'assistant','private assistant reply','completed','openai'
);
-- An unfinished turn must never be included in model context.
select public.save_chat_message(
 '13131313-1313-4313-8313-131313131313',
 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
 '14141414-1414-4414-8414-141414141414',
 'user','unfinished user turn','completed','openai'
);

-- Atomic idempotency: one claim, no duplicate user row, conflict on payload
-- mismatch, replay completed answer, and terminal failure blocks re-invocation.
do $idempotency$
declare
  v_claim jsonb;
  v_replay jsonb;
  v_completed jsonb;
  v_gemini jsonb;
begin
  v_claim := public.claim_chat_turn(
    'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    'd3d3d3d3-d3d3-43d3-83d3-d3d3d3d3d3d3',
    '15151515-1515-4515-8515-151515151515',
    '16161616-1616-4616-8616-161616161616',
    '17171717-1717-4717-8717-171717171717',
    'Idempotent hello','openai'
  );
  if v_claim->>'outcome' <> 'claimed' then
    raise exception 'First request should claim the turn';
  end if;

  v_replay := public.claim_chat_turn(
    'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    'd3d3d3d3-d3d3-43d3-83d3-d3d3d3d3d3d3',
    '41414141-4141-4141-8141-414141414141',
    '42424242-4242-4242-8242-424242424242',
    '43434343-4343-4343-8343-434343434343',
    'A different concurrent turn','openai'
  );
  if v_replay->>'outcome' <> 'processing'
     or v_replay->>'turn_id' <> v_claim->>'turn_id' then
    raise exception 'A conversation must not start a second concurrent generation';
  end if;

  v_replay := public.claim_chat_turn(
    'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    'd3d3d3d3-d3d3-43d3-83d3-d3d3d3d3d3d3',
    '15151515-1515-4515-8515-151515151515',
    '18181818-1818-4818-8818-181818181818',
    '19191919-1919-4919-8919-191919191919',
    'Idempotent hello','openai'
  );
  if v_replay->>'outcome' <> 'processing'
     or v_replay->>'turn_id' <> v_claim->>'turn_id' then
    raise exception 'Duplicate request must reuse the original in-progress turn';
  end if;

  v_replay := public.claim_chat_turn(
    'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    'd3d3d3d3-d3d3-43d3-83d3-d3d3d3d3d3d3',
    '15151515-1515-4515-8515-151515151515',
    '20202020-2020-4020-8020-202020202020',
    '21212121-2121-4121-8121-212121212121',
    'Different content','openai'
  );
  if v_replay->>'outcome' <> 'conflict' then
    raise exception 'Reusing a client turn ID with different content must conflict';
  end if;

  v_completed := public.complete_chat_turn(
    'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    'd3d3d3d3-d3d3-43d3-83d3-d3d3d3d3d3d3',
    '15151515-1515-4515-8515-151515151515',
    '22222222-2222-4222-8222-222222222222',
    'Idempotent answer','openai','test-model'
  );
  if v_completed->>'status' <> 'completed'
     or v_completed->>'content' <> 'Idempotent answer' then
    raise exception 'Turn completion did not persist the assistant response';
  end if;

  v_replay := public.claim_chat_turn(
    'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    'd3d3d3d3-d3d3-43d3-83d3-d3d3d3d3d3d3',
    '15151515-1515-4515-8515-151515151515',
    '23232323-2323-4323-8323-232323232323',
    '24242424-2424-4424-8424-242424242424',
    'Idempotent hello','openai'
  );
  if v_replay->>'outcome' <> 'completed'
     or v_replay->>'assistant_content' <> 'Idempotent answer' then
    raise exception 'Completed retry must replay the stored assistant response';
  end if;

  v_claim := public.claim_chat_turn(
    'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    'd3d3d3d3-d3d3-43d3-83d3-d3d3d3d3d3d3',
    '25252525-2525-4525-8525-252525252525',
    '26262626-2626-4626-8626-262626262626',
    '27272727-2727-4727-8727-272727272727',
    'A turn that will fail','openai'
  );
  if v_claim->>'outcome' <> 'claimed' then
    raise exception 'Failed-turn fixture should be claimed';
  end if;
  perform public.fail_chat_turn(
    'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    'd3d3d3d3-d3d3-43d3-83d3-d3d3d3d3d3d3',
    '25252525-2525-4525-8525-252525252525',
    'failed'
  );
  v_replay := public.claim_chat_turn(
    'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    'd3d3d3d3-d3d3-43d3-83d3-d3d3d3d3d3d3',
    '25252525-2525-4525-8525-252525252525',
    '28282828-2828-4828-8828-282828282828',
    '29292929-2929-4929-8929-292929292929',
    'A turn that will fail','openai'
  );
  if v_replay->>'outcome' <> 'failed' then
    raise exception 'Failed turn retry must not start provider work again';
  end if;

  v_gemini := public.claim_chat_turn(
    'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    'd2d2d2d2-d2d2-42d2-82d2-d2d2d2d2d2d2',
    '31313131-3131-4131-8131-313131313131',
    '32323232-3232-4232-8232-323232323232',
    '33333333-3333-4333-8333-333333333333',
    'First Gemini turn','gemini'
  );
  if v_gemini->>'outcome' <> 'claimed' then
    raise exception 'First Gemini turn should be claimed';
  end if;
  perform public.complete_chat_turn(
    'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    'd2d2d2d2-d2d2-42d2-82d2-d2d2d2d2d2d2',
    '31313131-3131-4131-8131-313131313131',
    '34343434-3434-4434-8434-343434343434',
    'First Gemini answer','gemini','gemini-test'
  );
  v_replay := public.claim_chat_turn(
    'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    'd2d2d2d2-d2d2-42d2-82d2-d2d2d2d2d2d2',
    '31313131-3131-4131-8131-313131313131',
    '35353535-3535-4535-8535-353535353535',
    '36363636-3636-4636-8636-363636363636',
    'First Gemini turn','gemini'
  );
  if v_replay->>'outcome' <> 'completed' then
    raise exception 'Completed Gemini retry must replay before multi-turn restriction';
  end if;
  v_gemini := public.claim_chat_turn(
    'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    'd2d2d2d2-d2d2-42d2-82d2-d2d2d2d2d2d2',
    '37373737-3737-4737-8737-373737373737',
    '38383838-3838-4838-8838-383838383838',
    '39393939-3939-4939-8939-393939393939',
    'Second Gemini turn','gemini'
  );
  if v_gemini->>'outcome' <> 'multi_turn_not_supported' then
    raise exception 'New Gemini follow-up must fail closed until step history is implemented';
  end if;
end
$idempotency$;

-- Revocation is provider-scoped, takes effect immediately, and is idempotent.
select public.record_chat_provider_consent(
 '12121212-1212-4121-8121-121212121212',
 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
 'gemini','chat-policy-test'
);
do $consent_revocation$
begin
  if not public.has_chat_provider_consent(
    'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa','openai'
  ) then
    raise exception 'Expected active OpenAI consent before revocation';
  end if;
  if public.revoke_chat_provider_consent(
    'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa','openai'
  ) <> 1 then
    raise exception 'OpenAI consent revocation should revoke exactly one active grant';
  end if;
  if public.has_chat_provider_consent(
    'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa','openai'
  ) then
    raise exception 'Revoked OpenAI consent must no longer authorize processing';
  end if;
  if public.revoke_chat_provider_consent(
    'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa','openai'
  ) <> 0 then
    raise exception 'Repeated OpenAI consent revocation should be idempotent';
  end if;
  if not public.has_chat_provider_consent(
    'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa','gemini'
  ) then
    raise exception 'Revoking OpenAI consent must not revoke Gemini consent';
  end if;
end
$consent_revocation$;

-- Inspect private rows as the migration owner, not the restricted service role.
reset role;
do $unique_turn$
declare
  v_user_count integer;
  v_assistant_count integer;
begin
  select count(*) into v_user_count from private.chat_messages
   where turn_id='16161616-1616-4616-8616-161616161616' and role='user';
  select count(*) into v_assistant_count from private.chat_messages
   where turn_id='16161616-1616-4616-8616-161616161616' and role='assistant';
  if v_user_count<>1 or v_assistant_count<>1 then
    raise exception 'Idempotent turn created duplicate user or assistant messages';
  end if;
end
$unique_turn$;
set role service_role;

do $isolation$
declare
  v_result jsonb;
  v_context jsonb;
  v_metadata jsonb;
  v_deleted boolean;
begin
  if public.get_chat_conversation(
    'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
    'dddddddd-dddd-4ddd-8ddd-dddddddddddd'
  ) is not null then
    raise exception 'Cross-user conversation read was not isolated';
  end if;

  v_metadata := public.get_chat_conversation_metadata(
    'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    'dddddddd-dddd-4ddd-8ddd-dddddddddddd'
  );
  if v_metadata->>'id' <> 'dddddddd-dddd-4ddd-8ddd-dddddddddddd'
     or v_metadata->>'provider' <> 'openai' then
    raise exception 'Owner-scoped metadata lookup returned invalid details';
  end if;
  if public.get_chat_conversation_metadata(
    'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
    'dddddddd-dddd-4ddd-8ddd-dddddddddddd'
  ) is not null then
    raise exception 'Cross-user metadata lookup was not isolated';
  end if;

  v_context := public.get_recent_chat_context(
    'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
    30,32000
  );
  if jsonb_array_length(v_context) <> 2
     or v_context->0->>'role' <> 'user'
     or v_context->0->>'content' <> 'private owner message'
     or v_context->1->>'role' <> 'assistant'
     or v_context->1->>'content' <> 'private assistant reply' then
    raise exception 'Recent context must contain only complete turns in chronological order';
  end if;

  if public.get_recent_chat_context(
    'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
    'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
    30,32000
  ) <> '[]'::jsonb then
    raise exception 'Cross-user context returned another user messages';
  end if;

  v_result := public.list_chat_conversations('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',30);
  if v_result <> '[]'::jsonb then
    raise exception 'Cross-user history returned another user conversation';
  end if;

  v_deleted := public.delete_chat_conversation(
    'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
    'dddddddd-dddd-4ddd-8ddd-dddddddddddd'
  );
  if v_deleted then
    raise exception 'Cross-user delete unexpectedly succeeded';
  end if;

  begin
    perform public.save_chat_message(
      '11111111-1111-4111-8111-111111111111',
      'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
      'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
      '22222222-2222-4222-8222-222222222222',
      'user','unauthorized message','completed','openai'
    );
    raise exception 'Cross-user message write unexpectedly succeeded';
  exception when others then
    if sqlerrm = 'Cross-user message write unexpectedly succeeded' then raise; end if;
  end;
end
$isolation$;
reset role;
SQL

# Exercise the atomic upsert under simultaneous requests. Exactly five of twenty
# calls may pass a fresh per-user window with a limit of five.
psql -v ON_ERROR_STOP=1 -c "insert into auth.users(id,email) values ('33333333-3333-4333-8333-333333333333','rate-limit@example.test') on conflict (id) do nothing" >/dev/null
tmpdir="$(mktemp -d)"
trap 'rm -rf "$tmpdir"' EXIT
for n in $(seq 1 20); do
  (
    psql -X -A -t -v ON_ERROR_STOP=1 -c "select public.consume_chat_rate_limit('33333333-3333-4333-8333-333333333333',5,60)" >"$tmpdir/$n"
  ) &
done
wait
allowed="$(grep -hxc 't' "$tmpdir"/* | awk '{sum += $1} END {print sum+0}')"
[[ "$allowed" == "5" ]] || {
  echo "Concurrent chat rate limit expected 5 allowed requests; observed $allowed." >&2
  exit 1
}

echo "Chat PostgreSQL integration tests passed: grants, owner isolation, and concurrent atomic rate limiting."
