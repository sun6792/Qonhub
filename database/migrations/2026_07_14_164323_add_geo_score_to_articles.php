<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->unsignedTinyInteger('geo_score')->nullable()->after('is_ai_generated')
                ->comment('GEO 六维评分 0-100');
            $table->string('geo_grade', 4)->nullable()->after('geo_score')
                ->comment('GEO 等级 A-F');
            $table->json('geo_score_data')->nullable()->after('geo_grade')
                ->comment('GEO 评分详情 {score, grade, dimensions, retries}');
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn(['geo_score_data', 'geo_grade', 'geo_score']);
        });
    }
};
