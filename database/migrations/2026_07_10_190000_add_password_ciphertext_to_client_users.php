<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_users', function (Blueprint $table): void {
            $table->text('password_ciphertext')->nullable()->comment('ApiKeyCrypto加密的明文密码，超管可查看');
        });
    }

    public function down(): void
    {
        Schema::table('client_users', function (Blueprint $table): void {
            $table->dropColumn('password_ciphertext');
        });
    }
};
