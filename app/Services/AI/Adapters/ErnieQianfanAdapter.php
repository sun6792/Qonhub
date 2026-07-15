<?php

namespace App\Services\AI\Adapters;

/**
 * 文心一言专属适配器 — 非 OpenAI 兼容，需要单独适配。
 *
 * Auth: client_id + client_secret → access_token (需自动续期)
 * 参数名: max_output_tokens (不是 max_tokens)
 * 流式: SSE 格式与 OpenAI 不同
 */
class ErnieQianfanAdapter extends BaseLlmAdapter
{
    private ?string $accessToken = null;
    private int $tokenExpiresAt = 0;

    public function chat(string $modelId, array $messages, array $options): array
    {
        $token = $this->getAccessToken();
        $url = "https://aip.baidubce.com/rpc/2.0/ai_custom/v1/wenxinworkshop/chat/{$modelId}?access_token={$token}";

        $body = array_merge(
            ['messages' => $messages],
            $this->normalizeOptions($options)
        );

        $response = $this->sendRequest($url, $body);

        return $this->normalizeResponse($response);
    }

    public function testConnection(string $modelId): bool
    {
        try {
            $this->getAccessToken();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    protected function normalizeOptions(array $options): array
    {
        return [
            'max_output_tokens' => $options['max_tokens'] ?? 2048,
            'temperature' => $options['temperature'] ?? 0.7,
            'top_p' => $options['top_p'] ?? 0.8,
            // 文心一言使用 penalty_score，不是 frequency_penalty
            'penalty_score' => 1.0,
        ];
    }

    protected function normalizeResponse(array $raw): array
    {
        return [
            'text' => $raw['result'] ?? '',
            'tokens_used' => ($raw['usage']['total_tokens'] ?? 0),
            'model' => $raw['model'] ?? '',
        ];
    }

    /**
     * 获取或刷新 access_token。
     * 文心一言认证：POST https://aip.baidubce.com/oauth/2.0/token
     */
    private function getAccessToken(): string
    {
        if ($this->accessToken && time() < $this->tokenExpiresAt - 60) {
            return $this->accessToken;
        }

        $apiKey = $this->apiKey ?? '';
        // 文心一言 API Key 格式: client_id::client_secret
        $parts = explode('::', $apiKey);

        if (count($parts) !== 2) {
            throw new \RuntimeException(
                '文心一言 API Key 格式错误。请使用 client_id::client_secret 格式'
            );
        }

        $response = $this->httpClient()->asForm()->post(
            'https://aip.baidubce.com/oauth/2.0/token',
            [
                'grant_type' => 'client_credentials',
                'client_id' => $parts[0],
                'client_secret' => $parts[1],
            ]
        );

        if ($response->failed()) {
            throw new \RuntimeException('文心一言 access_token 获取失败: ' . $response->body());
        }

        $data = $response->json();
        $this->accessToken = $data['access_token'] ?? '';
        $this->tokenExpiresAt = time() + ($data['expires_in'] ?? 7200);

        return $this->accessToken;
    }
}
