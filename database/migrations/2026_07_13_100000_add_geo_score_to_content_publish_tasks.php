<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_publish_tasks', function (Blueprint $table) {
            $table->integer('avg_geo_score')->nullable()->after('total_jobs');
            $table->json('geo_score_details')->nullable()->after('avg_geo_score');
            $table->unsignedBigInteger('keyword_group_id')->nullable()->after('platform_keys');
            $table->string('run_mode', 20)->default('manual')->after('keyword_group_id');
            $table->timestamp('last_auto_run_at')->nullable()->after('run_mode');
        });
    }

    public function down(): void
    {
        Schema::table('content_publish_tasks', function (Blueprint $table) {
            $table->dropColumn(['avg_geo_score', 'geo_score_details', 'keyword_group_id', 'run_mode', 'last_auto_run_at']);
        });
    }
};
