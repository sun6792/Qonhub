<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_users', function (Blueprint $table): void {
            $table->string('username', 80)->nullable()->after('workspace_id');
            $table->string('email', 200)->nullable()->change();
        });

        // 把现有 email 值复制到 username
        DB::statement("UPDATE client_users SET username = email WHERE username IS NULL");
    }

    public function down(): void
    {
        Schema::table('client_users', function (Blueprint $table): void {
            $table->dropColumn('username');
            $table->string('email', 200)->nullable(false)->unique()->change();
        });
    }
};
