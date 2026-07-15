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

        // 文心一言：专属适配器（非 OpenAI 兼容）
        if ($providerCode === 'ernie') {
            $adapter = new ErnieQianfanAdapter($providerRow, $this->apiKeyCrypto);
            if ($encryptedApiKey && $this->apiKeyCrypto) {
                $adapter->apiKey = $this->apiKeyCrypto->decrypt($encryptedApiKey);
            }
            return $adapter;
        }

        // 其余全部走 OpenAI 兼容适配器
        $adapter = new OpenAiCompatibleAdapter($providerRow, $this->apiKeyCrypto);
        if ($encryptedApiKey && $this->apiKeyCrypto) {
            $adapter->apiKey = $this->apiKeyCrypto->decrypt($encryptedApiKey);
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
}
