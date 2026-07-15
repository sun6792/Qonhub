<?php

namespace App\Services\Agent\Tools;

use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Services\Agent\AgentToolInterface;

/**
 * 关键词库查询工具 — 封装 Keyword/KeywordLibrary 模型。
 */
class KeywordLibraryTool implements AgentToolInterface
{
    public function getName(): string
    {
        return 'keyword_library';
    }

    public function getDescription(): string
    {
        return '查询关键词库中的关键词列表，支持按词库ID、搜索词过滤。返回关键词文本、搜索量、竞争度等信息。';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'library_id' => ['type' => 'integer', 'description' => '词库ID（可选，不传则返回全部词库）'],
                'search' => ['type' => 'string', 'description' => '关键词搜索过滤'],
                'limit' => ['type' => 'integer', 'description' => '返回数量上限，默认20'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $args, int $workspaceId): array
    {
        $libraryId = (int) ($args['library_id'] ?? 0);
        $search = (string) ($args['search'] ?? '');
        $limit = min((int) ($args['limit'] ?? 20), 50);

        $keywordQuery = Keyword::query()->orderByDesc('id')->limit($limit);

        if ($libraryId > 0) {
            $keywordQuery->where('keyword_library_id', $libraryId);
        }
        if ($search !== '') {
            $keywordQuery->where('keyword', 'ilike', "%{$search}%");
        }

        $keywords = $keywordQuery->get(['id', 'keyword', 'search_volume', 'competition_level', 'keyword_library_id']);

        return [
            'success' => true,
            'data' => [
                'total' => $keywords->count(),
                'keywords' => $keywords->toArray(),
            ],
        ];
    }
}
