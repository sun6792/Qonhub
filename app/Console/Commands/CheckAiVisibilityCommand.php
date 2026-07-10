<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use App\Services\GeoFlow\AiVisibilityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class CheckAiVisibilityCommand extends Command
{
    protected $signature = 'geoflow:check-visibility
                            {--workspace= : 指定工作空间ID，留空则检查所有活跃空间}
                            {--limit=50 : 单次最大查询数}
                            {--delay=1500 : 查询间隔毫秒}';

    protected $description = '检测品牌在各AI平台中的引用情况并生成每日快照';

    public function handle(AiVisibilityService $visibilityService): int
    {
        $workspaceId = $this->option('workspace');
        $workspaceId = $workspaceId !== null ? (int) $workspaceId : 0;

        if ($workspaceId > 0) {
            $workspace = Workspace::query()->whereKey($workspaceId)->first();
            if (! $workspace) {
                $this->error("工作空间 {$workspaceId} 不存在");

                return self::FAILURE;
            }

            $this->info("正在检测工作空间: {$workspace->name}");

            try {
                $result = $visibilityService->checkWorkspace($workspace);
                $this->info("完成！查询 {$result['total']} 次，品牌提及 {$result['mentioned']} 次");
                $this->table(['平台', '关键词', '是否提及'], array_map(
                    static fn (array $c): array => [$c['platform'], $c['keyword'], $c['mentioned'] ? '✅ 是' : '❌ 否'],
                    $result['checks']
                ));

                $visibilityService->snapshotForWorkspace($workspace, now());
                $this->info('每日快照已生成');
            } catch (Throwable $e) {
                $this->error("检测失败: {$e->getMessage()}");
                Log::error('AI visibility check failed: '.$e->getMessage());

                return self::FAILURE;
            }

            return self::SUCCESS;
        }

        // 检查所有活跃空间
        $workspaces = Workspace::query()->where('status', 'active')->get();
        $this->info("开始批量检测 {$workspaces->count()} 个活跃工作空间...");

        $success = 0;
        $failed = 0;

        foreach ($workspaces as $workspace) {
            $this->line("  - 检测: {$workspace->name}");

            try {
                $result = $visibilityService->checkWorkspace($workspace);
                $this->line("    查询 {$result['total']} 次，提及 {$result['mentioned']} 次");
                $success++;
            } catch (Throwable $e) {
                $this->error("    失败: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->info("批量检测完成: 成功 {$success}, 失败 {$failed}");

        // 生成所有快照
        $this->info('正在生成每日快照...');
        $visibilityService->generateDailySnapshots();
        $this->info('每日快照已生成');

        return self::SUCCESS;
    }
}
