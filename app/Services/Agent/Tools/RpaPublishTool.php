<?php

namespace App\Services\Agent\Tools;

use App\Services\Agent\AgentToolInterface;
use App\Services\Agent\RpaRoutingDecider;
use App\Services\GeoFlow\Publishing\ContentPublishService;
use App\Services\GeoFlow\Publishing\RpaEngineClient;
use App\Models\Workspace;

/**
 * RPA 分发调用工具 — 封装 ContentPublishService + RpaEngineClient。
 */
class RpaPublishTool implements AgentToolInterface
{
    public function __construct(
        private readonly ContentPublishService $publishService,
        private readonly RpaRoutingDecider $routingDecider,
    ) {}

    public function getName(): string
    {
        return 'rpa_publish';
    }

    public function getDescription(): string
    {
        return '通过自研RPA引擎或API渠道分发文章到指定平台。支持15个成熟渠道的自研RPA脚本，自动创建分发任务并入队执行。';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'workspace_id' => ['type' => 'integer', 'description' => '工作空间ID'],
                'platform_key' => ['type' => 'string', 'description' => '目标平台标识（如 toutiao/baijiahao/b2b168）'],
                'article_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => '待分发文章ID列表'],
                'action' => ['type' => 'string', 'description' => '操作类型: publish(发文) 或 register(注册认证)'],
            ],
            'required' => ['workspace_id', 'platform_key', 'article_ids', 'action'],
        ];
    }

    public function execute(array $args, int $workspaceId): array
    {
        $wsId = (int) ($args['workspace_id'] ?? $workspaceId);
        $workspace = Workspace::query()->find($wsId);
        if (! $workspace) {
            return ['success' => false, 'data' => null, 'error' => '工作空间不存在'];
        }

        $platformKey = (string) ($args['platform_key'] ?? '');
        $articleIds = array_map('intval', $args['article_ids'] ?? []);
        $action = (string) ($args['action'] ?? 'publish');
        $route = $this->routingDecider->decide($platformKey);

        try {
            if ($route === 'native_rpa') {
                // 自研 RPA 轨道
                $task = $this->publishService->createPublishTask(
                    workspace: $workspace,
                    articleIds: $articleIds,
                    platformKeys: [$platformKey],
                    options: ['use_smart_scheduling' => true],
                );
                $this->publishService->dispatchPublishTask($task);

                return [
                    'success' => true,
                    'data' => [
                        'task_id' => (int) $task->id,
                        'route' => 'native_rpa',
                        'platform' => $platformKey,
                        'total_jobs' => (int) $task->total_jobs,
                    ],
                ];
            }

            return [
                'success' => true,
                'data' => [
                    'route' => $route,
                    'platform' => $platformKey,
                    'message' => '渠道已入队（' . $route . ' 轨道）',
                ],
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'data' => null, 'error' => $e->getMessage()];
        }
    }
}
