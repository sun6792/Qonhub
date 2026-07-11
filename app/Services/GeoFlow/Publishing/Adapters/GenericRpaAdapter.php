<?php

namespace App\Services\GeoFlow\Publishing\Adapters;

use App\Models\Article;
use App\Services\GeoFlow\Publishing\BasePlatformAdapter;
use RuntimeException;

/**
 * 通用 RPA 浏览器自动化适配器。
 *
 * 通过调用外部无头浏览器引擎（Headless Chrome / Puppeteer / Playwright）
 * 实现无 API 平台的内容发布。
 *
 * RPA 引擎作为独立服务部署，适配器通过 HTTP/gRPC 与引擎通信。
 * 当前声明接口，RPA 引擎实现另行部署。
 */
class GenericRpaAdapter extends BasePlatformAdapter
{
    private ?RpaEngineClient $rpaClient = null;

    /**
     * 获取 RPA 客户端（延迟初始化）。
     */
    protected function rpa(): RpaEngineClient
    {
        if ($this->rpaClient === null) {
            $this->rpaClient = app(RpaEngineClient::class);
        }

        return $this->rpaClient;
    }

    public function platformKey(): string
    {
        return $this->account->platform_key;
    }

    /**
     * 健康检测：检查 RPA 引擎是否可达。
     */
    public function checkHealth(): array
    {
        return $this->rpa()->healthCheck();
    }

    /**
     * 内容发布（通过 RPA 引擎执行）。
     */
    protected function doPublish(Article $article, array $adaptedContent): array
    {
        $credential = $this->getDecryptedCredential();

        return $this->rpa()->executeTask([
            'platform' => $this->platformKey(),
            'action' => 'publish_article',
            'account' => [
                'username' => $this->account->account_id_on_platform,
                'credential' => $credential,
                'credential_type' => $this->account->credential_type,
            ],
            'content' => [
                'title' => $adaptedContent['title'],
                'body' => $adaptedContent['body'],
                'images' => $adaptedContent['images'] ?? [],
            ],
            'options' => [
                'bound_ip' => $this->account->bound_ip,
                'fingerprint_id' => $this->account->bound_fingerprint_id,
                'timeout_seconds' => 120,
            ],
        ]);
    }

    // ── B2B 企业认证入驻（v3.0 新增） ────────────────────

    /**
     * 通用 RPA 企业认证入驻。
     *
     * 作为所有无特殊逻辑 B2B 平台的兜底认证方案，
     * 将企业档案数据通过 RPA 引擎提交到目标平台。
     */
    protected function doRegister(
        \App\Models\EnterpriseProfile $profile,
        array $enterpriseData
    ): array {
        return $this->rpa()->executeTask([
            'platform' => $this->platformKey(),
            'action' => 'register_and_certify',
            'account' => [
                'username' => $enterpriseData['register_username'] ?? '',
                'credential' => $enterpriseData['register_credential'] ?? '',
                'credential_type' => $this->account->credential_type,
            ],
            'enterprise' => $enterpriseData,
            'options' => [
                'bound_ip' => $this->account->bound_ip,
                'fingerprint_id' => $this->account->bound_fingerprint_id,
                'timeout_seconds' => 180,
            ],
        ]);
    }

    // ── RPA 特有：发布前准备 ────────────────────────────

    protected function validate(Article $article): void
    {
        parent::validate($article);

        // RPA 模式额外检查：代理 IP 是否绑定
        if (empty($this->account->bound_ip)) {
            throw new RuntimeException('RPA 发布需要绑定代理 IP，请先在账号管理中配置');
        }
    }

    protected function adaptFormat(array $content): array
    {
        // RPA 模式直接发 HTML
        $content['body'] = (string) ($content['body'] ?? '');

        return $content;
    }

    // ── 辅助 ─────────────────────────────────────────────

    private function getDecryptedCredential(): string
    {
        $service = app(\App\Services\GeoFlow\Publishing\AccountPoolService::class);

        return $service->decryptCredential($this->account);
    }

    private function fail(string $message, array $raw): array
    {
        return [
            'success' => false,
            'remote_id' => '',
            'remote_url' => '',
            'remote_status' => 'error',
            'raw_response' => array_merge($raw, ['error' => $message]),
        ];
    }
}
