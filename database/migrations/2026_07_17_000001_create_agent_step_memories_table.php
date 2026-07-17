<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_step_memories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('execution_id');
            $table->string('agent_type', 50)->comment('scout/strategy/content/deploy/review');
            $table->text('input_digest')->nullable()->comment('压缩后的输入摘要');
            $table->text('output_digest')->nullable()->comment('压缩后的输出摘要');
            $table->boolean('success')->default(true);
            $table->jsonb('metrics')->nullable()->comment('{geo_score, mention_rate, channels_published, ...}');
            $table->jsonb('tags')->nullable()->comment('[industry, keywords, platforms, ...]');
            $table->string('pattern_key', 100)->nullable()->comment('跨任务可复用模式的哈希标识');
            $table->timestamps();

            $table->index(['workspace_id', 'agent_type']);
            $table->index('pattern_key');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_step_memories');
    }
};
