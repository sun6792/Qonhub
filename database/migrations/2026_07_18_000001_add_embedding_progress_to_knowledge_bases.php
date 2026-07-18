<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('knowledge_bases', 'embedding_status')) {
            Schema::table('knowledge_bases', function (Blueprint $table) {
                $table->string('embedding_status', 20)->default('pending')->after('word_count');
            });
        }
        if (! Schema::hasColumn('knowledge_bases', 'embedding_progress')) {
            Schema::table('knowledge_bases', function (Blueprint $table) {
                $table->integer('embedding_progress')->default(0)->after('embedding_status');
            });
        }
        if (! Schema::hasColumn('knowledge_bases', 'embedding_error')) {
            Schema::table('knowledge_bases', function (Blueprint $table) {
                $table->string('embedding_error', 500)->nullable()->after('embedding_progress');
            });
        }
    }

    public function down(): void
    {
        Schema::table('knowledge_bases', function (Blueprint $table) {
            $table->dropColumn(['embedding_status', 'embedding_progress', 'embedding_error']);
        });
    }
};
