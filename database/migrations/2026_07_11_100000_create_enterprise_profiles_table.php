<?php

/**
 * 企业档案表：一个工作空间对应一份企业档案，用于 B2B 信息锚点认证。
 *
 * NAP+W（Name/Address/Phone/Website）一致性是 LLM 引用准确度的核心前提。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained('workspaces')->cascadeOnDelete();
            $table->string('company_full_name', 200)->default('')->comment('公司全称');
            $table->string('company_short_name', 100)->default('')->comment('公司简称');
            $table->string('unified_social_credit_code', 50)->default('')->comment('统一社会信用代码');
            $table->string('legal_person', 50)->default('')->comment('法定代表人');
            $table->string('registered_capital', 50)->default('')->comment('注册资本');
            $table->date('establishment_date')->nullable()->comment('成立日期');
            $table->text('business_scope')->nullable()->comment('经营范围');
            $table->string('company_province', 20)->default('')->comment('所在省');
            $table->string('company_city', 20)->default('')->comment('所在市');
            $table->string('company_address', 255)->default('')->comment('详细地址');
            $table->string('company_phone', 30)->default('')->comment('企业电话');
            $table->string('company_email', 100)->default('')->comment('企业邮箱');
            $table->string('company_website', 200)->default('')->comment('企业官网');
            $table->string('industry', 50)->default('')->comment('所属行业');
            $table->text('products_services')->nullable()->comment('主营产品/服务（JSON）');
            $table->string('business_license_path', 300)->default('')->comment('营业执照附件路径');
            $table->string('company_logo_path', 300)->default('')->comment('企业Logo路径');
            $table->boolean('nap_consistency_checked')->default(false)->comment('NAP+W 一致性是否已校验');
            $table->string('verification_status', 20)->default('pending')->comment('核验状态: pending/verified/rejected');
            $table->foreignId('verified_by')->nullable()->constrained('admins');
            $table->timestamp('verified_at')->nullable()->comment('核验时间');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_profiles');
    }
};
