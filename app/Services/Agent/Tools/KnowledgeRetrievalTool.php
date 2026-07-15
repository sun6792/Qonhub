<?php

namespace App\Services\Agent\Tools;

use App\Services\Agent\AgentToolInterface;
use App\Services\GeoFlow\KnowledgeRetrievalService;

/**
 * 知识库 RAG 检索工具 — 封装 KnowledgeRetrievalService。
 */
class KnowledgeRetrievalTool implements AgentToolInterface
{
    public function __construct(
        private readonly KnowledgeRetrievalService $retrievalService,
    ) {}

    public function getName(): string
    {
        return 'knowledge_retrieval';
    }

    public function getDescription(): string
    {
        return '从知识库中检索与查询相关的文档片段，支持多知识库混合检索（pgvector语义搜索+关键词匹配+元数据权威评分），返回带来源标注的证据片段。';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => '检索查询文本'],
                'knowledge_base_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => '知识库ID列表'],
                'limit' => ['type' => 'integer', 'description' => '返回结果数量上限，默认5'],
            ],
            'required' => ['query', 'knowledge_base_ids'],
        ];
    }

    public function execute(array $args, int $workspaceId): array
    {
        $kbIds = array_map('intval', $args['knowledge_base_ids'] ?? []);
        $query = (string) ($args['query'] ?? '');
        $limit = (int) ($args['limit'] ?? 5);

        if ($kbIds === [] || $query === '') {
            return ['success' => false, 'data' => null, 'error' => '缺少必要参数'];
        }

        $context = $this->retrievalService->retrieveContextFromMany(
            $kbIds,
            $query,
            limit: min($limit, 10),
            maxChars: 3200
        );

        return [
            'success' => true,
            'data' => [
                'context' => $context,
                'kb_count' => count($kbIds),
            ],
        ];
    }
}
