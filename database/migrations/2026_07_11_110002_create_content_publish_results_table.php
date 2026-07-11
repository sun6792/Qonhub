<?php

/**
 * 内容发布结果明细表。
 *
 * 每篇文章在单个平台的单次发布尝试记录一条。
 * 成功发布后自动更新 EnterpriseAnchorCertification（锚点打通）。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_publish_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_publish_task_id')->constrained('content_publish_tasks')->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('article_id')->nullable()->constrained('articles')->nullOnDelete();

            // ── 平台与账号 ──
            $table->string('platform_key', 50);
            $table->string('platform_type', 30)->default('self_media');
            $table->foreignId('publisher_account_id')->nullable()->constrained('content_publisher_accounts')->nullOnDelete();

            // ── 状态与结果 ──
            $table->string('status', 20)->default('pending')->comment('pending/queued/sending/success/failed/retrying/reviewing');
            $table->string('remote_article_id', 200)->default('')->comment('目标平台的文章ID');
            $table->string('remote_article_url', 500)->default('')->comment('目标平台的文章链接');
            $table->string('remote_status', 50)->default('')->comment('目标平台返回的状态（如审核中/已发布/驳回）');
            $table->text('remote_response')->nullable()->comment('目标平台原始响应 JSON');

            // ── 错误信息 ──
            $table->string('error_code', 50)->default('');
            $table->text('error_message')->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->unsignedInteger('max_retries')->default(3);

            // ── 发送的内容快照 ──
            $table->string('sent_title', 300)->default('')->comment('实际发送的标题（可能经改写）');
            $table->text('sent_content_preview')->nullable()->comment('发送正文前500字快照');

            // ── 锚点打通 ──
            $table->foreignId('anchor_certification_id')->nullable()->comment('关联的 EnterpriseAnchorCertification 记录');

            // ── 执行信息 ──
            $table->string('execution_engine', 20)->default('api')->comment('api/rpa');
            $table->string('executor_ip', 45)->default('')->comment('实际使用的出口IP');
            $table->unsignedInteger('duration_ms')->default(0)->comment('执行耗时毫秒');

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['platform_key', 'status']);
            $table->index(['content_publish_task_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_publish_results');
    }
};
