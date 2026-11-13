<?php

declare(strict_types=1);

namespace Kavoryn\Ai;

final class ChatStorageUnavailableException extends \RuntimeException {}

final class SupabaseChatStore
{
    /** @param callable(string,array<string,string>,string):array{status:int,body:string}|null $http */
    public function __construct(
        private readonly string $supabaseUrl,
        private readonly string $secretKey,
        private readonly string $serviceRoleJwt,
        private readonly mixed $http = null,
    ) {
        if (parse_url($supabaseUrl, PHP_URL_SCHEME) !== 'https' ||
            $secretKey === '' || $serviceRoleJwt === '') {
            throw new \InvalidArgumentException('Chat storage is not configured.');
        }
    }

    public function newId(): string
    {
        return $this->uuid();
    }

    public function consumeRateLimit(string $userId, int $limit = 20, int $windowSeconds = 60): bool
    {
        $this->assertUuid($userId);
        $result = $this->rpc('consume_chat_rate_limit', [
            'p_user_id'=>$userId, 'p_limit'=>max(1,min($limit,100)),
            'p_window_seconds'=>max(1,min($windowSeconds,3600)),
        ]);
        if (!is_bool($result)) {
            throw new ChatStorageUnavailableException('Invalid chat rate-limit response.');
        }
        return $result;
    }

    public function createConversation(string $userId, string $provider, string $language, ?string $title = null): array
    {
        $this->assertUuid($userId);
        $this->assertProvider($provider);
        if (!in_array($language, ['en','ha','ar','fr'], true) ||
            ($title !== null && strlen($title) > 120)) {
            throw new \\InvalidArgumentException('Invalid chat conversation details.');
        }
        $result = $this->rpc('create_chat_conversation', [
            'p_id'=>$this->uuid(), 'p_user_id'=>$userId,
            'p_title'=>$title, 'p_provider'=>$provider, 'p_language'=>$language,
        ]);
        if (!is_array($result) || !is_string($result['id'] ?? null)) {
            throw new ChatStorageUnavailableException('Invalid conversation creation response.');
        }
        return $result;
    }

    public function recordConsent(string $userId, string $provider, string $policyVersion): string
    {
        $this->assertUuid($userId);
        $this->assertProvider($provider);
        if ($policyVersion === '' || strlen($policyVersion) > 40) {
            throw new \InvalidArgumentException('Invalid chat consent policy version.');
        }
        $id = $this->uuid();
        $result = $this->rpc('record_chat_provider_consent', [
            'p_id' => $id, 'p_user_id' => $userId,
            'p_provider' => $provider, 'p_policy_version' => $policyVersion,
        ]);
        if ($result !== $id) {
            throw new ChatStorageUnavailableException('Invalid consent response.');
        }
        return $id;
    }

    public function hasConsent(string $userId, string $provider): bool
    {
        $this->assertUuid($userId);
        $this->assertProvider($provider);
        $result = $this->rpc('has_chat_provider_consent', [
            'p_user_id' => $userId, 'p_provider' => $provider,
        ]);
        if (!is_bool($result)) {
            throw new ChatStorageUnavailableException('Invalid consent verification response.');
        }
        return $result;
    }

    public function revokeConsent(string $userId, string $provider): int
    {
        $this->assertUuid($userId);
        $this->assertProvider($provider);
        $result = $this->rpc('revoke_chat_provider_consent', [
            'p_user_id' => $userId, 'p_provider' => $provider,
        ]);
        if (!is_int($result) || $result < 0) {
            throw new ChatStorageUnavailableException('Invalid consent revocation response.');
        }
        return $result;
    }

    /** @param array<string,mixed> $message */
    public function saveMessage(array $message): array
    {
        foreach (['id','user_id','conversation_id','turn_id'] as $key) {
            if (!is_string($message[$key] ?? null)) {
                throw new \InvalidArgumentException('Invalid chat message identity.');
            }
            $this->assertUuid($message[$key]);
        }
        $role = $message['role'] ?? null;
        $status = $message['status'] ?? null;
        $provider = $message['provider'] ?? null;
        $content = $message['content'] ?? null;
        $model = $message['model'] ?? null;
        $inputTokens = $message['input_tokens'] ?? null;
        $outputTokens = $message['output_tokens'] ?? null;
        if (!in_array($role, ['user','assistant'], true) ||
            !in_array($status, ['pending','streaming','completed','failed','cancelled'], true) ||
            !is_string($content) || trim($content) === '' || strlen($content) > 96000 ||
            !is_string($provider)) {
            throw new \InvalidArgumentException('Invalid chat message.');
        }
        $this->assertProvider($provider);
        if ($model !== null && (!is_string($model) || strlen($model) > 120)) {
            throw new \InvalidArgumentException('Invalid chat model.');
        }
        foreach ([$inputTokens,$outputTokens] as $tokens) {
            if ($tokens !== null && (!is_int($tokens) || $tokens < 0)) {
                throw new \InvalidArgumentException('Invalid token usage.');
            }
        }
        $result = $this->rpc('save_chat_message', [
            'p_id'=>$message['id'], 'p_user_id'=>$message['user_id'],
            'p_conversation_id'=>$message['conversation_id'], 'p_turn_id'=>$message['turn_id'],
            'p_role'=>$role, 'p_content'=>$content, 'p_status'=>$status,
            'p_provider'=>$provider, 'p_model'=>$model,
            'p_input_tokens'=>$inputTokens, 'p_output_tokens'=>$outputTokens,
        ]);
        if (!is_array($result) || ($result['id'] ?? null) !== $message['id'] ||
            ($result['conversation_id'] ?? null) !== $message['conversation_id']) {
            throw new ChatStorageUnavailableException('Invalid saved message response.');
        }
        return $result;
    }

