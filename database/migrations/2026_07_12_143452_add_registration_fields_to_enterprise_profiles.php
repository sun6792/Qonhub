<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprise_profiles', function (Blueprint $table) {
            $table->string('registration_phone', 30)->default('')->after('company_website')->comment('B2B平台注册专用手机号(收验证码用)');
            $table->boolean('registration_authorized')->default(false)->after('registration_phone')->comment('客户是否已授权代注册');
        });
    }

    public function down(): void
    {
        Schema::table('enterprise_profiles', function (Blueprint $table) {
            $table->dropColumn(['registration_phone', 'registration_authorized']);
        });
    }
};
