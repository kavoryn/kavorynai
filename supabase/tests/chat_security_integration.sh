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
     or has_function_privilege('authenticated','public.save_chat_message(uuid,uuid,uuid,uuid,text,text,text,text,text,integer,integer)','EXECUTE') then
    raise exception 'Untrusted roles must not execute private chat RPCs';
  end if;
  if has_table_privilege('service_role','private.chat_conversations','SELECT')
     or has_table_privilege('service_role','private.chat_messages','SELECT')
     or has_table_privilege('service_role','private.chat_provider_consents','SELECT') then
    raise exception 'Service role must use the server-only RPC boundary, not direct table access';
  end if;
  if not has_function_privilege('service_role','public.get_chat_conversation(uuid,uuid)','EXECUTE')
     or not has_function_privilege('service_role','public.save_chat_message(uuid,uuid,uuid,uuid,text,text,text,text,text,integer,integer)','EXECUTE') then
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
select public.save_chat_message(
 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
 'ffffffff-ffff-4fff-8fff-ffffffffffff',
 'user','private owner message','completed','openai'
);

do $isolation$
declare
  v_result jsonb;
  v_deleted boolean;
begin
  if public.get_chat_conversation(
    'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
    'dddddddd-dddd-4ddd-8ddd-dddddddddddd'
  ) is not null then
    raise exception 'Cross-user conversation read was not isolated';
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
