<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // P3: 发布渠道平台树
        Schema::table('distribution_channels', function (Blueprint $table) {
            if (! Schema::hasColumn('distribution_channels', 'distribute_type')) {
                $table->string('distribute_type', 30)->default('self_build')->after('channel_type');
            }
            if (! Schema::hasColumn('distribution_channels', 'platform_meta')) {
                $table->json('platform_meta')->nullable()->after('distribute_type');
            }
        });

        // P4: 竞品对比
        if (! Schema::hasTable('ai_competitors')) {
            Schema::create('ai_competitors', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('workspace_id');
                $table->string('brand_name', 100);
                $table->string('brand_website', 500)->nullable();
                $table->string('status', 20)->default('active');
                $table->timestamps();
                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('distribution_channels', function (Blueprint $table) {
            $table->dropColumn(['distribute_type', 'platform_meta']);
        });
        Schema::dropIfExists('ai_competitors');
    }
};
