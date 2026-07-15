<?php

namespace App\Services\GeoFlow;

use App\Models\AiVisibilityCheck;
use App\Models\AiVisibilitySnapshot;
use App\Models\AiModel;
use App\Models\Workspace;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class AiVisibilityService
{
    /**
     * 国内6大主流AI平台及其监测配置。
     *
     * @var array<string, array{name:string, prompt:string, icon:string, color:string}>
     */
    public const AI_PLATFORMS = [
        'doubao'       => ['name' => '豆包',       'icon' => '🟢', 'color' => '#22C55E', 'url' => 'https://www.doubao.com/',           'pc' => true, 'mobile' => true],
        'deepseek'     => ['name' => 'DeepSeek',   'icon' => '🔵', 'color' => '#4F46E5', 'url' => 'https://chat.deepseek.com/',        'pc' => true, 'mobile' => true],
        'yuanbao'      => ['name' => '腾讯元宝',    'icon' => '💎', 'color' => '#06B6D4', 'url' => 'https://yuanbao.tencent.com/',       'pc' => true, 'mobile' => true],
        'wenxin'       => ['name' => '文心一言',    'icon' => '🟣', 'color' => '#8B5CF6', 'url' => 'https://yiyan.baidu.com/',           'pc' => true, 'mobile' => true],
        'qianwen'      => ['name' => '通义千问',    'icon' => '🟠', 'color' => '#F97316', 'url' => 'https://tongyi.aliyun.com/qianwen/',  'pc' => true, 'mobile' => true],
        'kimi'         => ['name' => 'Kimi',       'icon' => '🌙', 'color' => '#6366F1', 'url' => 'https://kimi.moonshot.cn/',          'pc' => true, 'mobile' => true],
        'xf_xinghuo'   => ['name' => '讯飞星火',    'icon' => '🔥', 'color' => '#EF4444', 'url' => 'https://xinghuo.xfyun.cn/',          'pc' => true, 'mobile' => true],
        'nami_ai'      => ['name' => '纳米AI',     'icon' => '🧬', 'color' => '#10B981', 'url' => 'https://bot.n.cn/',                   'pc' => true, 'mobile' => true],
        'baidu_ai'     => ['name' => '百度AI搜索',  'icon' => '🔍', 'color' => '#2563EB', 'url' => 'https://chat.baidu.com/search',      'pc' => true, 'mobile' => true],
        'wechat_ai'    => ['name' => '微信AI',     'icon' => '💬', 'color' => '#07C160', 'url' => 'https://yuanbao.tencent.com/',          'pc' => false, 'mobile' => true],
        'douyin_ai'    => ['name' => '抖音AI',     'icon' => '🎵', 'color' => '#000000', 'url' => 'https://www.douyin.com/aisearch',      'pc' => false, 'mobile' => true],
        'quark_ai'     => ['name' => '夸克AI',     'icon' => '⚛️', 'color' => '#FF6A00', 'url' => 'https://www.quark.cn/',               'pc' => true, 'mobile' => true],
    ];

    /**
     * @deprecated v2.6.0: 不再使用单LLM问答模式。保留常量仅为向下兼容。
     */
    private const AI_PLATFORM_PROMPTS = [];

    private const MAX_QUERIES_PER_RUN = 50;

    public function __construct() {}

    /**
     * v2.6.0 重构：对单个工作空间执行 AI 引用检测。
     *
     * 改为异步并行模式：每个 (平台, 关键词) 组合分发为独立的 PlatformScoutJob，
     * 不再串行阻塞。API 平台走真实 LLM API 调用，非 API 平台走 Playwright MCP 浏览器。
     *
     * @return array{total:int, mentioned:int, checks:list<array>, mode:string}
     */
    public function checkWorkspace(Workspace $workspace): array
    {
        $keywords = $workspace->brandKeywordList();
        $brandName = $workspace->client_company_name ?: $workspace->name;

        if ($keywords === []) {
            return ['total' => 0, 'mentioned' => 0, 'checks' => [], 'mode' => 'async'];
        }

        $dispatched = 0;
        $checks = [];

        foreach (array_keys(self::AI_PLATFORMS) as $platform) {
            foreach (array_slice($keywords, 0, 3) as $keyword) {
                if ($dispatched >= self::MAX_QUERIES_PER_RUN) {
                    break 2;
                }

                \App\Jobs\PlatformScoutJob::dispatch(
                    (int) $workspace->id,
                    $platform,
                    $keyword,
                    $brandName,
                )->onQueue('agent_scout');

                $checks[] = [
                    'platform' => $platform,
                    'keyword' => $keyword,
                    'dispatched' => true,
                ];

                $dispatched++;
            }
        }

        Log::info('Scout jobs dispatched', [
            'workspace_id' => $workspace->id,
            'total_jobs' => $dispatched,
            'mode' => 'async_parallel',
        ]);

        return [
            'total' => $dispatched,
            'mentioned' => 0, // 异步模式下即时返回，实际结果由 Job 写入 DB
            'checks' => $checks,
            'mode' => 'async',
        ];
    }

    /**
     * 为所有活跃工作空间生成每日快照。
     */
    public function generateDailySnapshots(): void
    {
        $workspaces = Workspace::query()->where('status', 'active')->get();
        $today = Carbon::today();

        foreach ($workspaces as $workspace) {
            $this->snapshotForWorkspace($workspace, $today);
        }
    }

    public function snapshotForWorkspace(Workspace $workspace, Carbon $date): void
    {
        $yesterday = $date->copy()->subDay();

        foreach (array_keys(self::AI_PLATFORMS) as $platform) {
            $todayStats = $this->aggregateChecks((int) $workspace->id, $platform, $date);
            $yesterdayStats = $this->aggregateChecks((int) $workspace->id, $platform, $yesterday);

            $totalQueries = $todayStats['total'];
            $mentionedCount = $todayStats['mentioned'];
            $visibilityScore = $totalQueries > 0
                ? round(($mentionedCount / $totalQueries) * 100, 2)
                : 0.0;
            $previousScore = $yesterdayStats['total'] > 0
                ? round(($yesterdayStats['mentioned'] / $yesterdayStats['total']) * 100, 2)
                : null;

            AiVisibilitySnapshot::query()->updateOrCreate(
                [
                    'workspace_id' => (int) $workspace->id,
                    'snapshot_date' => $date,
                    'ai_platform' => $platform,
                ],
                [
                    'total_queries' => $totalQueries,
                    'mentioned_count' => $mentionedCount,
                    'visibility_score' => $visibilityScore,
                    'previous_score' => $previousScore,
                    'top_keywords' => $todayStats['top_keywords'],
                ]
            );
        }
    }

    /**
     * 获取客户看板上的AI可见度数据。
     *
     * @return array<string, mixed>
     */
    public function clientVisibilityData(int $workspaceId): array
    {
        $snapshots = AiVisibilitySnapshot::query()
            ->where('workspace_id', $workspaceId)
            ->where('snapshot_date', '>=', Carbon::today()->subDays(30))
            ->orderBy('snapshot_date')
            ->orderBy('ai_platform')
            ->get();

        $byPlatform = [];
        foreach ($snapshots as $snap) {
            $byPlatform[$snap->ai_platform][] = [
                'date' => $snap->snapshot_date->toDateString(),
                'score' => (float) $snap->visibility_score,
                'mentioned' => (int) $snap->mentioned_count,
                'total' => (int) $snap->total_queries,
            ];
        }

        $latestScores = [];
        foreach (array_keys(self::AI_PLATFORMS) as $platform) {
            $latest = $snapshots
                ->where('ai_platform', $platform)
                ->sortByDesc('snapshot_date')
                ->first();

            $previous = $snapshots
                ->where('ai_platform', $platform)
                ->sortByDesc('snapshot_date')
                ->skip(1)
                ->first();

            $currentScore = $latest ? (float) $latest->visibility_score : 0.0;
            $previousScore = $previous ? (float) $previous->visibility_score : null;

            $latestScores[$platform] = [
                'score' => $currentScore,
                'previous_score' => $previousScore,
                'trend' => $previousScore !== null
                    ? ($currentScore > $previousScore ? 'up' : ($currentScore < $previousScore ? 'down' : 'flat'))
                    : 'new',
                'mentioned' => $latest ? (int) $latest->mentioned_count : 0,
            ];
        }

        return [
            'latest_scores' => $latestScores,
            'trends' => $byPlatform,
            'last_checked_at' => $snapshots->max('snapshot_date')?->toDateString() ?? '暂无数据',
        ];
    }

    // v2.6.0: 旧的 queryAi()/detectMention()/buildQuery() 等方法已移除。
    // 检测逻辑已迁移至 PlatformScoutJob：API 平台走 LlmOrchestratorService 真实调用，
    // 非 API 平台走 PlaywrightMcpTool 浏览器抓取。
    // 公共 API (clientVisibilityData/dashboardOverview/brandTop5Share/brandCompare/runningWords/collectedWords) 保持不变。

    // ─── P4 新增：AI 数据大屏 + 竞争力报告 ───────────────

    /**
     * AI数据大屏总览KPI数据。
     */
    public function dashboardOverview(int $workspaceId): array
    {
        $today = Carbon::today();
        $lastMonth = $today->copy()->subDays(30);

        $recentSnapshots = AiVisibilitySnapshot::query()
            ->where('workspace_id', $workspaceId)
            ->where('snapshot_date', '>=', $lastMonth)
            ->get();

        $todaySnapshots = $recentSnapshots->where('snapshot_date', $today->toDateString());
        $yesterdaySnapshots = $recentSnapshots->where('snapshot_date', $today->copy()->subDay()->toDateString());

        $totalMentions = (int) $recentSnapshots->sum('mentioned_count');
        $coveredPlatforms = $recentSnapshots->where('mentioned_count', '>', 0)->unique('ai_platform')->count();
        $brandWords = $recentSnapshots->pluck('top_keywords')->filter()->flatten()->unique()->count();

        $todayMentions = (int) $todaySnapshots->sum('mentioned_count');
        $yesterdayMentions = (int) $yesterdaySnapshots->sum('mentioned_count');
        $trendPercent = $yesterdayMentions > 0
            ? round((($todayMentions - $yesterdayMentions) / $yesterdayMentions) * 100)
            : 0;

        return [
            'total_mentions' => $totalMentions,
            'covered_platforms' => $coveredPlatforms,
            'total_platforms' => count(self::AI_PLATFORMS),
            'brand_words' => max($brandWords, 1),
            'today_mentions' => $todayMentions,
            'trend_percent' => $trendPercent,
            'trend_direction' => $trendPercent > 0 ? 'up' : ($trendPercent < 0 ? 'down' : 'flat'),
        ];
    }

    /**
     * 品牌词TOP5占比数据。
     */
    public function brandTop5Share(int $workspaceId): array
    {
        $last30d = Carbon::today()->subDays(30);

        $checks = AiVisibilityCheck::query()
            ->where('workspace_id', $workspaceId)
            ->where('checked_at', '>=', $last30d)
            ->where('mentioned', true)
            ->get();

        $total = $checks->count();
        if ($total === 0) {
            return [];
        }

        $byKeyword = [];
        foreach ($checks as $c) {
            $kw = (string) ($c->query_keyword ?: '未知');
            if ($kw === '') { $kw = '未知'; }
            if (! isset($byKeyword[$kw])) {
                $byKeyword[$kw] = ['word' => $kw, 'count' => 0, 'platforms' => []];
            }
            $byKeyword[$kw]['count'] = (int) ($byKeyword[$kw]['count'] ?? 0) + 1;
            $byKeyword[$kw]['platforms'][] = $c->ai_platform;
        }

        // 按提及次数排序取TOP5
        uasort($byKeyword, fn($a, $b) => $b['count'] <=> $a['count']);
        $top5 = array_slice($byKeyword, 0, 5);

        return array_map(fn($item) => [
            'word' => $item['word'],
            'count' => $item['count'],
            'share' => round(($item['count'] / $total) * 100),
            'platforms' => count(array_unique($item['platforms'])),
        ], $top5);
    }

    /**
     * 品牌 vs 竞品对比分析。
     */
    public function brandCompare(int $workspaceId): array
    {
        $profile = \App\Models\EnterpriseProfile::query()->where('workspace_id', $workspaceId)->first();
        $brandName = $profile?->company_full_name
            ?: Workspace::find($workspaceId)?->name
                ?: '我的品牌';

        $competitors = \App\Models\AiCompetitor::query()
            ->where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->get();

        $last30d = Carbon::today()->subDays(30);
        $snapshots = AiVisibilitySnapshot::query()
            ->where('workspace_id', $workspaceId)
            ->where('snapshot_date', '>=', $last30d)
            ->get();

        // 自身品牌数据
        $selfStats = $this->computeBrandStats($snapshots);
        $selfScore = $snapshots->avg('visibility_score') ?: 0;

        // 竞品数据（BETA：当前为基于自身数据的算法模拟，非真实独立检测）
        $competitorResults = [];
        foreach ($competitors as $comp) {
            $competitorResults[] = [
                'id' => (int) $comp->id,
                'brand_name' => $comp->brand_name,
                'brand_website' => $comp->brand_website,
                'total_mentions' => max(0, (int) ($selfStats['total_mentioned'] * (0.3 + mt_rand(0, 70) / 100))),
                'covered_platforms' => max(1, (int) ($selfStats['covered_platforms'] * (0.4 + mt_rand(0, 60) / 100))),
                'top3_share' => round(max(5, $selfStats['top3_share'] * (0.5 + mt_rand(0, 50) / 100))),
                'is_simulated' => true,  // 标记为模拟数据，前端需展示 Beta 标签
            ];
        }

        return [
            'self' => [
                'brand_name' => $brandName,
                'total_mentions' => (int) ($selfStats['total_mentioned'] ?? 0),
                'covered_platforms' => (int) ($selfStats['covered_platforms'] ?? 0),
                'top3_share' => round((float) ($selfStats['top3_share'] ?? 0)),
                'visibility_score' => round((float) $selfScore, 2),
            ],
            'competitors' => $competitorResults,
            'competitor_data_simulated' => true,  // 顶层标记：竞品数据为模拟估算
            'platform_comparison' => $this->buildPlatformComparison($snapshots, $workspaceId),
        ];
    }

    /**
     * @return array{total_mentioned:int, covered_platforms:int, top3_share:float}
     */
    private function computeBrandStats(\Illuminate\Support\Collection $snapshots): array
    {
        $totalMentioned = (int) $snapshots->sum('mentioned_count');
        $totalQueries = (int) $snapshots->sum('total_queries');
        $coveredPlatforms = $snapshots->where('mentioned_count', '>', 0)->unique('ai_platform')->count();

        $top3Share = $totalQueries > 0
            ? ($totalMentioned / $totalQueries) * 100
            : 0;

        return [
            'total_mentioned' => $totalMentioned,
            'covered_platforms' => $coveredPlatforms,
            'top3_share' => $top3Share,
        ];
    }

    /**
     * 按平台对比数据。
     */
    private function buildPlatformComparison(\Illuminate\Support\Collection $snapshots, int $workspaceId): array
    {
        $result = [];
        foreach (array_keys(self::AI_PLATFORMS) as $platform) {
            $platformSnaps = $snapshots->where('ai_platform', $platform);
            $result[] = [
                'platform' => $platform,
                'name' => self::AI_PLATFORMS[$platform]['name'] ?? $platform,
                'icon' => self::AI_PLATFORMS[$platform]['icon'] ?? '🤖',
                'color' => self::AI_PLATFORMS[$platform]['color'] ?? '#6b7280',
                'score' => round((float) ($platformSnaps->avg('visibility_score') ?? 0), 1),
                'mentioned' => (int) $platformSnaps->sum('mentioned_count'),
            ];
        }

        return $result;
    }

    // ─── 原有私有方法 ──────────────────────────────────

    /**
     * 正在监测中的关键词（近30天有检测记录）。
     */
    public function runningWords(int $workspaceId): array
    {
        return AiVisibilityCheck::query()
            ->where('workspace_id', $workspaceId)
            ->where('checked_at', '>=', Carbon::today()->subDays(30))
            ->select('query_keyword')
            ->selectRaw('COUNT(*) as total_checks')
            ->selectRaw("SUM(CASE WHEN mentioned THEN 1 ELSE 0 END) as mentioned_count")
            ->selectRaw('COUNT(DISTINCT ai_platform) as platform_count')
            ->groupBy('query_keyword')
            ->orderByDesc('total_checks')
            ->limit(20)
            ->get()
            ->map(fn($r) => [
                'word' => $r->query_keyword,
                'total' => (int) $r->total_checks,
                'mentioned' => (int) $r->mentioned_count,
                'platforms' => (int) $r->platform_count,
                'status' => ($r->mentioned_count > 0) ? 'collected' : 'running',
            ])
            ->all();
    }

    /**
     * 已被AI收录的关键词（mention > 0）。
     */
    public function collectedWords(int $workspaceId): array
    {
        return AiVisibilityCheck::query()
            ->where('workspace_id', $workspaceId)
            ->where('checked_at', '>=', Carbon::today()->subDays(30))
            ->where('mentioned', true)
            ->select('query_keyword')
            ->selectRaw('COUNT(*) as mention_count')
            ->selectRaw('COUNT(DISTINCT ai_platform) as platform_count')
            ->groupBy('query_keyword')
            ->orderByDesc('mention_count')
            ->limit(20)
            ->get()
            ->map(fn($r) => [
                'word' => $r->query_keyword,
                'mentions' => (int) $r->mention_count,
                'platforms' => (int) $r->platform_count,
            ])
            ->all();
    }

    private function aggregateChecks(int $workspaceId, string $platform, Carbon $date): array
    {
        $checks = AiVisibilityCheck::query()
            ->where('workspace_id', $workspaceId)
            ->where('ai_platform', $platform)
            ->whereDate('checked_at', $date)
            ->get();

        $topKeywords = $checks
            ->where('mentioned', true)
            ->pluck('query_keyword')
            ->unique()
            ->take(5)
            ->values()
            ->all();

        return [
            'total' => $checks->count(),
            'mentioned' => $checks->where('mentioned', true)->count(),
            'top_keywords' => $topKeywords,
        ];
    }
}
