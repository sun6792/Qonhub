<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_visibility_checks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->foreign('workspace_id')->references('id')->on('workspaces')->onDelete('cascade');
            $table->string('ai_platform', 40)->comment('deepseek/doubao/wenxin/kimi/qianwen/yuanbao');
            $table->string('query_keyword', 200)->comment('向AI提问使用的关键词');
            $table->text('query_text')->comment('完整的提问文本');
            $table->boolean('mentioned')->default(false)->comment('是否提到了客户品牌');
            $table->string('mention_type', 40)->nullable()->comment('brand_name/domain/url/direct_citation');
            $table->text('response_snippet')->nullable()->comment('AI回复中提到品牌的内容片段');
            $table->integer('citation_position')->nullable()->comment('品牌在引用列表中的位置');
            $table->json('raw_response_meta')->nullable();
            $table->decimal('api_cost', 8, 6)->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamp('checked_at');
            $table->timestamps();

            $table->index(['workspace_id', 'ai_platform', 'checked_at']);
            $table->index('mentioned');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_visibility_checks');
    }
};
