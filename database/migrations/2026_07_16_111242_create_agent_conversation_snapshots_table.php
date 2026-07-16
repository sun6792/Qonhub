<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_conversation_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agent_execution_id')->nullable()->comment('关联的智能体执行记录');
            $table->unsignedBigInteger('workspace_id')->comment('工作空间');
            $table->unsignedBigInteger('task_id')->nullable()->comment('关联任务');
            $table->string('ai_provider_code', 50)->comment('AI平台标识 (deepseek/doubao/qwen/kimi/ernie)');
            $table->string('model_id', 100)->comment('模型ID (deepseek-v4-flash/...)');
            $table->text('prompt')->comment('发送给AI的完整prompt');
            $table->text('response_text')->nullable()->comment('AI返回的完整回答');
            $table->jsonb('cited_urls')->nullable()->comment('回答中引用的URL列表');
            $table->integer('geo_score')->nullable()->comment('品牌词相关性打分(0-100)');
            $table->boolean('brand_mentioned')->default(false)->comment('回答中是否提到品牌词');
            $table->string('brand_name', 200)->nullable()->comment('检测的品牌词');
            $table->timestamp('snapshot_at')->useCurrent();
            $table->timestamps();

            $table->index(['workspace_id', 'snapshot_at']);
            $table->index(['agent_execution_id']);
            $table->index(['ai_provider_code', 'snapshot_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_conversation_snapshots');
    }
};
