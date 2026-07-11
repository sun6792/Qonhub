<?php

/**
 * B2B 企业认证入驻字段扩展。
 *
 * 纯增量：不修改、不删除任何现有字段，保证向下兼容。
 *
 * 新增：
 * - content_publish_tasks.type: 任务类型 publish/certify，默认 publish
 * - content_publish_results.certify_url: 认证后企业店铺链接
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 任务表：加入类型区分
        if (! Schema::hasColumn('content_publish_tasks', 'type')) {
            Schema::table('content_publish_tasks', function (Blueprint $table) {
                $table->string('type', 20)->default('publish')->after('status')
                    ->comment('任务类型: publish=内容发布, certify=企业认证');
            });
        }

        // 明细表：加入认证结果链接
        if (! Schema::hasColumn('content_publish_results', 'certify_url')) {
            Schema::table('content_publish_results', function (Blueprint $table) {
                $table->string('certify_url', 500)->default('')->after('remote_article_url')
                    ->comment('B2B认证后的企业店铺URL');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('content_publish_tasks', 'type')) {
            Schema::table('content_publish_tasks', function (Blueprint $table) {
                $table->dropColumn('type');
            });
        }
        if (Schema::hasColumn('content_publish_results', 'certify_url')) {
            Schema::table('content_publish_results', function (Blueprint $table) {
                $table->dropColumn('certify_url');
            });
        }
    }
};
