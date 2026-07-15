<?php

namespace App\Services\Agent\Tools;

use App\Models\SensitiveWord;
use App\Services\Agent\AgentToolInterface;

/**
 * 敏感词检测工具 — 封装 SensitiveWord 模型。
 */
class SensitiveWordTool implements AgentToolInterface
{
    public function getName(): string
    {
        return 'sensitive_word_check';
    }

    public function getDescription(): string
    {
        return '检测文章内容中的敏感词和违规用语，返回检测到的敏感词列表和风险等级。';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'description' => '文章标题'],
                'content' => ['type' => 'string', 'description' => '文章正文'],
            ],
            'required' => ['content'],
        ];
    }

    public function execute(array $args, int $workspaceId): array
    {
        $title = (string) ($args['title'] ?? '');
        $content = (string) ($args['content'] ?? '');
        $fullText = $title . "\n" . $content;

        $hits = SensitiveWord::query()
            ->where('status', 'active')
            ->get()
            ->filter(fn (SensitiveWord $word) => stripos($fullText, $word->word) !== false)
            ->map(fn (SensitiveWord $word) => $word->word)
            ->values()
            ->all();

        $riskLevel = count($hits) === 0 ? 'safe' : (count($hits) > 3 ? 'high' : 'medium');

        return [
            'success' => true,
            'data' => [
                'risk_level' => $riskLevel,
                'hits_count' => count($hits),
                'hits' => $hits,
                'passed' => count($hits) === 0,
            ],
        ];
    }
}
