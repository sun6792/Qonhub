<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_platform_accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->foreign('workspace_id')->references('id')->on('workspaces')->onDelete('cascade');
            $table->string('platform_key', 50)->comment('zhihu/toutiao/baijiahao/xiaohongshu/sohu/bilibili');
            $table->string('platform_account_name', 200)->nullable()->comment('平台上的账号名称');
            $table->text('credential_ciphertext')->nullable()->comment('AES加密的Cookie或Token');
            $table->json('credential_meta')->nullable()->comment('认证元信息');
            $table->string('status', 20)->default('pending')->comment('pending/active/expired/revoked');
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('last_error_message')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'platform_key']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_platform_accounts');
    }
};
