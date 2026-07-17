<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
        // 先回填空值，防止 NULL 导致 NOT NULL 约束失败
        DB::statement("UPDATE enterprise_profiles SET unified_social_credit_code = '' WHERE unified_social_credit_code IS NULL");
        DB::statement("UPDATE enterprise_profiles SET legal_person = '' WHERE legal_person IS NULL");
        DB::statement("UPDATE enterprise_profiles SET registered_capital = '' WHERE registered_capital IS NULL");
        DB::statement("UPDATE enterprise_profiles SET company_email = '' WHERE company_email IS NULL");
        DB::statement("UPDATE enterprise_profiles SET company_website = '' WHERE company_website IS NULL");

        Schema::table('enterprise_profiles', function (Blueprint $table): void {
            $table->string('unified_social_credit_code', 50)->nullable(false)->change();
            $table->string('legal_person', 50)->nullable(false)->change();
            $table->string('registered_capital', 50)->nullable(false)->change();
            $table->string('company_email', 100)->nullable(false)->change();
            $table->string('company_website', 200)->nullable(false)->change();
        });
    }
};
