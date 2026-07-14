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
        'nami_ai'      => ['name' => '纳米AI',     'icon' => '🧬', 'color' => '#10B981', 'url' => 'https://www.nami.com/',              'pc' => true, 'mobile' => false],
        'baidu_ai'     => ['name' => '百度AI搜索',  'icon' => '🔍', 'color' => '#2563EB', 'url' => 'https://chat.baidu.com/search',      'pc' => true, 'mobile' => true],
        'wechat_ai'    => ['name' => '微信AI',     'icon' => '💬', 'color' => '#07C160', 'url' => 'https://weixin.qq.com/',             'pc' => false, 'mobile' => true],
        'douyin_ai'    => ['name' => '抖音AI',     'icon' => '🎵', 'color' => '#000000', 'url' => 'https://www.douyin.com/',            'pc' => false, 'mobile' => true],
        'quark_ai'     => ['name' => '夸克AI',     'icon' => '⚛️', 'color' => '#FF6A00', 'url' => 'https://ai.quark.cn/',               'pc' => true, 'mobile' => true],
    ];

    /**
     * @deprecated 使用 self::AI_PLATFORMS
     */
    private const AI_PLATFORM_PROMPTS = [
        'deepseek' => '请简要回答以下问题，并引用你参考的信息来源：',
        'doubao' => '请回答以下问题：',
        'wenxin' => '请回答以下问题：',
        'kimi' => '请回答以下问题：',
        'qianwen' => '请回答以下问题：',
        'yuanbao' => '请回答以下问题：',
    ];

    private const MAX_QUERIES_PER_RUN = 50;
    private const QUERY_DELAY_MS = 1500;

    public function __construct(
        private readonly ApiKeyCrypto $apiKeyCrypto,
    ) {}

    /**
     * 对单个工作空间执行AI引用检测。
     *
     * @return array{total:int, mentioned:int, checks:list<array>}
     */
    public function checkWorkspace(Workspace $workspace): array
    {
        $keywords = $workspace->brandKeywordList();
        $brandName = $workspace->client_company_name ?: $workspace->name;

        if ($keywords === []) {
            return ['total' => 0, 'mentioned' => 0, 'checks' => []];
        }

        $aiModel = $this->resolveAiModel();
        if (! $aiModel) {
            return ['total' => 0, 'mentioned' => 0, 'checks' => []];
        }

        $checks = [];
        $mentioned = 0;
        $count = 0;

        foreach (array_keys(self::AI_PLATFORMS) as $platform) {
            foreach (array_slice($keywords, 0, 3) as $keyword) {
                if ($count >= self::MAX_QUERIES_PER_RUN) {
                    break 2;
                }

                $queryText = $this->buildQuery($platform, $brandName, $keyword);

                try {
                    $response = $this->queryAi($aiModel, $queryText);
                    $isMentioned = $this->detectMention($response, $brandName);
                    $snippet = $isMentioned ? $this->extractSnippet($response, $brandName) : null;

                    $check = AiVisibilityCheck::query()->create([
                        'workspace_id' => (int) $workspace->id,
                        'ai_platform' => $platform,
                        'query_keyword' => $keyword,
                        'query_text' => $queryText,
                        'mentioned' => $isMentioned,
                        'mention_type' => $isMentioned ? 'brand_name' : null,
                        'response_snippet' => $snippet,
                        'checked_at' => now(),
                    ]);

                    $checks[] = [
                        'platform' => $platform,
                        'keyword' => $keyword,
                        'mentioned' => $isMentioned,
                    ];

                    if ($isMentioned) {
                        $mentioned++;
                    }

                    $count++;

                    if ($count < self::MAX_QUERIES_PER_RUN) {
                        usleep(self::QUERY_DELAY_MS * 1000);
                    }
                } catch (Throwable $e) {
                    Log::warning("AI visibility check failed: {$e->getMessage()}", [
                        'workspace_id' => $workspace->id,
                        'platform' => $platform,
                        'keyword' => $keyword,
                    ]);
                }
            }
        }

        return [
            'total' => $count,
            'mentioned' => $mentioned,
            'checks' => $checks,
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

    private function resolveAiModel(): ?AiModel
    {
        return AiModel::query()
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('model_type')->orWhere('model_type', '')->orWhere('model_type', 'chat'))
            ->orderBy('failover_priority')
            ->first();
    }

    private function buildQuery(string $platform, string $brandName, string $keyword): string
    {
        $prefix = self::AI_PLATFORM_PROMPTS[$platform] ?? self::AI_PLATFORM_PROMPTS['deepseek'];

        return "{$prefix}关于{$brandName}在{$keyword}方面有什么特点和优势？";
    }

    /**
     * @return string
     */
    private function queryAi(AiModel $model, string $prompt): string
    {
        $apiKey = $this->apiKeyCrypto->decrypt((string) $model->getRawOriginal('api_key') ?? '');
        $providerUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) ($model->api_url ?? ''));
        $driver = OpenAiRuntimeProvider::resolveChatDriver($providerUrl, (string) ($model->model_id ?? ''));
        $providerName = OpenAiRuntimeProvider::registerProvider('visibility', $driver, $providerUrl, $apiKey);

        $agent = new \App\Ai\Agents\MarkdownContentWriterAgent(
            instructions: '你是一个客观的AI助手，请根据你的知识库如实回答问题。如果不知道就说不确定。',
            maxTokens: 1024,
        );

        $response = $agent->prompt($prompt, [], $providerName, (string) ($model->model_id ?? ''));

        return (string) ($response->text ?? '');
    }

    private function detectMention(string $response, string $brandName): bool
    {
        $response = mb_strtolower($response, 'UTF-8');
        $brandName = mb_strtolower($brandName, 'UTF-8');

        if ($brandName !== '' && str_contains($response, $brandName)) {
            return true;
        }

        // 检查是否包含品牌名的一部分（至少2个字）
        if (mb_strlen($brandName) >= 4) {
            $parts = $this->brandNameTokens($brandName);
            foreach ($parts as $part) {
                if (str_contains($response, $part)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function brandNameTokens(string $brandName): array
    {
        $length = mb_strlen($brandName);
        $tokens = [];
        for ($i = 2; $i <= min(4, $length); $i++) {
            for ($j = 0; $j <= $length - $i; $j++) {
                $tokens[] = mb_substr($brandName, $j, $i);
            }
        }

        return array_values(array_unique($tokens));
    }

    private function extractSnippet(string $response, string $brandName): string
    {
        $pos = mb_stripos($response, $brandName);
        if ($pos === false) {
            return mb_substr($response, 0, 200);
        }

        $start = max(0, $pos - 60);
        $end = min(mb_strlen($response), $pos + mb_strlen($brandName) + 100);

        return mb_substr($response, $start, $end - $start);
    }

    /**
     * @return array{total:int, mentioned:int, top_keywords:list<string>}
     */
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
