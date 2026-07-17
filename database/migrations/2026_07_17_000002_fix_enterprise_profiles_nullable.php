<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprise_profiles', function (Blueprint $table): void {
            // 这些字段在实际使用中可能为空，需要允许 null
            $table->string('unified_social_credit_code', 50)->nullable()->change();
            $table->string('legal_person', 50)->nullable()->change();
            $table->string('registered_capital', 50)->nullable()->change();
            $table->string('company_email', 100)->nullable()->change();
            $table->string('company_website', 200)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('enterprise_profiles', function (Blueprint $table): void {
            $table->string('unified_social_credit_code', 50)->nullable(false)->change();
            $table->string('legal_person', 50)->nullable(false)->change();
            $table->string('registered_capital', 50)->nullable(false)->change();
            $table->string('company_email', 100)->nullable(false)->change();
            $table->string('company_website', 200)->nullable(false)->change();
        });
    }
};
