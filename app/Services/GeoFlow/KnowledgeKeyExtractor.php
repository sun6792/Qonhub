<?php

namespace App\Services\GeoFlow;

use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;

/**
 * 知识库关键数据定向提取器。
 *
 * 从客户上传的文档中按 geoskills 维度提取：
 *   1. 统计数据（百分比、数值、年份、单位）
 *   2. Q&A 对（问题+答案的完整摘录）
 *   3. 专家/权威信号（引用、称号、机构名）
 *   4. 企业关键事实（NAP、资质、产品参数）
 *
 * 提取结果作为 AI 写作时的"必用素材"注入 Prompt，
 * 替代随机检索，确保文章包含客户文档里的核心数据。
 *
 * @property-read array{stats:array, qa_pairs:array, expertise:array, facts:array, summary:string}
 */
class KnowledgeKeyExtractor
{
    /**
     * 从知识库提取所有维度的关键数据。
     */
    public function extract(KnowledgeBase $kb): array
    {
        $chunks = KnowledgeChunk::query()
            ->where('knowledge_base_id', (int) $kb->id)
            ->orderBy('chunk_index')
            ->get();

        $fullText = $kb->content ?: '';
        if ($fullText === '' && $chunks->isNotEmpty()) {
            $fullText = $chunks->pluck('content')->implode("\n\n");
        }

        return [
            'stats' => $this->extractStats($fullText),
            'qa_pairs' => $this->extractQAPairs($fullText),
            'expertise' => $this->extractExpertise($fullText),
            'facts' => $this->extractFacts($fullText),
            'summary' => $this->buildSummary($fullText),
        ];
    }

    /**
     * 生成用于注入 AI Prompt 的结构化素材文本。
     */
    public function toPromptContext(array $extracted, int $maxChars = 3000): string
    {
        $parts = [];

        if (! empty($extracted['facts'])) {
            $parts[] = "【企业关键信息】\n" . implode("\n", array_slice($extracted['facts'], 0, 8));
        }

        if (! empty($extracted['stats'])) {
            $parts[] = "【核心数据】\n" . implode("\n", array_slice($extracted['stats'], 0, 6));
        }

        if (! empty($extracted['qa_pairs'])) {
            $pairs = array_slice($extracted['qa_pairs'], 0, 3);
            $qaText = '';
            foreach ($pairs as $qa) {
                $qaText .= "Q: {$qa['q']}\nA: {$qa['a']}\n\n";
            }
            $parts[] = "【常见问答】\n" . trim($qaText);
        }

        if (! empty($extracted['expertise'])) {
            $parts[] = "【权威背书】\n" . implode("\n", array_slice($extracted['expertise'], 0, 5));
        }

        $context = implode("\n\n", $parts);

        return mb_substr($context, 0, $maxChars);
    }

    // ── 提取器 ───────────────────────────────────────────

    /**
     * @return array<int, string>
     */
    private function extractStats(string $text): array
    {
        $stats = [];
        $lines = preg_split('/\n+/', $text);

        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            // 匹配含数值+单位的句子
            if (preg_match('/\d+\.?\d*%\s*[^。.]*[。.]/u', $line, $m)) {
                $stats[] = $m[0];
            } elseif (preg_match('/\d+\.?\d*\s*(万|亿|吨|公斤|克|米|cm|mm|升|倍|次|年|月|天|小时|分钟|秒|元)\s*[^。.]*[。.]/u', $line, $m)) {
                $stats[] = $m[0];
            } elseif (preg_match('/超过\d+|达到\d+|突破\d+|增长\d+/u', $line, $m)) {
                $stats[] = $line;
            }
        }

