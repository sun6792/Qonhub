<?php

namespace App\Services\GeoFlow\Publishing\Adapters;

use App\Models\Article;
use App\Services\GeoFlow\Publishing\AccountPoolService;
use Illuminate\Support\Facades\Http;

/**
 * 百家号 Cookie 托管发布适配器。
 *
 * 复用现有 ClientPlatformAccount 的 Cookie 凭证体系，
 * 通过 RPA 引擎模拟浏览器登录后发布。
 */
class BaijiahaoCookieAdapter extends GenericRpaAdapter
{
    public function platformKey(): string
    {
        return 'baijiahao';
    }

    /**
     * 同时兼容从旧 ClientPlatformAccount 迁移的账号。
     */
    protected function doPublish(Article $article, array $adaptedContent): array
    {
        // 尝试从旧 ClientPlatformAccount 获取 Cookie
        $legacyAccount = \App\Models\ClientPlatformAccount::query()
            ->where('workspace_id', (int) $this->account->workspace_id)
            ->where('platform_key', 'baijiahao')
            ->where('status', 'active')
            ->first();

        if ($legacyAccount && ! empty($legacyAccount->credential_ciphertext)) {
            // 将旧 Cookie 迁移到新账号池
            $accountPool = app(AccountPoolService::class);
            $cookie = $accountPool->decryptCredential(
                // 临时适配：用旧体系的凭证
                new class($legacyAccount) extends \App\Models\ContentPublisherAccount
                {
                    public function __construct($legacy) { $this->credential_ciphertext = $legacy->credential_ciphertext; }
                }
            );
            // 回填到新账号
            $accountPool->updateCredential($this->account, $cookie);
        }

        return parent::doPublish($article, $adaptedContent);
    }

    protected function adaptFormat(array $content): array
    {
        $content['title'] = mb_substr($content['title'], 0, 30);
        // 百家号要求标题不含特殊字符
        $content['title'] = preg_replace('/[!！？?。.]$/u', '', $content['title']);

        return $content;
    }
}
