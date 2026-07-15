<?php

namespace App\Services\GeoFlow\Publishing;

use App\Models\Article;
use App\Models\ContentPublishResult;
use App\Models\ContentPublisherAccount;
use App\Support\GeoFlow\GeoPlatformRules;
use RuntimeException;

/**
 * 统一平台发布适配器基类。
 *
 * 所有渠道（自媒体/媒体/B2B）的发布逻辑均继承此类，
 * 实现 publish() 标准方法，复用内容改写、格式适配、合规校验。
 *
 * 子类只需实现：
 * 1. doPublish()        — 实际发布逻辑（API 请求 或 RPA 脚本调用）
 * 2. checkHealth()      — 健康检测（可选）
 * 3. platformKey()      — 平台标识
 */
abstract class BasePlatformAdapter
{
    protected ContentPublisherAccount $account;
    protected GeoPlatformRules $rules;

    public function __construct(ContentPublisherAccount $account)
    {
        $this->account = $account;
        $this->rules = new GeoPlatformRules();
    }

    /**
     * 平台唯一标识，子类必须实现。
     */
    abstract public function platformKey(): string;

    /**
     * 实际发布逻辑，子类必须实现。
     *
     * @return array{success: bool, remote_id: string, remote_url: string, remote_status: string, raw_response: array}
     */
    abstract protected function doPublish(Article $article, array $adaptedContent): array;

    /**
     * 健康检测，子类可选覆盖。
     *
     * @return array{healthy: bool, message: string}
     */
    public function checkHealth(): array
    {
        return ['healthy' => true, 'message' => 'ok'];
    }

    /**
     * 统一的发布入口（模板方法模式）。
     *
     * 流程：前置校验 → 内容改写 → 格式适配 → 合规检查 → 执行发布 → 记录结果
     */
    final public function publish(Article $article, ContentPublishResult $result): ContentPublishResult
    {
        $startMs = (int) (microtime(true) * 1000);

        try {
            // 1. 前置校验
            $this->validate($article);

            // 2. 内容改写（复用 GeoPlatformRules 规则引擎）
            $adaptedContent = $this->adaptContent($article);

            // 3. 格式适配
            $adaptedContent = $this->adaptFormat($adaptedContent);

            // 4. 合规检查（敏感词 + 平台违禁词）
            $this->checkCompliance($adaptedContent);

            // 5. 执行发布
            $response = $this->doPublish($article, $adaptedContent);

            // 6. 记录成功
            $durationMs = (int) (microtime(true) * 1000) - $startMs;

            $result->forceFill([
                'status' => $response['success'] ? 'success' : 'failed',
                'remote_article_id' => $response['remote_id'] ?? '',
                'remote_article_url' => $response['remote_url'] ?? '',
                'remote_status' => $response['remote_status'] ?? '',
                'remote_response' => $response['raw_response'] ?? [],
                'sent_title' => $adaptedContent['title'] ?? '',
                'sent_content_preview' => mb_substr($adaptedContent['body'] ?? '', 0, 500),
                'executor_ip' => $this->account->bound_ip ?? '',
                'duration_ms' => $durationMs,
                'sent_at' => now(),
                'completed_at' => now(),
            ])->save();

            if ($response['success']) {
                $this->account->recordPublish();
                $this->syncAnchorCertification($result, $response);
            } else {
                throw new RuntimeException($response['raw_response']['error'] ?? '发布失败');
            }

            return $result;

        } catch (\Throwable $e) {
            $durationMs = (int) (microtime(true) * 1000) - $startMs;

            $result->forceFill([
                'status' => 'failed',
                'error_code' => $this->classifyError($e),
                'error_message' => $e->getMessage(),
                'retry_count' => $result->retry_count + 1,
                'completed_at' => now(),
                'duration_ms' => $durationMs,
            ])->save();

            $this->account->recordFailure($e->getMessage());

            throw $e;
        }
    }

    // ── B2B 企业认证入驻（v3.0 新增，与 publish 并列） ──────

