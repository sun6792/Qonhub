<?php

namespace App\Services\AI\Adapters;

/**
 * OpenAI 兼容适配器 — 一次性覆盖 DeepSeek、火山方舟、通义千问、Kimi、智谱GLM、硅基流动。
 * 这六家全部兼容 OpenAI Chat Completions API，只需配置不同的 base_url。
 *
 * 各厂商细微差异通过构造函数传入的 $config['config_json'] 处理：
 *   - 火山方舟：config_json.authorization_header = 'Authorization' (标准)
 *   - 智谱GLM：config_json.authorization_header = 'Authorization' (标准, JWT生成在Adapter内)
 *   - 通义千问：config_json.extra_headers = ['X-DashScope-SSE' => 'enable']
 */
class OpenAiCompatibleAdapter extends BaseLlmAdapter
{
    public function chat(string $modelId, array $messages, array $options): array
    {
        $baseUrl = rtrim($this->providerConfig['api_base_url'] ?? '', '/');
        // 兼容 /v1、/v3 等版本路径：已有 /vN 则直接拼 /chat/completions
        $url = preg_match('#/v\d+$#', $baseUrl)
            ? "{$baseUrl}/chat/completions"
            : "{$baseUrl}/v1/chat/completions";

        $body = array_merge(
            [
                'model' => $modelId,
                'messages' => $messages,
                'stream' => false,
            ],
            $this->normalizeOptions($options)
        );

        $response = $this->sendRequestWithAuth($url, $body);

        return $this->normalizeResponse($response);
    }

    public function testConnection(string $modelId): bool
    {
        try {
            $this->chat($modelId, [
                ['role' => 'user', 'content' => 'Reply with OK.'],
            ], ['max_tokens' => 8]);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    protected function normalizeOptions(array $options): array
    {
        return [
            'max_tokens' => $options['max_tokens'] ?? 4096,
            'temperature' => $options['temperature'] ?? 0.7,
            'stream' => $options['stream'] ?? false,
        ];
    }

    protected function sendRequestWithAuth(string $url, array $body): array
    {
        $http = $this->httpClient();

        // 智谱 GLM 需动态生成 JWT Token
        if (($this->providerConfig['provider_code'] ?? '') === 'zhipu') {
            $apiKey = $this->apiKey ?? '';
            if (! empty($apiKey)) {
                $parts = explode('.', $apiKey);
                if (count($parts) === 2) {
                    $http = $http->withToken($this->generateZhipuJwt($parts[0], $parts[1]));
                } else {
                    $http = $http->withToken($apiKey);
                }
            }
        } else {
            $http = $http->withToken($this->apiKey ?? '');
        }

        // 厂商特定额外 headers
        $configJson = $this->providerConfig['config_json'] ?? [];
        if (is_string($configJson)) {
            $configJson = json_decode($configJson, true) ?: [];
        }
        foreach ($configJson['extra_headers'] ?? [] as $key => $value) {
            $http = $http->withHeader($key, $value);
        }

        // 直接发送（使用带Token的$http，不新建客户端）
        $response = $http->post($url, $body);

        if ($response->failed()) {
            throw new \RuntimeException(
                "LLM API error: HTTP {$response->status()} — " . ($response->body() ?: 'empty body')
            );
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new \RuntimeException('LLM API returned non-JSON response');
        }

        if (isset($json['error'])) {
            throw new \RuntimeException(
                'LLM API error: ' . ($json['error']['message'] ?? json_encode($json['error']))
            );
        }

        return $json;
    }

    /**
     * 智谱 GLM JWT Token 生成（API Key 拆分为 id.secret）。
     */
    private function generateZhipuJwt(string $id, string $secret): string
    {
        $header = self::base64urlEncode(json_encode(['alg' => 'HS256', 'sign_type' => 'SIGN']));
        $now = time();
        $payload = self::base64urlEncode(json_encode([
            'api_key' => $id,
            'exp' => $now + 3600,
            'timestamp' => $now,
        ]));
        $signature = self::base64urlEncode(
            hash_hmac('sha256', "{$header}.{$payload}", $secret, true)
        );

        return "{$header}.{$payload}.{$signature}";
    }

    private static function base64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
