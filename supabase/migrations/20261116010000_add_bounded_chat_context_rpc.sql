-- Bounded, owner-scoped context retrieval for stateless OpenAI chat.
-- Returns only completed user/assistant pairs from the authenticated owner's
-- active conversation. No provider-side state or storage is enabled here.

create or replace function public.get_recent_chat_context(
  p_user_id uuid,
  p_conversation_id uuid,
  p_message_limit integer default 30,
  p_character_limit integer default 32000
) returns jsonb
language sql
stable
security definer
set search_path = ''
as $function$
  with owned_conversation as (
    select c.id
    from private.chat_conversations c
    where c.id = p_conversation_id
      and c.user_id = p_user_id
      and c.deleted_at is null
  ),
  completed_turns as (
    select m.turn_id
    from private.chat_messages m
    join owned_conversation c on c.id = m.conversation_id
    where m.user_id = p_user_id
      and m.status = 'completed'
    group by m.turn_id
    having count(*) filter (where m.role = 'user' and m.status = 'completed') = 1
       and count(*) filter (where m.role = 'assistant' and m.status = 'completed') = 1
  ),
  recent_messages as (
    select m.id, m.turn_id, m.role, m.content, m.created_at
    from private.chat_messages m
    join owned_conversation c on c.id = m.conversation_id
    join completed_turns t on t.turn_id = m.turn_id
    where m.user_id = p_user_id
      and m.status = 'completed'
    order by m.created_at desc, m.id desc
    limit least(greatest(coalesce(p_message_limit, 30), 2), 30)
  ),
  bounded_messages as (
    select r.*,
      sum(pg_catalog.char_length(r.content)) over (
        order by r.created_at desc, r.id desc
        rows between unbounded preceding and current row
      ) as running_characters
    from recent_messages r
  )
  select coalesce(
    pg_catalog.jsonb_agg(
      pg_catalog.jsonb_build_object('role', b.role, 'content', b.content)
      order by b.created_at asc, b.id asc
    ),
    '[]'::jsonb
  )
  from bounded_messages b
  where b.running_characters <= least(greatest(coalesce(p_character_limit, 32000), 1), 32000);
$function$;

revoke all on function public.get_recent_chat_context(uuid, uuid, integer, integer)
  from public, anon, authenticated;
grant execute on function public.get_recent_chat_context(uuid, uuid, integer, integer)
  to service_role;
