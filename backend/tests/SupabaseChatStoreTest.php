<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Ai/SupabaseChatStore.php';

use Kavoryn\Ai\SupabaseChatStore;
use Kavoryn\Ai\ChatStorageUnavailableException;

function chatStoreExpect(bool $condition, string $message): void {
    if (!$condition) { fwrite(STDERR, $message . PHP_EOL); exit(1); }
}

$user='a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a12';
$conversation='b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a13';
$turn='c0eebc99-9c0b-4ef8-bb6d-6bb9bd380a14';
$message='d0eebc99-9c0b-4ef8-bb6d-6bb9bd380a15';
$calls=[];
$mock=function(string $url,array $headers,string $body) use (&$calls,$user,$conversation,$message):array {
    $calls[]=['url'=>$url,'headers'=>$headers,'payload'=>json_decode($body,true,16,JSON_THROW_ON_ERROR)];
    chatStoreExpect(($headers['Authorization']??null)==='Bearer service-role-test','Service role auth must remain server-side.');
    $name=basename($url);
    return match($name) {
        'create_chat_conversation'=>['status'=>200,'body'=>json_encode(['id'=>$calls[array_key_last($calls)]['payload']['p_id'],'provider'=>'gemini','language'=>'ha'],JSON_THROW_ON_ERROR)],
        'consume_chat_rate_limit'=>['status'=>200,'body'=>'true'],
        'record_chat_provider_consent'=>['status'=>200,'body'=>json_encode($calls[array_key_last($calls)]['payload']['p_id'],JSON_THROW_ON_ERROR)],
        'has_chat_provider_consent'=>['status'=>200,'body'=>'true'],
        'revoke_chat_provider_consent'=>['status'=>200,'body'=>'1'],
        'save_chat_message'=>['status'=>200,'body'=>json_encode([
            'id'=>$message,'conversation_id'=>$conversation,'turn_id'=>$calls[array_key_last($calls)]['payload']['p_turn_id'],
            'role'=>'user','content'=>'Sannu','status'=>'completed','provider'=>'gemini'
        ],JSON_THROW_ON_ERROR)],
        'list_chat_conversations'=>['status'=>200,'body'=>'[]'],
        'get_chat_conversation'=>['status'=>200,'body'=>json_encode([
            'id'=>$conversation,'messages'=>[]
        ],JSON_THROW_ON_ERROR)],
        'delete_chat_conversation'=>['status'=>200,'body'=>'true'],
        default=>['status'=>404,'body'=>'{}'],
    };
};
$store=new SupabaseChatStore('https://example.supabase.co','secret-test','service-role-test',$mock);
$created=$store->createConversation($user,'gemini','ha','Hausa test');
chatStoreExpect(($created['provider']??null)==='gemini','Conversation provider should be returned.');
chatStoreExpect($store->consumeRateLimit($user),'Rate limit should allow an available request.');
$consentId=$store->recordConsent($user,'gemini','chat-policy-v1');
chatStoreExpect((bool)preg_match('/^[0-9a-f-]{36}$/',$consentId),'Consent should return a UUID.');
chatStoreExpect($store->hasConsent($user,'gemini'),'Active consent should be verified.');
chatStoreExpect($store->revokeConsent($user,'gemini')===1,'Revocation count should be returned.');
$saved=$store->saveMessage([
    'id'=>$message,'user_id'=>$user,'conversation_id'=>$conversation,'turn_id'=>$turn,
    'role'=>'user','content'=>'Sannu','status'=>'completed','provider'=>'gemini'
]);
chatStoreExpect(($saved['id']??null)===$message,'Saved message identity must be checked.');
chatStoreExpect($store->listConversations($user)===[],'Empty history should be represented as an array.');
chatStoreExpect(($store->getConversation($user,$conversation)['id']??null)===$conversation,'Conversation detail must match requested ID.');
chatStoreExpect($store->deleteConversation($user,$conversation),'Soft delete result should be true.');
$last=$calls[array_key_last($calls)];
chatStoreExpect(($last['payload']['p_user_id']??null)===$user,'Every operation must bind the verified user ID.');
chatStoreExpect(str_contains($last['url'],'delete_chat_conversation'),'Delete must use the owner-scoped RPC.');

$failed=new SupabaseChatStore('https://example.supabase.co','secret-test','service-role-test',static fn()=>['status'=>500,'body'=>'{}']);
try { $failed->hasConsent($user,'openai'); throw new RuntimeException('Storage failure must fail closed.'); }
catch(ChatStorageUnavailableException) {}

try { $store->hasConsent('not-a-uuid','gemini'); throw new RuntimeException('Invalid identity must be rejected.'); }
catch(InvalidArgumentException) {}


$baseMessage = [
    'id'=>$message,'user_id'=>$user,'conversation_id'=>$conversation,'turn_id'=>$turn,
    'role'=>'user','status'=>'completed','provider'=>'gemini',
];
$unicodeBoundary = $baseMessage;
$unicodeBoundary['content'] = str_repeat('é', 24000);
$store->saveMessage($unicodeBoundary);
$tooManyCharacters = $baseMessage;
$tooManyCharacters['content'] = str_repeat('é', 24001);
try {
    $store->saveMessage($tooManyCharacters);
    throw new RuntimeException('Messages over the SQL character limit must be rejected in PHP.');
} catch (InvalidArgumentException) {
}
$invalidUtf8 = $baseMessage;
$invalidUtf8['content'] = "invalid\xB1";
try {
    $store->saveMessage($invalidUtf8);
    throw new RuntimeException('Invalid UTF-8 message content must be rejected.');
} catch (InvalidArgumentException) {
}

echo "Chat storage tests passed.".PHP_EOL;
