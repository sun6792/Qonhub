<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v2.6.0 Phase 2: 五智能体执行流水表
     */
    public function up(): void
    {
        Schema::create('agent_executions', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->string('workflow_key', 64)->default('');            // 工作流标识 (如 scout_strategy_content_deploy_review)
            $table->string('current_state', 30)->default('idle');       // 状态机当前状态
            $table->string('current_agent', 30)->nullable();            // 当前正在执行的 Agent
            $table->json('input_data')->nullable();                     // 初始输入 (任务配置/触发参数)
            $table->json('scout_output')->nullable();                   // 侦察 Agent 输出
            $table->json('strategy_output')->nullable();                // 策略 Agent 输出
            $table->json('content_output')->nullable();                 // 内容 Agent 输出
            $table->json('deploy_output')->nullable();                  // 分发 Agent 输出
            $table->json('review_output')->nullable();                  // 复盘 Agent 输出
            $table->json('error_data')->nullable();                     // 错误信息
            $table->integer('retry_count')->default(0);                 // 当前阶段重试次数
            $table->integer('max_retries')->default(3);                 // 最大重试次数
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->bigInteger('triggered_by_admin_id')->nullable();    // 触发人
            $table->string('trigger_type', 30)->default('manual');      // manual/schedule/webhook
            $table->timestamps();

            $table->index(['workspace_id', 'current_state'], 'ae_ws_state_index');
            $table->index(['workspace_id', 'created_at'], 'ae_ws_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_executions');
    }
};
