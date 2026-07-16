<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_cited_sources', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('snapshot_id')->nullable()->comment('关联的对话快照');
            $table->string('ai_platform', 50)->comment('AI平台 (deepseek/doubao/qwen/...)');
            $table->text('url');
            $table->string('domain', 200)->nullable();
            $table->string('title', 500)->nullable()->comment('来源页面标题');
            $table->text('excerpt')->nullable()->comment('来源页面摘要');
            $table->integer('mention_position')->nullable()->comment('在AI回答中的引用位置(第几句)');
            $table->timestamps();

            $table->index(['workspace_id', 'ai_platform']);
            $table->index('snapshot_id');
            $table->unique(['snapshot_id', 'url'], 'cited_sources_snapshot_url_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_cited_sources');
    }
};
