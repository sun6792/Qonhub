<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * 开发环境一键检查 — 启动系统前验证所有依赖可用。
 *
 * 用法: php artisan dev:check
 */
class DevCheckCommand extends Command
{
    protected $signature = 'dev:check';
    protected $description = 'Check all dev dependencies before starting the system';

    public function handle(): int
    {
        $this->newLine();
        $this->info('═══════════════════════════════════════');
        $this->info('  豆流 AI — 开发环境检查');
        $this->info('═══════════════════════════════════════');
        $this->newLine();

        $allOk = true;

        // 1. PHP
        $this->info('▶ PHP');
        $phpVersion = phpversion();
        if (version_compare($phpVersion, '8.2', '>=')) {
            $this->line("  ✅ PHP {$phpVersion}");
        } else {
            $this->line("  ❌ PHP {$phpVersion}（需要 ≥ 8.2）");
            $allOk = false;
        }

        // 2. Database
        $this->info('▶ Database');
        try {
            DB::connection()->getPdo();
            $dbName = DB::connection()->getDatabaseName();
            $this->line("  ✅ PostgreSQL ({$dbName})");
        } catch (\Throwable $e) {
            $this->line("  ❌ 数据库不可用: {$e->getMessage()}");
            $allOk = false;
        }

        // 3. Redis
        $this->info('▶ Redis');
        try {
            Redis::connection()->ping();
            $this->line('  ✅ Redis 连接正常');
        } catch (\Throwable $e) {
            $this->line("  ❌ Redis 不可用: {$e->getMessage()}");
            $allOk = false;
        }

        // 4. pgvector 扩展
        $this->info('▶ pgvector Extension');
        try {
            $hasVector = DB::selectOne("SELECT 1 FROM pg_extension WHERE extname = 'vector'");
            if ($hasVector) {
                $this->line('  ✅ pgvector 已安装');
            } else {
                $this->line('  ⚠️  pgvector 未安装（知识库向量检索不可用）');
            }
        } catch (\Throwable) {
            $this->line('  ⚠️  无法检测 pgvector');
        }

        // 5. Node.js + npm
        $this->info('▶ Node.js');
        $nodeVersion = trim(shell_exec('node -v 2>&1') ?? '');
        $npmVersion = trim(shell_exec('npm -v 2>&1') ?? '');
        if ($nodeVersion && version_compare(ltrim($nodeVersion, 'v'), '18', '>=')) {
            $this->line("  ✅ Node {$nodeVersion}, npm {$npmVersion}");
        } elseif ($nodeVersion) {
            $this->line("  ⚠️  Node {$nodeVersion}（RPA 引擎建议 ≥ 18）");
        } else {
            $this->line('  ⚠️  Node.js 未检测到（RPA 引擎需要）');
        }

        // 6. RPA 引擎
        $this->info('▶ RPA Engine');
        if (file_exists(base_path('rpa-engine/node_modules'))) {
            $this->line('  ✅ RPA 依赖已安装');
        } else {
            $this->line('  ⚠️  RPA 依赖未安装（进入 rpa-engine/ 执行 npm install）');
        }

        // 7. .env
        $this->info('▶ .env Configuration');
        $appKey = config('app.key');
        if ($appKey && $appKey !== '') {
            $this->line('  ✅ APP_KEY 已设置');
        } else {
            $this->line('  ❌ APP_KEY 未设置（运行 php artisan key:generate）');
            $allOk = false;
        }

        $this->newLine();
        if ($allOk) {
            $this->info('═══════════════════════════════════════');
            $this->info('  ✅ 环境就绪，可以启动系统');
            $this->info('═══════════════════════════════════════');
            $this->newLine();
            $this->line('  启动命令:');
            $this->line('    npm start                 — 一键启动（服务器+队列+Vite）');
            $this->line('    php artisan serve --port=18080  — 仅启动服务器');
            $this->newLine();
            $this->line('  管理后台: http://localhost:18080/geo_admin');
            $this->line('  Horizon:  http://localhost:18080/horizon');
            $this->newLine();
            return self::SUCCESS;
        }

        $this->warn('═══════════════════════════════════════');
        $this->warn('  ❌ 部分依赖未就绪，请先修复上述问题');
        $this->warn('═══════════════════════════════════════');
        $this->newLine();
        return self::FAILURE;
    }
}