    /**
     * B2B 企业认证入驻的标准流程。
     *
     * 客户只需一份 EnterpriseProfile，点"一键认证"后，
     * 系统自动将企业信息批量提交到所有 B2B 平台完成注册认证。
     *
     * 流程：校验企业档案 → 字段适配 → 执行注册 → 抓取店铺 URL → 同步锚点
     *
     * 与 publish() 完全并列，不修改不破坏原有发布逻辑。
     */
    final public function register(ContentPublishResult $result): ContentPublishResult
    {
        $startMs = (int) (microtime(true) * 1000);

        try {
            // 1. 获取企业档案
            $profile = \App\Models\EnterpriseProfile::query()
                ->where('workspace_id', (int) $result->workspace_id)
                ->first();

            if (! $profile || empty($profile->company_full_name)) {
                throw new \RuntimeException('请先完善企业档案（公司全称必填）后再进行认证');
            }

            // 2. 字段适配：EnterpriseProfile → 平台注册字段
            $enterpriseData = $this->adaptEnterpriseProfile($profile);

            // 3. 执行认证（由子类实现）
            $registerResult = $this->doRegister($profile, $enterpriseData);

            // 4. 记录结果
            $durationMs = (int) (microtime(true) * 1000) - $startMs;

            $result->forceFill([
                'status' => $registerResult['success'] ? 'success' : 'failed',
                'certify_url' => $registerResult['shop_url'] ?? '',
                'remote_article_url' => $registerResult['shop_url'] ?? '',
                'remote_status' => $registerResult['success'] ? 'certified' : 'error',
                'remote_response' => $registerResult['raw_response'] ?? [],
                'execution_engine' => $registerResult['engine'] ?? ($this->account->requires_rpa ? 'rpa' : 'api'),
                'executor_ip' => $this->account->bound_ip ?? '',
                'duration_ms' => $durationMs,
                'sent_at' => now(),
                'completed_at' => now(),
            ])->save();

            if ($registerResult['success']) {
                $this->account->recordPublish();

                // 5. 同步锚点（复用现有锚点体系）
                $this->syncAnchorCertification($result, [
                    'remote_url' => $registerResult['shop_url'] ?? '',
                    'success' => true,
                    'remote_id' => $registerResult['account_id'] ?? '',
                ]);

                // 6. 一键认证完成后，把同一 workspace 下其余该平台的 pending 认证结果也标记成功
                $this->cascadeCertifySuccess($result);
            }

            return $result;

        } catch (\Throwable $e) {
            $durationMs = (int) (microtime(true) * 1000) - $startMs;

            $result->forceFill([
                'status' => 'failed',
                'error_code' => $this->classifyError($e),
                'error_message' => $e->getMessage(),
                'retry_count' => $result->retry_count + 1,
                'completed_at' => now(),
                'duration_ms' => $durationMs,
            ])->save();

            $this->account->recordFailure($e->getMessage());

            throw $e;
        }
    }

    /**
     * 实际认证执行逻辑，由各 B2B 子类覆盖实现。
     *
     * 默认抛出异常：自媒体/媒体类适配器不支持认证，只有 B2B 适配器覆盖此方法。
     *
     * @return array{success: bool, shop_url: string, account_id: string, raw_response: array, engine: string}
     */
    protected function doRegister(
        \App\Models\EnterpriseProfile $profile,
        array $enterpriseData
    ): array {
        throw new \RuntimeException('当前平台 ' . $this->platformKey() . ' 不支持企业认证入驻，请使用 B2B 类适配器');
    }

    /**
     * 将 EnterpriseProfile 字段映射为平台要求的注册字段。
     *
     * 子类可覆盖此方法以适配特定平台字段名。
     */
    protected function adaptEnterpriseProfile(\App\Models\EnterpriseProfile $profile): array
    {
        return [
            'company_name' => $profile->company_full_name,
            'company_short_name' => $profile->company_short_name ?: $profile->company_full_name,
            'credit_code' => $profile->unified_social_credit_code,
            'legal_person' => $profile->legal_person,
            'registered_capital' => $profile->registered_capital,
            'establishment_date' => $profile->establishment_date?->toDateString(),
            'business_scope' => $profile->business_scope,
            'province' => $profile->company_province,
            'city' => $profile->company_city,
            'address' => $profile->company_address,
            'phone' => $profile->company_phone,
            'email' => $profile->company_email,
            'website' => $profile->company_website,
            'industry' => $profile->industry,
            'products' => is_array($profile->products_services)
                ? implode('、', $profile->products_services)
                : (string) ($profile->products_services ?? ''),
            // 平台通用：用规范后的账号密码
            'register_username' => $this->account->account_id_on_platform,
            'register_credential' => $this->getDecryptedCredential(),
        ];
    }

    /**
     * 一键认证完成后，级联更新同 workspace 同平台的其他 pending 认证结果。
     */
    protected function cascadeCertifySuccess(ContentPublishResult $result): void
    {
        \App\Models\ContentPublishResult::query()
            ->where('workspace_id', (int) $result->workspace_id)
            ->where('platform_key', $result->platform_key)
            ->where('status', 'pending')
            ->where('id', '!=', (int) $result->id)
            ->update([
                'status' => 'success',
                'certify_url' => $result->certify_url,
                'remote_article_url' => $result->remote_article_url,
                'completed_at' => now(),
            ]);
    }

    // ── 内容适配（可覆盖） ─────────────────────────────────

