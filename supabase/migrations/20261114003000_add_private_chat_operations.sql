-- Server-only chat persistence operations. All user IDs are supplied by the
-- authenticated PHP API after Supabase bearer verification; clients never call
-- these SECURITY DEFINER functions directly.
create or replace function public.record_chat_provider_consent(
  p_id uuid, p_user_id uuid, p_provider text, p_policy_version text
) returns uuid
language plpgsql security definer set search_path = ''
as $function$
declare v_id uuid;
begin
  if p_id is null or p_user_id is null
     or p_provider not in ('openai','gemini')
     or p_policy_version is null or char_length(p_policy_version) not between 1 and 40 then
    raise exception 'Invalid chat consent parameters';
  end if;
  insert into private.chat_provider_consents(id,user_id,provider,purpose,policy_version)
  values(p_id,p_user_id,p_provider,'chat.message',p_policy_version)
  returning id into v_id;
  return v_id;
end;
$function$;

create or replace function public.has_chat_provider_consent(
  p_user_id uuid, p_provider text
) returns boolean
language sql stable security definer set search_path = ''
as $function$
  select exists (
    select 1 from private.chat_provider_consents c
    where c.user_id=p_user_id and c.provider=p_provider
      and c.purpose='chat.message' and c.revoked_at is null
  );
$function$;

create or replace function public.revoke_chat_provider_consent(
  p_user_id uuid, p_provider text
) returns integer
language plpgsql security definer set search_path = ''
as $function$
declare v_count integer;
begin
  update private.chat_provider_consents
    set revoked_at=pg_catalog.now()
    where user_id=p_user_id and provider=p_provider
      and purpose='chat.message' and revoked_at is null;
  get diagnostics v_count = row_count;
  return v_count;
end;
$function$;

create or replace function public.save_chat_message(
  p_id uuid, p_user_id uuid, p_conversation_id uuid, p_turn_id uuid,
  p_role text, p_content text, p_status text,
  p_provider text, p_model text default null,
  p_input_tokens integer default null, p_output_tokens integer default null
) returns jsonb
language plpgsql security definer set search_path = ''
as $function$
declare
  v_conversation private.chat_conversations;
  v_message private.chat_messages;
begin
  if p_id is null or p_user_id is null or p_conversation_id is null or p_turn_id is null
     or p_role not in ('user','assistant')
     or p_status not in ('pending','streaming','completed','failed','cancelled')
     or p_content is null or char_length(p_content)>24000
     or p_provider not in ('openai','gemini')
     or (p_model is not null and char_length(p_model)>120)
     or (p_input_tokens is not null and p_input_tokens<0)
     or (p_output_tokens is not null and p_output_tokens<0) then
    raise exception 'Invalid chat message parameters';
  end if;

  select * into v_conversation from private.chat_conversations
   where id=p_conversation_id and user_id=p_user_id and deleted_at is null
   for update;
  if not found or v_conversation.provider<>p_provider then
    raise exception 'Chat conversation not found';
  end if;

  if p_role='user' then
    if p_status<>'completed' or not public.has_chat_provider_consent(p_user_id,p_provider) then
      raise exception 'Active provider consent required';
    end if;
  elsif not exists (
    select 1 from private.chat_messages m
    where m.conversation_id=p_conversation_id and m.user_id=p_user_id
      and m.turn_id=p_turn_id and m.role='user'
  ) then
    raise exception 'User turn not found';
  end if;

  insert into private.chat_messages(
    id,conversation_id,user_id,turn_id,role,content,status,provider,model,
    input_tokens,output_tokens,completed_at
  ) values(
    p_id,p_conversation_id,p_user_id,p_turn_id,p_role,p_content,p_status,p_provider,p_model,
    p_input_tokens,p_output_tokens,
    case when p_status in ('completed','failed','cancelled') then pg_catalog.now() else null end
  )
  on conflict(conversation_id,user_id,turn_id,role) do update set
    status=excluded.status,
    model=coalesce(excluded.model,private.chat_messages.model),
    input_tokens=coalesce(excluded.input_tokens,private.chat_messages.input_tokens),
    output_tokens=coalesce(excluded.output_tokens,private.chat_messages.output_tokens),
    completed_at=excluded.completed_at
  where private.chat_messages.content=excluded.content
    and private.chat_messages.id=excluded.id
    and (
      private.chat_messages.status=excluded.status
      or (private.chat_messages.status='pending' and excluded.status in ('streaming','completed','failed','cancelled'))
      or (private.chat_messages.status='streaming' and excluded.status in ('completed','failed','cancelled'))
    )
  returning * into v_message;

  if not found then
    raise exception 'Chat message idempotency conflict';
  end if;

  update private.chat_conversations set updated_at=pg_catalog.now()
   where id=p_conversation_id and user_id=p_user_id;

  return pg_catalog.jsonb_build_object(
    'id',v_message.id,'conversation_id',v_message.conversation_id,
    'turn_id',v_message.turn_id,'role',v_message.role,'content',v_message.content,
    'status',v_message.status,'provider',v_message.provider,'model',v_message.model,
    'created_at',v_message.created_at,'completed_at',v_message.completed_at
  );
