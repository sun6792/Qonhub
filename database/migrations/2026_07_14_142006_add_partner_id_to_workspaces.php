<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            // 预留代理商/合作伙伴绑定字段，为 P2 代理分销体系做数据基础
            $table->unsignedBigInteger('partner_id')->nullable()->after('owner_admin_id');
            $table->string('partner_type', 40)->nullable()->after('partner_id')
                ->comment('reseller / agency / null（直客）');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn(['partner_type', 'partner_id']);
        });
    }
};