    public function listConversations(string $userId, int $limit = 30): array
    {
        $this->assertUuid($userId);
        $result = $this->rpc('list_chat_conversations', [
            'p_user_id'=>$userId, 'p_limit'=>max(1,min($limit,100)),
        ]);
        if (!is_array($result) || !array_is_list($result)) {
            throw new ChatStorageUnavailableException('Invalid conversation list response.');
        }
        return $result;
    }

    public function getConversation(string $userId, string $conversationId): ?array
    {
        $this->assertUuid($userId);
        $this->assertUuid($conversationId);
        $result = $this->rpc('get_chat_conversation', [
            'p_user_id'=>$userId, 'p_conversation_id'=>$conversationId,
        ]);
        if ($result === null) return null;
        if (!is_array($result) || ($result['id'] ?? null) !== $conversationId ||
            !is_array($result['messages'] ?? null)) {
            throw new ChatStorageUnavailableException('Invalid conversation response.');
        }
        return $result;
    }

    public function deleteConversation(string $userId, string $conversationId): bool
    {
        $this->assertUuid($userId);
        $this->assertUuid($conversationId);
        $result = $this->rpc('delete_chat_conversation', [
            'p_user_id'=>$userId, 'p_conversation_id'=>$conversationId,
        ]);
        if (!is_bool($result)) {
            throw new ChatStorageUnavailableException('Invalid conversation deletion response.');
        }
        return $result;
    }

    private function rpc(string $name, array $payload): mixed
    {
        $url = rtrim($this->supabaseUrl, '/') . '/rest/v1/rpc/' . $name;
        $headers = [
            'apikey'=>$this->secretKey,
            'Authorization'=>'Bearer '.$this->serviceRoleJwt,
            'Content-Type'=>'application/json',
            'Accept'=>'application/json',
        ];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        try {
            if ($this->http !== null) {
                $response = ($this->http)($url, $headers, $body);
            } else {
                if (!function_exists('curl_init')) throw new ChatStorageUnavailableException();
                $curl = curl_init($url);
                if ($curl === false) throw new ChatStorageUnavailableException();
                $lines = [];
                foreach ($headers as $key=>$value) $lines[] = $key.': '.$value;
                curl_setopt_array($curl, [
                    CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
                    CURLOPT_POSTFIELDS=>$body, CURLOPT_HTTPHEADER=>$lines,
                    CURLOPT_CONNECTTIMEOUT=>3, CURLOPT_TIMEOUT=>8,
                    CURLOPT_FOLLOWLOCATION=>false, CURLOPT_SSL_VERIFYPEER=>true,
                    CURLOPT_SSL_VERIFYHOST=>2,
                ]);
                $responseBody = curl_exec($curl);
                $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
                $error = curl_errno($curl);
                curl_close($curl);
                if ($responseBody === false || $error !== 0) throw new ChatStorageUnavailableException();
                $response = ['status'=>$status,'body'=>(string)$responseBody];
            }
        } catch (ChatStorageUnavailableException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new ChatStorageUnavailableException('Chat storage is temporarily unavailable.');
        }
        if (!is_array($response) || !is_int($response['status'] ?? null) ||
            !is_string($response['body'] ?? null) ||
            $response['status'] < 200 || $response['status'] >= 300) {
            throw new ChatStorageUnavailableException('Chat storage rejected the operation.');
        }
        try {
            return json_decode(trim($response['body']), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ChatStorageUnavailableException('Invalid chat storage response.');
        }
    }

    private function assertProvider(string $provider): void
    {
        if (!in_array($provider, ['openai','gemini'], true)) {
            throw new \InvalidArgumentException('Unsupported chat provider.');
        }
    }

    private function assertUuid(string $value): void
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value)) {
            throw new \InvalidArgumentException('Invalid chat identity.');
        }
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s',substr($hex,0,8),substr($hex,8,4),substr($hex,12,4),substr($hex,16,4),substr($hex,20,12));
    }
}
