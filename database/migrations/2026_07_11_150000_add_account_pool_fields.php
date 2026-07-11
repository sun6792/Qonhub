<?php

/**
 * 账号池轻量升级：支持自动轮换 + 限流感知。
 * 纯增量，不改动现有字段。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. client_platform_accounts（旧自媒体授权表）
        Schema::table('client_platform_accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('client_platform_accounts', 'daily_published_count')) {
                $table->unsignedInteger('daily_published_count')->default(0)->after('status')->comment('今日已发布次数');
            }
            if (! Schema::hasColumn('client_platform_accounts', 'daily_publish_limit')) {
                $table->unsignedInteger('daily_publish_limit')->default(5)->after('daily_published_count')->comment('单日发布上限');
            }
            if (! Schema::hasColumn('client_platform_accounts', 'risk_level')) {
                $table->string('risk_level', 20)->default('low')->after('status')->comment('风控等级: low/medium/high');
            }
            if (! Schema::hasColumn('client_platform_accounts', 'last_used_at')) {
                $table->timestamp('last_used_at')->nullable()->after('expires_at')->comment('上次使用时间');
            }
        });

        // 2. content_publisher_accounts（新账号池表）
        Schema::table('content_publisher_accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('content_publisher_accounts', 'risk_level')) {
                $table->string('risk_level', 20)->default('low')->after('health_status')->comment('风控等级: low/medium/high');
            }
            if (! Schema::hasColumn('content_publisher_accounts', 'success_rate')) {
                $table->unsignedInteger('success_rate')->default(100)->after('total_publish_count')->comment('成功率百分比');
            }
        });
    }

    public function down(): void
    {
        Schema::table('client_platform_accounts', function (Blueprint $table) {
            $table->dropColumn(['daily_published_count', 'daily_publish_limit', 'risk_level', 'last_used_at']);
        });
        Schema::table('content_publisher_accounts', function (Blueprint $table) {
            $table->dropColumn(['risk_level', 'success_rate']);
        });
    }
};
