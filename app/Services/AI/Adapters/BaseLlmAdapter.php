<?php

namespace App\Services\AI\Adapters;

use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * 大模型供应商抽象基类。
 * 每个厂商 Adapter 实现此基类，抹平参数差异。
 *
 * Phase 1: chat() / testConnection()
 * Phase 3: chatWithTools() — Function Calling 支持
 */
abstract class BaseLlmAdapter
{
    public string $apiKey = '';

    public function __construct(
        protected readonly array $providerConfig,  // ai_model_providers 行
        protected readonly ?ApiKeyCrypto $apiKeyCrypto = null,
    ) {
        // API Key 由工厂在创建后注入
    }

    /**
     * 发送纯文本对话请求。
     */
    abstract public function chat(string $modelId, array $messages, array $options): array;

    /**
     * 测试连接是否可用。
     */
    abstract public function testConnection(string $modelId): bool;

    /**
     * 将内部统一参数转为厂商特定参数。
     *
     * 子类应覆盖此方法以适配各厂商参数差异，基础实现安全透传所有已知扩展参数。
     */
    protected function normalizeOptions(array $options): array
    {
        $safe = [
            'max_tokens'  => $options['max_tokens'] ?? 4096,
            'temperature' => $options['temperature'] ?? 0.7,
        ];

        // 透传扩展参数（联网搜索 / Function Calling / 标准 OpenAI 参数）
        foreach ([
            'tools', 'tool_choice', 'enable_search', 'web_search',
            'top_p', 'frequency_penalty', 'presence_penalty',
            'stream', 'stop', 'response_format',
        ] as $key) {
            if (array_key_exists($key, $options)) {
                $safe[$key] = $options[$key];
            }
        }

        return $safe;
    }

    /**
     * 将厂商特定响应转为统一格式。
     */
    protected function normalizeResponse(array $raw): array
    {
        return [
            'text' => $raw['choices'][0]['message']['content'] ?? '',
            'tokens_used' => $raw['usage']['total_tokens'] ?? 0,
            'model' => $raw['model'] ?? '',
        ];
    }

    /**
     * 解密 API Key（复用现有组件）。
     */
    protected function decryptApiKey(string $encryptedKey): string
    {
        if ($this->apiKeyCrypto) {
            return $this->apiKeyCrypto->decrypt($encryptedKey);
        }
        // Fallback: 如果未注入 ApiKeyCrypto（测试场景），直接返回
        return $encryptedKey;
    }

    /**
     * 构建 HTTP 客户端（复用 OutboundHttpProxy）。
     */
    protected function httpClient(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::timeout(120)
            ->connectTimeout(10)
            ->withHeader('Content-Type', 'application/json');
    }

    /**
     * 发送 HTTP 请求并处理异常。
     */
    /**
     * 发送 HTTP 请求。
     *
     * @param  \Illuminate\Http\Client\PendingRequest|null  $http  可选：已配置认证的客户端
     */
    protected function sendRequest(string $url, array $body, ?\Illuminate\Http\Client\PendingRequest $http = null): array
    {
        $client = $http ?? $this->httpClient();
        $response = $client->post($url, $body);

        if ($response->failed()) {
            throw new RuntimeException(
                "LLM API error: HTTP {$response->status()} — " . ($response->body() ?: 'empty body')
            );
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('LLM API returned non-JSON response');
        }

        if (isset($json['error'])) {
            throw new RuntimeException(
                'LLM API error: ' . ($json['error']['message'] ?? json_encode($json['error']))
            );
        }

        return $json;
    }
}
