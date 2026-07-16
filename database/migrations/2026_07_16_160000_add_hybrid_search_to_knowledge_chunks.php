<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 添加 tsvector 列（自动从 content 生成）
        DB::statement('ALTER TABLE knowledge_chunks ADD COLUMN IF NOT EXISTS ts_vector tsvector');
        DB::statement("ALTER TABLE knowledge_chunks ALTER COLUMN ts_vector SET DEFAULT ''::tsvector");

        // GIN 索引加速全文检索
        DB::statement('CREATE INDEX IF NOT EXISTS idx_chunks_tsvector ON knowledge_chunks USING GIN (ts_vector)');

        // 填充现有数据
        DB::statement("UPDATE knowledge_chunks SET ts_vector = to_tsvector('simple', COALESCE(content, '')) WHERE ts_vector IS NULL OR ts_vector = ''::tsvector");

        // 触发器：content 变更时自动更新 ts_vector
        DB::statement("
            CREATE OR REPLACE FUNCTION update_chunk_tsvector() RETURNS trigger AS \$\$
            BEGIN
                NEW.ts_vector = to_tsvector('simple', COALESCE(NEW.content, ''));
                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;
        ");
        DB::statement('DROP TRIGGER IF EXISTS trg_chunk_tsvector ON knowledge_chunks');
        DB::statement('CREATE TRIGGER trg_chunk_tsvector BEFORE INSERT OR UPDATE ON knowledge_chunks FOR EACH ROW EXECUTE FUNCTION update_chunk_tsvector()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS trg_chunk_tsvector ON knowledge_chunks');
        DB::statement('DROP FUNCTION IF EXISTS update_chunk_tsvector()');
        DB::statement('DROP INDEX IF EXISTS idx_chunks_tsvector');
        DB::statement('ALTER TABLE knowledge_chunks DROP COLUMN IF EXISTS ts_vector');
    }
};
