<?php

namespace App\Console\Commands;

use App\Models\ClientPlatformAccount;
use App\Models\ContentPublisherAccount;
use Illuminate\Console\Command;

/**
 * 将旧 client_platform_accounts 数据迁移到新 content_publisher_accounts 表。
 *
 * 保留 workspace 关联和加密方式不变（复用 ApiKeyCrypto），
 * 支持 --pretend 预览模式和幂等执行（已迁移的记录不会重复导入）。
 *
 * 用法：
 *   php artisan migrate:platform-accounts              # 执行迁移
 *   php artisan migrate:platform-accounts --pretend    # 预览（不写入）
 *   php artisan migrate:platform-accounts --force      # 强制重新迁移
 */
class MigratePlatformAccountsCommand extends Command
{
    protected $signature = 'migrate:platform-accounts
                            {--pretend : 预览模式，不实际写入}
                            {--force : 强制重新迁移已存在的记录}';

    protected $description = '将旧 client_platform_accounts 数据迁移到新的 content_publisher_accounts 账号池';

    public function handle(): int
    {
        $oldAccounts = ClientPlatformAccount::query()
            ->with('workspace')
            ->orderBy('id')
            ->get();

        if ($oldAccounts->isEmpty()) {
            $this->info('没有需要迁移的旧数据');

            return self::SUCCESS;
        }

        $this->info("发现 {$oldAccounts->count()} 条旧数据");
        $migrated = 0;
        $skipped = 0;
        $errors = 0;

        /** @var \Illuminate\Console\OutputStyle $output */
        $output = $this->output;
        $output->progressStart($oldAccounts->count());

        foreach ($oldAccounts as $old) {
            $output->progressAdvance();

            // 检查是否已迁移
            if (! $this->option('force')) {
                $exists = ContentPublisherAccount::query()
                    ->where('workspace_id', (int) $old->workspace_id)
                    ->where('platform_key', $old->platform_key)
                    ->where('account_name', $old->platform_account_name ?: '默认账号')
                    ->exists();

                if ($exists) {
                    $skipped++;

                    continue;
                }
            }

            try {
                if ($this->option('pretend')) {
                    $this->line("  [PRETEND] {$old->platform_key}:{$old->platform_account_name} → content_publisher_accounts");
                    $migrated++;

                    continue;
                }

                ContentPublisherAccount::query()->create([
                    'workspace_id' => (int) $old->workspace_id,
                    'platform_key' => $old->platform_key,
                    'platform_type' => $this->mapType($old->platform_key),
                    'platform_name' => $old->platform_key,
                    'account_name' => $old->platform_account_name ?: '默认账号',
                    'account_id_on_platform' => '',
                    'credential_type' => 'cookie',
                    'credential_ciphertext' => $old->credential_ciphertext,
                    'credential_metadata' => [
                        'migrated_from' => 'client_platform_accounts',
                        'migrated_at' => now()->toIso8601String(),
                        'old_id' => (int) $old->id,
                        'old_status' => $old->status,
                        'last_verified_at' => $old->last_verified_at?->toIso8601String(),
                    ],
                    'status' => $old->status === 'active' ? 'active' : 'disabled',
                    'health_status' => 'unknown',
                    'created_by_admin_id' => null,
                    'notes' => '从旧 client_platform_accounts 迁移',
                ]);

                $migrated++;
            } catch (\Throwable $e) {
                $this->error("  迁移失败 {$old->platform_key}:{$old->platform_account_name} — {$e->getMessage()}");
                $errors++;
            }
        }

        $output->progressFinish();

        $this->newLine();
        $this->info("迁移完成：成功 {$migrated} / 跳过 {$skipped} / 失败 {$errors}");

        if ($this->option('pretend')) {
            $this->warn('已使用 --pretend 模式，未实际写入数据');
        }

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * 映射旧 platform_key 到新的 platform_type。
     */
    private function mapType(string $platformKey): string
    {
        $selfMedia = ['toutiao', 'baijiahao', 'xiaohongshu', 'sohu', 'wangyihao', 'bilibili'];

        return in_array($platformKey, $selfMedia, true) ? 'self_media' : 'b2b';
    }
}
