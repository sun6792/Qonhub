<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** v2.6.0: 文章引用追踪 */
    public function up(): void
    {
        Schema::table('ai_visibility_checks', function (Blueprint $table): void {
            $table->json('cited_article_ids')->nullable()->after('response_snippet');
            $table->integer('cited_article_count')->default(0)->after('cited_article_ids');
        });
    }

    public function down(): void
    {
        Schema::table('ai_visibility_checks', function (Blueprint $table): void {
            $table->dropColumn('cited_article_ids');
            $table->dropColumn('cited_article_count');
        });
    }
};
