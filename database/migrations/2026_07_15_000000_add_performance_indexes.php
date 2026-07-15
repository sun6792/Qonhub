<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * v2.5.x P1 性能优化：为高频查询字段添加索引，消除全表扫描。
     */
    public function up(): void
    {
        // ── articles 表：高频查询字段索引 ──────────────────
        Schema::table('articles', function (Blueprint $table): void {
            // 覆盖所有 published/draft 计数查询: WHERE status = 'published' AND deleted_at IS NULL
            $table->index(['status', 'deleted_at'], 'articles_status_deleted_at_index');

            // 覆盖 pending review 查询: WHERE review_status = 'pending' AND deleted_at IS NULL
            $table->index(['review_status', 'deleted_at'], 'articles_review_status_deleted_at_index');

            // 覆盖首页/站点列表排序: WHERE status = 'published' ORDER BY published_at DESC
            $table->index(['published_at', 'status'], 'articles_published_at_status_index');

            // 覆盖 today stats / created_at 范围查询
            $table->index(['created_at', 'deleted_at'], 'articles_created_at_deleted_at_index');

            // 覆盖 task-article 关联查询
            $table->index('task_id', 'articles_task_id_index');

            // 覆盖 AI 生成统计
            $table->index('is_ai_generated', 'articles_is_ai_generated_index');

            // 覆盖分类列表: WHERE category_id = ? AND status = 'published' ORDER BY published_at DESC
            $table->index(['category_id', 'status', 'published_at'], 'articles_category_status_published_at_index');
        });

        // ── tasks 表：状态查询索引 ──────────────────────────
        Schema::table('tasks', function (Blueprint $table): void {
            // Dashboard/Worker 频繁按 status 查询: WHERE status = 'active'
            $table->index('status', 'tasks_status_index');

            // 调度查询: WHERE schedule_enabled = true
            $table->index('schedule_enabled', 'tasks_schedule_enabled_index');

            // Worker 调度: WHERE next_run_at <= NOW()
            $table->index('next_run_at', 'tasks_next_run_at_index');
        });

        // ── task_runs 表：分析聚合索引 ─────────────────────
        Schema::table('task_runs', function (Blueprint $table): void {
            // 分析看板: WHERE status = ? GROUP BY DATE(created_at)
            $table->index(['status', 'created_at'], 'task_runs_status_created_at_index');
        });

        // ── ai_competitors 表：工作空间 + 状态索引 ──────────
        Schema::table('ai_competitors', function (Blueprint $table): void {
            // 竞品对比查询: WHERE workspace_id = ? AND status = 'active'
            $table->index(['workspace_id', 'status'], 'ai_competitors_workspace_status_index');
        });

        // ── view_logs 表：Dashboard 今日访客索引 ───────────
        if (Schema::hasTable('view_logs')) {
            Schema::table('view_logs', function (Blueprint $table): void {
                if (Schema::hasColumn('view_logs', 'created_at') && Schema::hasColumn('view_logs', 'method')) {
                    $table->index(['created_at', 'method'], 'view_logs_created_at_method_index');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table): void {
            $table->dropIndex('articles_status_deleted_at_index');
            $table->dropIndex('articles_review_status_deleted_at_index');
            $table->dropIndex('articles_published_at_status_index');
            $table->dropIndex('articles_created_at_deleted_at_index');
            $table->dropIndex('articles_task_id_index');
            $table->dropIndex('articles_is_ai_generated_index');
            $table->dropIndex('articles_category_status_published_at_index');
        });

        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropIndex('tasks_status_index');
            $table->dropIndex('tasks_schedule_enabled_index');
            $table->dropIndex('tasks_next_run_at_index');
        });

        Schema::table('task_runs', function (Blueprint $table): void {
            $table->dropIndex('task_runs_status_created_at_index');
        });

        Schema::table('ai_competitors', function (Blueprint $table): void {
            $table->dropIndex('ai_competitors_workspace_status_index');
        });

        if (Schema::hasTable('view_logs') && Schema::hasColumn('view_logs', 'created_at') && Schema::hasColumn('view_logs', 'method')) {
            Schema::table('view_logs', function (Blueprint $table): void {
                $table->dropIndex('view_logs_created_at_method_index');
            });
        }
    }
};