end;
$function$;

create or replace function public.list_chat_conversations(p_user_id uuid, p_limit integer default 30)
returns jsonb
language sql stable security definer set search_path = ''
as $function$
  select coalesce(pg_catalog.jsonb_agg(pg_catalog.jsonb_build_object(
    'id',c.id,'title',c.title,'provider',c.provider,'language',c.language,
    'created_at',c.created_at,'updated_at',c.updated_at
  ) order by c.updated_at desc),'[]'::jsonb)
  from (
    select * from private.chat_conversations
    where user_id=p_user_id and deleted_at is null
    order by updated_at desc limit least(greatest(coalesce(p_limit,30),1),100)
  ) c;
$function$;

create or replace function public.get_chat_conversation(p_user_id uuid,p_conversation_id uuid)
returns jsonb
language sql stable security definer set search_path = ''
as $function$
  select pg_catalog.jsonb_build_object(
    'id',c.id,'title',c.title,'provider',c.provider,'language',c.language,
    'created_at',c.created_at,'updated_at',c.updated_at,
    'messages',coalesce((
      select pg_catalog.jsonb_agg(pg_catalog.jsonb_build_object(
        'id',m.id,'turn_id',m.turn_id,'role',m.role,'content',m.content,
        'status',m.status,'provider',m.provider,'model',m.model,
        'created_at',m.created_at,'completed_at',m.completed_at
      ) order by m.created_at,m.id)
      from private.chat_messages m
      where m.conversation_id=c.id and m.user_id=p_user_id
    ),'[]'::jsonb)
  )
  from private.chat_conversations c
  where c.id=p_conversation_id and c.user_id=p_user_id and c.deleted_at is null;
$function$;

create or replace function public.delete_chat_conversation(p_user_id uuid,p_conversation_id uuid)
returns boolean
language plpgsql security definer set search_path = ''
as $function$
declare v_count integer;
begin
  update private.chat_conversations set deleted_at=pg_catalog.now(),updated_at=pg_catalog.now()
   where id=p_conversation_id and user_id=p_user_id and deleted_at is null;
  get diagnostics v_count = row_count;
  return v_count=1;
end;
$function$;

revoke all on function public.record_chat_provider_consent(uuid,uuid,text,text) from public,anon,authenticated;
revoke all on function public.has_chat_provider_consent(uuid,text) from public,anon,authenticated;
revoke all on function public.revoke_chat_provider_consent(uuid,text) from public,anon,authenticated;
revoke all on function public.save_chat_message(uuid,uuid,uuid,uuid,text,text,text,text,text,integer,integer) from public,anon,authenticated;
revoke all on function public.list_chat_conversations(uuid,integer) from public,anon,authenticated;
revoke all on function public.get_chat_conversation(uuid,uuid) from public,anon,authenticated;
revoke all on function public.delete_chat_conversation(uuid,uuid) from public,anon,authenticated;
grant execute on function public.record_chat_provider_consent(uuid,uuid,text,text) to service_role;
grant execute on function public.has_chat_provider_consent(uuid,text) to service_role;
grant execute on function public.revoke_chat_provider_consent(uuid,text) to service_role;
grant execute on function public.save_chat_message(uuid,uuid,uuid,uuid,text,text,text,text,text,integer,integer) to service_role;
grant execute on function public.list_chat_conversations(uuid,integer) to service_role;
grant execute on function public.get_chat_conversation(uuid,uuid) to service_role;
grant execute on function public.delete_chat_conversation(uuid,uuid) to service_role;
