<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_visibility_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->foreign('workspace_id')->references('id')->on('workspaces')->onDelete('cascade');
            $table->date('snapshot_date');
            $table->string('ai_platform', 40);
            $table->unsignedInteger('total_queries')->default(0);
            $table->unsignedInteger('mentioned_count')->default(0);
            $table->decimal('visibility_score', 5, 2)->default(0)->comment('0-100');
            $table->decimal('previous_score', 5, 2)->nullable();
            $table->json('top_keywords')->nullable()->comment('引用率最高的关键词');
            $table->json('detail')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'snapshot_date', 'ai_platform'], 'vis_snap_unique');
            $table->index('snapshot_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_visibility_snapshots');
    }
};
