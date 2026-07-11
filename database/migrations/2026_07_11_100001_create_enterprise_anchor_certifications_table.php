<?php

/**
 * 企业 B2B 锚点认证记录：追踪每个企业在各 B2B 平台的认证状态。
 *
 * 运营团队统一操作，客户无需反复登录。认证目的不是发文，而是让
 * 平台的企业页面被主流大模型收录并引用，提升品牌在 AI 回答中的出现率。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_anchor_certifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_profile_id')->constrained('enterprise_profiles')->cascadeOnDelete();
            $table->string('anchor_platform_key', 50)->comment('锚点平台标识');
            $table->string('platform_account_id', 100)->default('')->comment('平台账号ID/用户名');
            $table->string('platform_page_url', 300)->default('')->comment('平台企业页面URL');
            $table->string('certification_status', 20)->default('pending')->comment('认证状态: pending/certified/expired/rejected');
            $table->foreignId('certified_by')->nullable()->constrained('admins');
            $table->timestamp('certified_at')->nullable()->comment('认证完成时间');
            $table->date('expires_at')->nullable()->comment('认证过期时间');
            $table->text('verification_notes')->nullable()->comment('认证备注');
            $table->timestamp('last_sync_at')->nullable()->comment('最后同步时间');
            $table->json('metadata')->nullable()->comment('平台特定元数据');
            $table->timestamps();

            $table->unique(['enterprise_profile_id', 'anchor_platform_key'], 'uqe_anchor_profile_platform');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_anchor_certifications');
    }
};
