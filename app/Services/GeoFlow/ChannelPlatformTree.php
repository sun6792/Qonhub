<?php

namespace App\Services\GeoFlow;

use App\Models\ClientPlatformAccount;
use App\Models\DistributionChannel;
use App\Models\EnterpriseAnchorCertification;
use App\Models\EnterpriseProfile;

/**
 * 发布渠道平台树服务。
 *
 * 统一输出两级分类+三级平台的级联数据，
 * 所有平台数据从各模块现有配置中读取，不重复维护。
 */
class ChannelPlatformTree
{
    /**
     * 返回完整平台树（二级分类+三级平台），按 workspace 过滤。
     *
     * @return array<int, array{value:int|string, label:string, children:array}>
     */
    public function build(int $workspaceId): array
    {
        return [
            [
                'value' => 2,
                'label' => '平台发布',
                'children' => array_values(array_filter([
                    $this->buildSelfMediaNode($workspaceId),
                    $this->buildB2bNode($workspaceId),
                    $this->buildWebsiteAgentNode($workspaceId),
                    $this->buildSelfBuildNode($workspaceId),
                ], fn($n) => ! empty($n['children']))),
            ],
            [
                'value' => 3,
                'label' => '媒体发布',
                'children' => array_values(array_filter([
                    $this->buildAuthoritativeNode($workspaceId),
                    $this->buildAuthMediaNode($workspaceId),
                ], fn($n) => ! empty($n['children']))),
            ],
        ];
    }

    // ── 自媒体矩阵 ─────────────────────────────────────

    private function buildSelfMediaNode(int $workspaceId): array
    {
        $platforms = ClientPlatformAccount::supportedPlatforms();
        $accounts = ClientPlatformAccount::query()
            ->where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->get()
            ->keyBy('platform_key');

        $children = [];
        foreach ($platforms as $key => $info) {
            $children[] = [
                'value' => $key,
                'label' => $info['name'] ?? $key,
                'icon' => $info['icon'] ?? 'globe',
                'connected' => $accounts->has($key),
            ];
        }

        return [
            'value' => 'self_media',
            'label' => '自媒体矩阵',
            'icon' => 'users',
            'children' => $children,
        ];
    }

    // ── B2B 行业网站 ───────────────────────────────────

    private function buildB2bNode(int $workspaceId): array
    {
        $platforms = EnterpriseAnchorService::anchorPlatforms();
        $profile = EnterpriseProfile::query()->where('workspace_id', $workspaceId)->first();

        $certs = collect();
        if ($profile) {
            $certs = EnterpriseAnchorCertification::query()
                ->where('enterprise_profile_id', (int) $profile->id)
                ->get()
                ->keyBy('anchor_platform_key');
        }

        $children = [];
        foreach ($platforms as $key => $info) {
            $cert = $certs->get($key);
            $children[] = [
                'value' => $key,
                'label' => $info['name'] ?? $key,
                'icon' => $info['icon'] ?? 'building',
                'connected' => $cert && $cert->isCertified() && ! $cert->isExpired(),
                'supports_rpa' => ! empty($info['supports_rpa']),
            ];
        }

        return [
            'value' => 'b2b',
            'label' => 'B2B行业网站',
            'icon' => 'building',
            'children' => $children,
        ];
    }

    // ── 智能体官网 ─────────────────────────────────────

    private function buildWebsiteAgentNode(int $workspaceId): array
    {
        // 渠道通过 task_distribution_channels 关联 workspace 的 task，
        // 此处直接列出所有活跃的 geoflow_agent 类型渠道
        $channels = DistributionChannel::query()
            ->where('channel_type', 'geoflow_agent')
            ->where('status', 'active')
            ->get();

        if ($channels->isEmpty()) {
            return ['value' => 'website_agent', 'label' => '智能体官网', 'children' => []];
        }

        return [
            'value' => 'website_agent',
            'label' => '智能体官网',
            'icon' => 'bot',
            'children' => $channels->map(fn($ch) => [
                'value' => 'agent_' . $ch->id,
                'label' => $ch->name ?: $ch->domain ?: 'Agent #' . $ch->id,
                'icon' => 'bot',
                'connected' => ($ch->last_health_status ?? null) === 'healthy',
            ])->all(),
        ];
    }

    // ── 自营媒体 ───────────────────────────────────────

    private function buildSelfBuildNode(int $workspaceId): array
    {
        $channels = DistributionChannel::query()
            ->whereIn('channel_type', ['wordpress_rest', 'generic_http'])
            ->where('status', 'active')
            ->get();

        if ($channels->isEmpty()) {
            return ['value' => 'self_build', 'label' => '自营媒体', 'children' => []];
        }

        return [
            'value' => 'self_build',
            'label' => '自营媒体',
            'icon' => 'server',
            'children' => $channels->map(fn($ch) => [
                'value' => 'self_' . $ch->id,
                'label' => $ch->name ?: $ch->domain ?: $ch->channel_type,
                'icon' => $ch->channel_type === 'wordpress_rest' ? 'wordpress' : 'globe',
                'connected' => ($ch->last_health_status ?? null) === 'healthy',
            ])->all(),
        ];
    }

    // ── 权威合作媒体 ───────────────────────────────────

    private function buildAuthoritativeNode(int $workspaceId): array
    {
        // 权威合作媒体 = 后端直连或商务合作，不需要客户注册
        return [
            'value' => 'authoritative',
            'label' => '权威合作媒体',
            'icon' => 'star',
            'children' => [
                [
                    'value' => 'authoritative_all',
                    'label' => '全部权威媒体（商务合作渠道）',
                    'icon' => 'star',
                    'connected' => false,
                    'note' => '该发布方式由运营团队代为执行',
                ],
            ],
        ];
    }

    // ── 自媒体权威号 ───────────────────────────────────

    private function buildAuthMediaNode(int $workspaceId): array
    {
        $platforms = ClientPlatformAccount::supportedPlatforms();
        $accounts = ClientPlatformAccount::query()
            ->where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->get()
            ->keyBy('platform_key');

        $children = [];
        foreach ($platforms as $key => $info) {
            $children[] = [
                'value' => 'auth_' . $key,
                'label' => ($info['name'] ?? $key) . '权威号',
                'icon' => $info['icon'] ?? 'award',
                'connected' => $accounts->has($key),
            ];
        }

        return [
            'value' => 'authoritative_media',
            'label' => '自媒体权威号',
            'icon' => 'award',
            'children' => $children,
        ];
    }
}
