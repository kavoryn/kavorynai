<?php

declare(strict_types=1);

namespace Kavoryn\Ai;

final class SupabaseAiQuestionRateLimiter
{
    /** @param callable(string,array<string,string>,string):array{status:int,body:string}|null $http */
    public function __construct(
        private readonly string $supabaseUrl,
        private readonly string $secretKey,
        private readonly string $serviceRoleJwt,
        private readonly mixed $http = null,
    ) {
        if (parse_url($supabaseUrl, PHP_URL_SCHEME) !== 'https' || $secretKey === '' || $serviceRoleJwt === '') {
            throw new \InvalidArgumentException('AI question rate limiting is not configured.');
        }
    }

    public function allow(string $userId): bool
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $userId)) {
            throw new \InvalidArgumentException('Invalid rate-limit identity.');
        }
        $url=rtrim($this->supabaseUrl,'/').'/rest/v1/rpc/consume_ai_question_rate_limit';
        $headers=['apikey'=>$this->secretKey,'Authorization'=>'Bearer '.$this->serviceRoleJwt,'Content-Type'=>'application/json','Accept'=>'application/json'];
        $body=json_encode(['p_user_id'=>$userId,'p_limit'=>10,'p_window_seconds'=>60],JSON_THROW_ON_ERROR);
        try {
            if($this->http!==null) $response=($this->http)($url,$headers,$body);
            else {
                if(!function_exists('curl_init')) throw new AiQuestionRateLimitUnavailableException();
                $ch=curl_init($url);if($ch===false)throw new AiQuestionRateLimitUnavailableException();
                $lines=[];foreach($headers as $k=>$v)$lines[]=$k.': '.$v;
                curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>$lines,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>8,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
                $rb=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$errno=curl_errno($ch);curl_close($ch);
                if($rb===false||$errno!==0)throw new AiQuestionRateLimitUnavailableException();
                $response=['status'=>$status,'body'=>(string)$rb];
            }
        } catch(AiQuestionRateLimitUnavailableException $e){throw $e;} catch(\Throwable){throw new AiQuestionRateLimitUnavailableException();}
        if(!is_array($response)||!is_int($response['status']??null)||$response['status']<200||$response['status']>=300||!is_string($response['body']??null)) throw new AiQuestionRateLimitUnavailableException();
        try{$allowed=json_decode($response['body'],true,8,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new AiQuestionRateLimitUnavailableException();}
        if(!is_bool($allowed)) throw new AiQuestionRateLimitUnavailableException();
        return $allowed;
    }
}

final class AiQuestionRateLimitUnavailableException extends \RuntimeException {}