    /**
     * 内容差异化改写。默认复用 GeoPlatformRules，子类可添加平台特有规则。
     */
    protected function adaptContent(Article $article): array
    {
        $title = $article->title;
        $body = $article->content;

        // 复用现有规则引擎做平台级改写
        $title = $this->rules->adaptTitle($title, $this->platformKey());
        $body = $this->rules->adaptBody($body, $this->platformKey());

        return [
            'title' => $title,
            'body' => $body,
            'excerpt' => $article->excerpt ?? mb_substr(strip_tags((string) $body), 0, 120),
            'keywords' => $article->keywords ?? '',
            'category_id' => $article->category_id,
            'images' => $this->extractImages($article),
        ];
    }

    /**
     * 格式适配（标题长度、正文格式等）。
     */
    protected function adaptFormat(array $content): array
    {
        // 默认：标题不超过30字、正文 Markdown→HTML
        $content['title'] = mb_substr($content['title'], 0, 30);
        $content['body'] = $this->markdownToPlain($content['body']);

        return $content;
    }

    /**
     * 合规检查（敏感词 + 平台规则）。
     */
    protected function checkCompliance(array $content): void
    {
        // 复用现有敏感词检查
        $sensitiveWords = \App\Models\SensitiveWord::query()->pluck('word')->all();
        $text = $content['title'] . ' ' . $content['body'];

        foreach ($sensitiveWords as $word) {
            if (mb_strpos($text, $word) !== false) {
                throw new RuntimeException("内容包含敏感词: {$word}");
            }
        }
    }

    // ── 锚点打通 ──────────────────────────────────────────

    /**
     * 发布成功后自动更新企业锚点认证记录。
     */
    protected function syncAnchorCertification(ContentPublishResult $result, array $response): void
    {
        $profile = \App\Models\EnterpriseProfile::query()
            ->where('workspace_id', (int) $result->workspace_id)
            ->first();

        if (! $profile) {
            return;
        }

        $cert = \App\Models\EnterpriseAnchorCertification::query()
            ->where('enterprise_profile_id', (int) $profile->id)
            ->where('anchor_platform_key', $this->platformKey())
            ->first();

        if ($cert) {
            $cert->forceFill([
                'certification_status' => 'certified',
                'certified_at' => $cert->certified_at ?? now(),
                'platform_page_url' => $response['remote_url'] ?: $cert->platform_page_url,
            ])->save();
        } else {
            $cert = \App\Models\EnterpriseAnchorCertification::query()->create([
                'enterprise_profile_id' => (int) $profile->id,
                'anchor_platform_key' => $this->platformKey(),
                'certification_status' => 'certified',
                'certified_at' => now(),
                'platform_page_url' => $response['remote_url'] ?? '',
            ]);
        }

        $result->forceFill(['anchor_certification_id' => (int) $cert->id])->save();
    }

    // ── 工具方法 ──────────────────────────────────────────

    /**
     * 统一的失败响应格式，供子类 publish() 方法调用。
     */
    protected function failResponse(string $message, array $raw = []): array
    {
        return [
            'success' => false,
            'remote_id' => '',
            'remote_url' => '',
            'remote_status' => 'error',
            'raw_response' => array_merge($raw, ['error' => $message]),
        ];
    }

    protected function getDecryptedCredential(): string
    {
        $service = app(\App\Services\GeoFlow\Publishing\AccountPoolService::class);

        return $service->decryptCredential($this->account);
    }

    protected function validate(Article $article): void
    {
        if (empty(trim($article->title ?? ''))) {
            throw new RuntimeException('文章标题为空');
        }
        if (empty(trim($article->content ?? ''))) {
            throw new RuntimeException('文章正文为空');
        }
        if (! $this->account->isAvailable()) {
            throw new RuntimeException("账号不可用: {$this->account->account_name}");
        }
    }

    protected function extractImages(Article $article): array
    {
        return $article->images()
            ->with('image')
            ->get()
            ->map(fn ($ai) => $ai->image?->file_path)
            ->filter()
            ->values()
            ->all();
    }

    protected function markdownToPlain(string $markdown): string
    {
        // 简单 Markdown → 纯文本（实际项目用 ArticleHtmlPresenter）
        $text = strip_tags((string) $markdown);
        $text = preg_replace('/[#*`>\[\]()!_~]/', '', $text);

        return trim((string) $text);
    }

    protected function classifyError(\Throwable $e): string
    {
        $msg = $e->getMessage();

        if (str_contains($msg, 'timeout') || str_contains($msg, 'Timed out')) {
            return 'timeout';
        }
        if (str_contains($msg, '401') || str_contains($msg, '403') || str_contains($msg, 'Unauthorized')) {
            return 'auth_error';
        }
        if (str_contains($msg, '敏感词') || str_contains($msg, '违规')) {
            return 'content_rejected';
        }
        if (str_contains($msg, '验证码') || str_contains($msg, 'captcha')) {
            return 'captcha_required';
        }
        if (str_contains($msg, '限流') || str_contains($msg, '429') || str_contains($msg, 'rate')) {
            return 'rate_limited';
        }

        return 'unknown';
    }
}
