-- Assertions after 20261115001000_lock_down_public_schema_default_privileges.sql.

create table public.future_postgres_table (id uuid primary key);
set role supabase_admin;
create table public.future_supabase_admin_table (id uuid primary key);
reset role;

do $$
declare
  v_role text;
  v_table text;
  v_privilege text;
  v_roles text[] := array['anon', 'authenticated', 'service_role'];
  v_tables text[] := array[
    'public.audit_events',
    'public.profiles',
    'public.future_postgres_table',
    'public.future_supabase_admin_table'
  ];
  v_non_row_privileges text[] := array['TRUNCATE', 'REFERENCES', 'TRIGGER'];
begin
  foreach v_role in array v_roles loop
    foreach v_table in array v_tables loop
      foreach v_privilege in array v_non_row_privileges loop
        if pg_catalog.has_table_privilege(v_role, v_table, v_privilege) then
          raise exception '% unexpectedly retains % on %', v_role, v_privilege, v_table;
        end if;
      end loop;
    end loop;
  end loop;

  if pg_catalog.has_table_privilege('anon', 'public.audit_events', 'SELECT')
     or pg_catalog.has_table_privilege('anon', 'public.audit_events', 'INSERT')
     or pg_catalog.has_table_privilege('anon', 'public.audit_events', 'UPDATE')
     or pg_catalog.has_table_privilege('anon', 'public.audit_events', 'DELETE')
     or pg_catalog.has_table_privilege('authenticated', 'public.audit_events', 'SELECT')
     or pg_catalog.has_table_privilege('authenticated', 'public.audit_events', 'INSERT')
     or pg_catalog.has_table_privilege('authenticated', 'public.audit_events', 'UPDATE')
     or pg_catalog.has_table_privilege('authenticated', 'public.audit_events', 'DELETE') then
    raise exception 'audit_events is still directly accessible to client roles';
  end if;

  if not pg_catalog.has_table_privilege('service_role', 'public.audit_events', 'INSERT') then
    raise exception 'service_role must retain append-only audit insertion';
  end if;

  if pg_catalog.has_table_privilege('service_role', 'public.audit_events', 'SELECT')
     or pg_catalog.has_table_privilege('service_role', 'public.audit_events', 'UPDATE')
     or pg_catalog.has_table_privilege('service_role', 'public.audit_events', 'DELETE')
     or pg_catalog.has_table_privilege('service_role', 'public.audit_events', 'TRUNCATE') then
    raise exception 'service_role audit_events access is not append-only';
  end if;

  if not pg_catalog.has_table_privilege('authenticated', 'public.profiles', 'SELECT') then
    raise exception 'authenticated lost the required own-profile SELECT grant';
  end if;

  if not exists (
    select 1 from pg_catalog.pg_class c
    join pg_catalog.pg_namespace n on n.oid = c.relnamespace
    where n.nspname = 'public' and c.relname = 'profiles' and c.relrowsecurity
  ) then
    raise exception 'profiles RLS was unexpectedly disabled';
  end if;

  if not exists (
    select 1 from pg_catalog.pg_policies
    where schemaname = 'public' and tablename = 'profiles'
      and policyname = 'profiles_select_own' and roles = array['authenticated']::name[]
  ) then
    raise exception 'profiles owner policy was unexpectedly removed';
  end if;
end;
$$;

-- RLS remains effective: anonymous users see no profile rows.
set role anon;
do $$
begin
  if exists (select 1 from public.profiles) then
    raise exception 'anonymous role can read profile rows';
  end if;
end;
$$;
reset role;

-- Authenticated owners see only rows whose user_id matches auth.uid().
set role authenticated;
set request.jwt.claim.sub = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
do $$
begin
  if (select count(*) from public.profiles) <> 1
     or not exists (select 1 from public.profiles where user_id = auth.uid()) then
    raise exception 'authenticated owner-scoped profile policy failed';
  end if;
end;
$$;
reset role;

-- The server role can append an audit row but cannot read or mutate audit history.
set role service_role;
insert into public.audit_events (event_type, feature) values ('ci.test', 'security');
reset role;
