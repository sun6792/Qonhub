<?php

/**
 * 统一内容发布任务表。
 *
 * 一次"一键发布"操作创建一条任务，包含多篇文章 × 多个平台的发布计划。
 * 具体每篇文章在每个平台的发布结果由 content_publish_results 表追踪。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_publish_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();

            // ── 任务标识 ──
            $table->string('task_name', 200)->default('')->comment('任务名称');
            $table->string('status', 20)->default('pending')->comment('pending/queued/running/completed/partial_failed/failed/cancelled');
            $table->unsignedInteger('progress_percent')->default(0)->comment('发布进度百分比');

            // ── 内容来源（复用 ContentArmory 弹药库） ──
            $table->foreignId('armory_publish_log_id')->nullable()->comment('关联弹药库发布记录');
            $table->json('article_ids')->nullable()->comment('本次发布的文章 ID 列表');
            $table->json('platform_keys')->nullable()->comment('目标平台 key 列表');

            // ── 统计 ──
            $table->unsignedInteger('total_articles')->default(0);
            $table->unsignedInteger('total_platforms')->default(0);
            $table->unsignedInteger('total_jobs')->default(0)->comment('总分发作业数 (articles × platforms × accounts)');
            $table->unsignedInteger('completed_jobs')->default(0);
            $table->unsignedInteger('failed_jobs')->default(0);

            // ── 发布控制 ──
            $table->boolean('use_smart_scheduling')->default(true)->comment('是否使用智能错峰调度');
            $table->boolean('use_content_rewrite')->default(true)->comment('是否启用内容差异化改写');
            $table->string('rewrite_mode', 20)->default('per_platform')->comment('改写模式: per_platform/per_account/none');

            // ── 风控参数 ──
            $table->unsignedInteger('min_publish_interval_seconds')->default(60)->comment('最小发布间隔');
            $table->unsignedInteger('max_concurrent_accounts')->default(3)->comment('最大并发账号数');

            // ── 操作用户 ──
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins');
            $table->foreignId('requested_by_client_user_id')->nullable()->comment('客户提交的发布请求');

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_publish_tasks');
    }
};
