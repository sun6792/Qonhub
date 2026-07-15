<?php

namespace App\Services\Agent\Tools;

use App\Services\Agent\AgentToolInterface;
use App\Services\GeoFlow\GeoContentScorer;

/**
 * GEO 评分工具 — 封装 GeoContentScorer。
 */
class GeoScoreTool implements AgentToolInterface
{
    public function __construct(
        private readonly GeoContentScorer $scorer,
    ) {}

    public function getName(): string
    {
        return 'geo_score';
    }

    public function getDescription(): string
    {
        return '对文章内容和标题进行六维GEO评分（0-100分），返回各维度得分、总分、等级和改进建议。低于70分需要重写。';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'description' => '文章标题'],
                'content' => ['type' => 'string', 'description' => '文章正文（Markdown格式）'],
            ],
            'required' => ['title', 'content'],
        ];
    }

    public function execute(array $args, int $workspaceId): array
    {
        $result = $this->scorer->score(
            (string) ($args['title'] ?? ''),
            (string) ($args['content'] ?? '')
        );

        return [
            'success' => true,
            'data' => [
                'score' => $result['score'] ?? 0,
                'grade' => $result['grade'] ?? 'F',
                'dimensions' => $result['dimensions'] ?? [],
                'suggestions' => $result['suggestions'] ?? [],
            ],
        ];
    }
}
