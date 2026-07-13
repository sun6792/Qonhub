<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprise_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('enterprise_profiles', 'contact_name')) {
                $table->string('contact_name', 50)->nullable()->after('company_website');
            }
            if (! Schema::hasColumn('enterprise_profiles', 'contact_phone')) {
                $table->string('contact_phone', 20)->nullable()->after('contact_name');
            }
        });

        Schema::table('enterprise_anchor_certifications', function (Blueprint $table) {
            if (! Schema::hasColumn('enterprise_anchor_certifications', 'rpa_task_id')) {
                $table->string('rpa_task_id', 100)->nullable()->after('expires_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('enterprise_profiles', function (Blueprint $table) {
            $table->dropColumn(['contact_name', 'contact_phone']);
        });
        Schema::table('enterprise_anchor_certifications', function (Blueprint $table) {
            $table->dropColumn('rpa_task_id');
        });
    }
};
