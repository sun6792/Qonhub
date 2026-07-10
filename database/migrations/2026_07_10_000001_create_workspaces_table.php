<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 150)->unique();
            $table->text('description')->nullable();
            $table->string('logo_url', 500)->nullable();
            $table->string('access_token', 64)->unique()->comment('客户前端看板访问凭证');
            $table->unsignedBigInteger('owner_admin_id')->nullable()->comment('主要负责运营人员');
            $table->foreign('owner_admin_id')->references('id')->on('admins')->onDelete('set null');
            $table->string('client_company_name', 200)->nullable()->comment('客户企业名称');
            $table->string('client_contact_name', 100)->nullable();
            $table->string('client_email', 200)->nullable();
            $table->string('client_phone', 40)->nullable();
            $table->json('brand_keywords')->nullable()->comment('AI引用追踪用的品牌关键词列表');
            $table->json('config')->nullable()->comment('空间级配置覆盖');
            $table->string('status', 20)->default('active')->comment('active/paused/archived');
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('owner_admin_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspaces');
    }
};
