<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Ai/SupabaseAiQuestionRateLimiter.php';

use Kavoryn\Ai\SupabaseAiQuestionRateLimiter;
use Kavoryn\Ai\AiQuestionRateLimitUnavailableException;

function rateExpect(bool $ok,string $message):void
{
    if(!$ok){fwrite(STDERR,$message.PHP_EOL);exit(1);}
}
$user='a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a12';
$limiter=new SupabaseAiQuestionRateLimiter(
    'https://example.supabase.co','secret-test','service-role-test',
    function(string $url,array $headers,string $body):array {
        $payload=json_decode($body,true,16,JSON_THROW_ON_ERROR);
        rateExpect(($payload['p_limit']??null)===10,'Expected ten questions per window.');
        rateExpect(($payload['p_window_seconds']??null)===60,'Expected sixty-second window.');
        rateExpect(($payload['p_user_id']??null)==='a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a12','Limit must bind authenticated user.');
        return ['status'=>200,'body'=>'true'];
    }
);
rateExpect($limiter->allow($user),'Allowed request should pass.');
$denied=new SupabaseAiQuestionRateLimiter('https://example.supabase.co','secret-test','service-role-test',static fn()=>['status'=>200,'body'=>'false']);
rateExpect(!$denied->allow($user),'Rate-limited request should be denied.');
$unavailable=new SupabaseAiQuestionRateLimiter('https://example.supabase.co','secret-test','service-role-test',static fn()=>['status'=>503,'body'=>'{}']);
try{$unavailable->allow($user);throw new RuntimeException('Unavailable rate-limit storage must fail closed.');}
catch(AiQuestionRateLimitUnavailableException){}
echo "AI question rate limiter tests passed.".PHP_EOL;
