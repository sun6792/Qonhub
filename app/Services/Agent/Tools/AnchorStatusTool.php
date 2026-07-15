<?php

namespace App\Services\Agent\Tools;

use App\Models\Workspace;
use App\Services\Agent\AgentToolInterface;
use App\Services\GeoFlow\EnterpriseAnchorService;

/**
 * 锚点状态查询工具 — 封装 EnterpriseAnchorService。
 */
class AnchorStatusTool implements AgentToolInterface
{
    public function __construct(
        private readonly EnterpriseAnchorService $anchorService,
    ) {}

    public function getName(): string
    {
        return 'anchor_status';
    }

    public function getDescription(): string
    {
        return '查询指定工作空间的B2B企业锚点认证状态，支持10大B2B平台。返回各平台的认证进度（已认证/待认证/已过期）和覆盖缺口。';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'workspace_id' => ['type' => 'integer', 'description' => '工作空间ID'],
            ],
            'required' => ['workspace_id'],
        ];
    }

    public function execute(array $args, int $workspaceId): array
    {
        $wsId = (int) ($args['workspace_id'] ?? $workspaceId);
        $workspace = Workspace::query()->find($wsId);
        if (! $workspace) {
            return ['success' => false, 'data' => null, 'error' => '工作空间不存在'];
        }

        $profile = $this->anchorService->getOrInitProfile($workspace);
        $summary = $this->anchorService->certificationSummary($profile);

        return [
            'success' => true,
            'data' => [
                'has_profile' => $profile->exists && ! empty($profile->company_full_name),
                'total_platforms' => $summary['total'] ?? 0,
                'certified' => $summary['certified'] ?? 0,
                'pending' => $summary['pending'] ?? 0,
                'expired' => $summary['expired'] ?? 0,
                'platforms' => ($summary['platforms'] ?? collect())->toArray(),
            ],
        ];
    }
}
