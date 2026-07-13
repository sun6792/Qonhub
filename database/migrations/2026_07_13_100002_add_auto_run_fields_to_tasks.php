<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            if (! Schema::hasColumn('tasks', 'keyword_group_id')) {
                $table->unsignedBigInteger('keyword_group_id')->nullable()->after('image_library_id');
            }
            if (! Schema::hasColumn('tasks', 'auto_distribute_channels')) {
                $table->json('auto_distribute_channels')->nullable()->after('keyword_group_id');
            }
            if (! Schema::hasColumn('tasks', 'run_mode')) {
                $table->string('run_mode', 20)->default('manual')->after('auto_distribute_channels');
            }
            if (! Schema::hasColumn('tasks', 'last_auto_run_at')) {
                $table->timestamp('last_auto_run_at')->nullable()->after('run_mode');
            }
            if (! Schema::hasColumn('tasks', 'last_keyword_index')) {
                $table->integer('last_keyword_index')->default(0)->after('last_auto_run_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['keyword_group_id', 'auto_distribute_channels', 'run_mode', 'last_auto_run_at', 'last_keyword_index']);
        });
    }
};
