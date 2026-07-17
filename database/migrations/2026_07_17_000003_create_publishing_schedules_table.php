<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publishing_schedules', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('article_id');
            $table->string('platform', 50)->comment('toutiao_publish/baijiahao_publish/xiaohongshu_publish/sohu_publish');
            $table->timestamp('scheduled_at')->comment('计划发布时间');
            $table->string('status', 20)->default('pending')->comment('pending/processing/completed/failed');
            $table->text('error_message')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
            $table->index('workspace_id');
            $table->index('article_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publishing_schedules');
    }
};
