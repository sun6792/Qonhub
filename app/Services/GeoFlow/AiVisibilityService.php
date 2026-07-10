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
        'deepseek' => ['name' => 'DeepSeek', 'prompt' => '请简要回答以下问题，并尽可能引用信息来源：', 'icon' => '🔵', 'color' => '#4F46E5'],
        'doubao' => ['name' => '豆包', 'prompt' => '请回答以下问题：', 'icon' => '🟢', 'color' => '#22C55E'],
        'wenxin' => ['name' => '文心一言', 'prompt' => '请回答以下问题：', 'icon' => '🟣', 'color' => '#8B5CF6'],
        'kimi' => ['name' => 'Kimi', 'prompt' => '请回答以下问题，并标注信息来源：', 'icon' => '🌙', 'color' => '#6366F1'],
        'qianwen' => ['name' => '通义千问', 'prompt' => '请回答以下问题：', 'icon' => '🟠', 'color' => '#F97316'],
        'yuanbao' => ['name' => '腾讯元宝', 'prompt' => '请回答以下问题：', 'icon' => '💎', 'color' => '#06B6D4'],
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
