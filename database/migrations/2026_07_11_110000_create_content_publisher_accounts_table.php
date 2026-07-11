<?php

/**
 * 统一内容发布账号池。
 *
 * 设计原则：
 * 1. 严格 Workspace 租户隔离，workspace_id 为必填外键
 * 2. 凭证复用 ApiKeyCrypto（AES-256-CBC）加密，与现有 AI 密钥/分发密钥/客户 Cookie 体系一致
 * 3. 支持三种凭证类型：oauth_token / cookie / password，覆盖全渠道
 * 4. 健康检测、过期管理、故障自动轮换的状态机
 *
 * 关联关系：
 * - workspace_id → workspaces（租户隔离）
 * - 对应 EnterpriseAnchorCertification 的 anchor_platform_key（B2B/媒体锚点打通）
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_publisher_accounts', function (Blueprint $table) {
            $table->id();
            // ── 租户与平台标识 ──
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('platform_key', 50)->comment('平台标识（与 EnterpriseAnchorService 对齐）');
            $table->string('platform_type', 30)->default('self_media')->comment('平台大类: self_media/news_media/b2b');
            $table->string('platform_name', 100)->default('')->comment('平台显示名称');

            // ── 账号标识 ──
            $table->string('account_name', 100)->default('')->comment('账号显示名（如"品尚运动-主号"）');
            $table->string('account_id_on_platform', 200)->default('')->comment('平台上的账号ID/UID');

            // ── 凭证（AES-256-CBC 加密存储） ──
            $table->string('credential_type', 20)->default('cookie')->comment('凭证类型: oauth_token/cookie/password');
            $table->text('credential_ciphertext')->nullable()->comment('ApiKeyCrypto 加密的凭证密文');
            $table->text('credential_metadata')->nullable()->comment('凭证附加信息 JSON（如 oauth refresh_token/expires_at 等）');

            // ── 状态与健康 ──
            $table->string('status', 20)->default('active')->comment('active/disabled/expired/locked/error');
            $table->string('health_status', 20)->default('unknown')->comment('healthy/degraded/unhealthy/unknown');
            $table->timestamp('last_health_check_at')->nullable();
            $table->string('last_error_message', 500)->default('')->comment('最近一次错误信息');
            $table->unsignedInteger('consecutive_failures')->default(0)->comment('连续失败次数，用于自动轮换');
            $table->unsignedInteger('daily_publish_count')->default(0)->comment('今日已发布次数');
            $table->timestamp('daily_reset_at')->nullable()->comment('每日计数重置时间');

            // ── 发布控制 ──
            $table->unsignedInteger('publish_interval_seconds')->default(120)->comment('单账号最小发布间隔');
            $table->unsignedInteger('daily_publish_limit')->default(20)->comment('单账号单日发布上限');
            $table->unsignedInteger('total_publish_count')->default(0)->comment('累计发布次数');
            $table->timestamp('last_publish_at')->nullable()->comment('最近发布时间');
            $table->timestamp('next_available_at')->nullable()->comment('下次可发布时间');

            // ── RPA/代理绑定 ──
            $table->string('bound_ip', 45)->default('')->comment('绑定的代理出口IP');
            $table->string('bound_fingerprint_id', 64)->default('')->comment('浏览器指纹ID');
            $table->boolean('requires_rpa')->default(false)->comment('是否需要RPA浏览器引擎');

            // ── OAuth 特有字段 ──
            $table->string('oauth_app_id', 200)->default('')->comment('OAuth App ID');
            $table->text('oauth_extra')->nullable()->comment('OAuth 配置 JSON（redirect_uri/scopes 等）');

            // ── 运营管理 ──
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins');
            $table->unsignedInteger('sort_order')->default(0)->comment('排序权重');
            $table->text('notes')->nullable()->comment('运营备注');

            $table->timestamps();
            $table->softDeletes();

            // 同一 workspace 下同平台账号名唯一
            $table->unique(['workspace_id', 'platform_key', 'account_name'], 'uq_pub_account_ws_platform_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_publisher_accounts');
    }
};
