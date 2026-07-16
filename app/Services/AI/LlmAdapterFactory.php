<?php

namespace App\Services\AI;

use App\Models\AiModelProvider;
use App\Services\AI\Adapters\BaseLlmAdapter;
use App\Services\AI\Adapters\DeepSeekAdapter;
use App\Services\AI\Adapters\ErnieQianfanAdapter;
use App\Services\AI\Adapters\OpenAiCompatibleAdapter;
use App\Support\GeoFlow\ApiKeyCrypto;
use RuntimeException;

/**
 * 大模型适配器工厂 — 按 provider_code 创建对应的 Adapter。
 */
class LlmAdapterFactory
{
    /**
     * @param  array{provider_code:string, adapter_class:string, api_key:string|null}|null  $encryptedApiKey
     */
    public function __construct(
        private readonly ?ApiKeyCrypto $apiKeyCrypto = null,
    ) {}

    /**
     * 根据 ai_model_providers 行 + ai_models 行创建适配器。
     */
    public function create(array $providerRow, ?string $encryptedApiKey = null): BaseLlmAdapter
    {
        $providerCode = $providerRow['provider_code'] ?? '';

        // 使用 provider 配置的 adapter_class，默认 OpenAI 兼容
        $adapterClass = $providerRow['adapter_class'] ?? OpenAiCompatibleAdapter::class;
        $adapter = new $adapterClass($providerRow, $this->apiKeyCrypto);
        if ($encryptedApiKey) {
            $adapter->apiKey = $this->resolveApiKey($encryptedApiKey);
        }
        return $adapter;
    }

    /**
     * 按 provider_code 创建适配器（含 API Key 解密）。
     * 自动从数据库查询 provider 配置。
     */
    public function createByCode(string $providerCode, string $encryptedApiKey, string $modelId = ''): BaseLlmAdapter
    {
        $provider = AiModelProvider::query()
            ->where('provider_code', $providerCode)
            ->where('is_active', true)
            ->first();

        if (! $provider) {
            throw new RuntimeException("AI 供应商不存在或已禁用: {$providerCode}");
        }

        return $this->create($provider->toArray(), $encryptedApiKey);
    }

    /**
     * 智能解析 API Key：已解密的直接用，加密的才解密。
     */
    private function resolveApiKey(string $key): string
    {
        if (! str_starts_with($key, 'enc:v1:')) {
            return $key; // 已解密（上层预处理）
        }
        return app(\App\Support\GeoFlow\ApiKeyCrypto::class)->decrypt($key);
    }
}
