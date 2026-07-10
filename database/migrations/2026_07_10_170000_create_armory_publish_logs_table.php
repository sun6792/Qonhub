<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('armory_publish_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('article_id');
            $table->foreign('article_id')->references('id')->on('articles')->onDelete('cascade');
            $table->unsignedBigInteger('workspace_id')->nullable();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->onDelete('set null');
            $table->string('template_key', 50);
            $table->string('platform_key', 50)->nullable();
            $table->unsignedBigInteger('channel_id')->nullable();
            $table->foreign('channel_id')->references('id')->on('distribution_channels')->onDelete('set null');
            $table->text('rewritten_title')->nullable();
            $table->text('rewritten_content')->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('message')->nullable();
            $table->json('response_meta')->nullable();
            $table->unsignedBigInteger('published_by_admin_id')->nullable();
            $table->foreign('published_by_admin_id')->references('id')->on('admins')->onDelete('set null');
            $table->timestamps();

            $table->index(['article_id', 'template_key']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('armory_publish_logs');
    }
};
