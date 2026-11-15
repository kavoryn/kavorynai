-- Disposable PostgreSQL fixture approximating current Supabase public defaults.
-- This file is CI-only; it is not a production migration.

alter role service_role bypassrls;
create role supabase_admin nologin;
grant create on schema public to supabase_admin;

create table public.audit_events (
  id uuid primary key default gen_random_uuid(),
  actor_user_id uuid null,
  event_type text not null,
  feature text null,
  metadata jsonb not null default '{}'::jsonb,
  created_at timestamptz not null default now()
);
alter table public.audit_events enable row level security;

create table public.profiles (
  user_id uuid primary key,
  display_name text null
);
alter table public.profiles enable row level security;
create policy profiles_select_own on public.profiles
  for select to authenticated
  using (user_id = auth.uid());

insert into public.profiles (user_id, display_name) values
  ('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'Owner A'),
  ('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'Owner B');

grant usage on schema auth to anon, authenticated;
grant execute on function auth.uid() to anon, authenticated;

-- Reproduce the current overly broad ACL on existing public tables.
grant all on table public.audit_events, public.profiles
  to anon, authenticated, service_role;

-- Reproduce Supabase's current default grants for both common table owners.
alter default privileges for role postgres in schema public
  grant all on tables to anon, authenticated, service_role;
alter default privileges for role supabase_admin in schema public
  grant all on tables to anon, authenticated, service_role;
