-- Remove the obsolete five-argument consent RPC.
-- The backend now calls only the six-argument overload with an explicit purpose.
-- Keeping both signatures can make PostgREST RPC resolution ambiguous and leaves
-- a legacy path that cannot distinguish explanation consent from Q&A consent.

revoke all on function public.record_ai_processing_consent(uuid, uuid, uuid, text, text)
  from public, anon, authenticated, service_role;

drop function if exists public.record_ai_processing_consent(uuid, uuid, uuid, text, text);