        return array_values(array_unique(array_slice($stats, 0, 15)));
    }

    /**
     * @return array<int, array{q:string, a:string}>
     */
    private function extractQAPairs(string $text): array
    {
        $pairs = [];

        // 模式1: Q: ... A: ...
        preg_match_all('/Q[：:]\s*(.{5,80})\s*A[：:]\s*(.{10,300})/iu', $text, $m1, PREG_SET_ORDER);
        foreach ($m1 as $match) {
            $pairs[] = ['q' => trim($match[1]), 'a' => trim($match[2])];
        }

        // 模式2: ？后面紧跟的陈述
        preg_match_all('/(.{5,40})[？?]\s*\n?\s*(.{10,200})[。.]/u', $text, $m2, PREG_SET_ORDER);
        foreach ($m2 as $match) {
            if (count($pairs) < 10) {
                $pairs[] = ['q' => trim($match[1]) . '？', 'a' => trim($match[2])];
            }
        }

        // 模式3: 标题作为问题，紧跟段落作为答案
        preg_match_all('/^#{1,3}\s+(.{5,50})\s*\n\s*(.{20,300})/mu', $text, $m3, PREG_SET_ORDER);
        foreach ($m3 as $match) {
            if (count($pairs) < 15 && mb_strpos($match[1], '？') === false && mb_strpos($match[1], '?') === false) {
                $pairs[] = ['q' => '什么是' . trim($match[1]) . '？', 'a' => trim($match[2])];
            }
        }

        return array_slice($pairs, 0, 10);
    }

    /**
     * @return array<int, string>
     */
    private function extractExpertise(string $text): array
    {
        $signals = [];

        // 专家引言
        if (preg_match_all('/“[^”]{15,}”/u', $text, $m)) {
            foreach ($m[0] as $quote) {
                $signals[] = '专家引言：' . $quote;
            }
        }

        // 称号/职位
        $titles = ['教授', '博士', '院士', '专家', '创始人', '工程师', '主任'];
        foreach ($titles as $title) {
            if (preg_match_all('/[^。.]*' . $title . '[^。.]*[。.]/u', $text, $m)) {
                foreach ($m[0] as $sentence) {
                    if (! in_array($sentence, $signals, true)) {
                        $signals[] = $sentence;
                    }
                }
            }
        }

        // 机构/合作
        if (preg_match_all('/(合作|联合|共建|授权|认证|指定|推荐).{3,20}(单位|机构|实验室|中心|基地|平台)/u', $text, $m)) {
            foreach ($m[0] as $match) {
                $signals[] = '机构认证：' . $match;
            }
        }

        // 专利/证书
        if (preg_match_all('/(专利|著作权|商标|认证|许可证|资质).{3,20}(号|证书|编号)/u', $text, $m)) {
            foreach ($m[0] as $match) {
                $signals[] = '知识产权：' . $match;
            }
        }

        return array_values(array_unique(array_slice($signals, 0, 10)));
    }

    /**
     * @return array<int, string>
     */
    private function extractFacts(string $text): array
    {
        $facts = [];

        // 公司名
        if (preg_match('/(.{2,20}(有限公司|有限责任公司|股份有限公司|集团|科技|实业).{0,10})/u', $text, $m)) {
            $facts[] = '公司名称：' . trim($m[1]);
        }

        // 地址
        if (preg_match('/(.{2,10}(省|市|区|县|镇).{5,50}(号|楼|层|室|栋|大厦|产业园|工业园))/u', $text, $m)) {
            $facts[] = '公司地址：' . trim($m[1]);
        }

        // 电话
        if (preg_match('/(\d{3,4}[-\s]?\d{7,11})/', $text, $m)) {
            $facts[] = '联系电话：' . $m[1];
        }

        // 成立年份
        if (preg_match('/(成立[于在]|创立[于在]|始建于|创办于).{0,5}(\d{4})/u', $text, $m)) {
            $facts[] = '成立年份：' . $m[2] . '年';
        }

        // 产品/服务
        if (preg_match_all('/(主营|主要[经营产]).{2,30}([。.；;])/u', $text, $m)) {
            foreach ($m[0] as $line) {
                $facts[] = '经营范围：' . trim((string) $line);
            }
        }

        // 资质
        if (preg_match_all('/(ISO\d+|CE认证|FDA|ROHS|高新技术企业|专精特新|小巨人)/u', $text, $m)) {
            $facts[] = '企业资质：' . implode('、', array_unique($m[0]));
        }

        return array_values(array_unique(array_slice($facts, 0, 10)));
    }

    private function buildSummary(string $text): string
    {
        $first500 = mb_substr($text, 0, 500);

        return $first500;
    }
}
