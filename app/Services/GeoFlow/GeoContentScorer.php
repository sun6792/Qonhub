<?php

namespace App\Services\GeoFlow;

/**
 * GEO 内容质量评分引擎。
 *
 * 基于 geoskills citability 评分标准，对文章内容做自动化评分。
 * 评分维度对齐 geoskills 四个子维度，权重已校准。
 *
 * 使用场景：
 *   - 文章发布前自动评分，低分提示改写
 *   - 弹药库 AI 改写后对比新旧分数
 *   - 客户端展示文章 GEO 质量
 */
class GeoContentScorer
{
    // ── 虚词库（geoskills hedge-words）───────────────────

    private const HEDGE_WORDS = [
        'uncertainty' => ['可能', '也许', '或许', '大概', '或许会', '说不定'],
        'distancing' => ['似乎', '显得', '倾向', '表明', '看似', '貌似'],
        'weakening' => ['有点', '某种程度上', '一定程度上', '可以说', '值得注意的是'],
        'qualification' => ['相对', '比较', '还算', '颇为', '相当'],
        'approximation' => ['大约', '左右', '将近', '差不多', '约', '几乎'],
        'generalization' => ['通常', '往往', '一般', '经常', '有时', '大多数情况下'],
    ];

    private const DATA_PATTERNS = [
        '/\d+\.?\d*%/',
        '/\d+\.?\d*\s*(万|亿|千|百|倍)/',
        '/\d{2,4}\s*年/',
        '/\d+\.?\d*\s*(吨|公斤|克|米|厘米|毫米|升|毫升)/',
    ];

    // ── 评分权重 ─────────────────────────────────────────

    private const WEIGHTS = [
        'answer_quality' => 0.20,    // Q&A结构
        'self_containment' => 0.18,  // 自包含性
        'statistical_density' => 0.17, // 数据密度
        'structural_clarity' => 0.17, // 结构清晰度
        'expertise_signals' => 0.13,  // 专家信号
        'hedge_penalty' => 0.15,      // 虚词扣分（反向权重）
    ];

    // ── 公开方法 ─────────────────────────────────────────

    /**
     * 对文章内容做全面 GEO 评分。
     *
     * @return array{score:int, grade:string, dimensions:array, suggestions:array, hedge_count:int, data_count:int}
     */
    public function score(string $title, string $content): array
    {
        $title = trim($title);
        $content = trim(strip_tags($content));
        $fullText = $title . "\n" . $content;
        $wordCount = $this->wordCount($fullText);
        $paragraphs = $this->splitParagraphs($content);

        // 各维度评分
        $answerQuality = $this->scoreAnswerQuality($content, $title);
        $selfContainment = $this->scoreSelfContainment($paragraphs, $wordCount);
        $statisticalDensity = $this->scoreStatisticalDensity($fullText, $wordCount);
        $structuralClarity = $this->scoreStructuralClarity($content, $paragraphs);
        $expertiseSignals = $this->scoreExpertiseSignals($fullText);
        $hedgeAnalysis = $this->analyzeHedgeWords($fullText);

        // 虚词扣分
        $hedgeScore = $this->hedgePenaltyToScore($hedgeAnalysis['density']);

        // 加权合成
        $rawScore = round(
            $answerQuality * self::WEIGHTS['answer_quality']
            + $selfContainment * self::WEIGHTS['self_containment']
            + $statisticalDensity * self::WEIGHTS['statistical_density']
            + $structuralClarity * self::WEIGHTS['structural_clarity']
            + $expertiseSignals * self::WEIGHTS['expertise_signals']
            + $hedgeScore * self::WEIGHTS['hedge_penalty']
        );

        $score = max(0, min(100, $rawScore));

        return [
            'score' => $score,
            'grade' => $this->grade($score),
            'dimensions' => [
                'answer_quality' => $answerQuality,
                'self_containment' => $selfContainment,
                'statistical_density' => $statisticalDensity,
                'structural_clarity' => $structuralClarity,
                'expertise_signals' => $expertiseSignals,
                'hedge_score' => $hedgeScore,
            ],
            'suggestions' => $this->generateSuggestions($answerQuality, $statisticalDensity, $structuralClarity, $hedgeAnalysis, $expertiseSignals),
            'hedge_count' => $hedgeAnalysis['total'],
            'hedge_density' => round($hedgeAnalysis['density'], 2),
            'data_count' => $this->countDataPoints($fullText),
        ];
    }

    /**
     * 快速评分（仅返回分数）。
     */
    public function quickScore(string $title, string $content): int
    {
        return $this->score($title, $content)['score'];
    }

    /**
     * 两篇文章对比，返回提升幅度。
     */
    public function compare(string $oldContent, string $newContent): array
    {
        $oldScore = $this->quickScore('', $oldContent);
        $newScore = $this->quickScore('', $newContent);

        return [
            'old_score' => $oldScore,
            'new_score' => $newScore,
            'improvement' => $newScore - $oldScore,
            'grade_change' => $this->grade($oldScore) . ' → ' . $this->grade($newScore),
        ];
    }

    // ── 子维度评分方法 ───────────────────────────────────

    private function scoreAnswerQuality(string $content, string $title): int
    {
        $score = 0;

        // Q&A 模式检测（？ + 紧接的陈述句）
        $qaCount = preg_match_all('/[？?]\s*.{10,80}[。.]/u', $content, $m);
        $score += min(40, $qaCount * 8);

        // 定义句式（"XX 是..."）
        $defCount = preg_match_all('/[：:]\s*.{5,60}是.{5,}/u', $content, $m);
        $score += min(25, $defCount * 5);

        // 开篇直接回答（前 100 字内出现结论性语句）
        $first100 = mb_substr($content, 0, 100);
        if (preg_match('/[。.]\s*[^。.]*[是为].{3,}/u', $first100)) {
            $score += 20;
        }

        // FAQ 结构
        if (mb_strpos($content, 'Q') !== false || mb_strpos($content, '常见问题') !== false) {
            $score += 15;
        }

        return min(100, $score);
    }

    private function scoreSelfContainment(array $paragraphs, int $wordCount): int
    {
        if (empty($paragraphs)) {
            return 20;
        }
        if ($wordCount < 100) {
            return 30;
        }

        $score = 30;

        // 最优段落长度：134-167 词（geo-seo-claude 研究，AI 引用率最高）
        foreach ($paragraphs as $p) {
            $len = mb_strlen($p);
            if ($len >= 134 && $len <= 167) {
                $score += 25;
                break;
            } elseif ($len >= 100 && $len <= 200) {
                $score += 18;
                break;
            } elseif ($len >= 80 && $len <= 250) {
                $score += 10;
                break;
            }
        }

        // 代词密度检测（代词越少 = 越自包含，AI 更喜欢）
        $pronounCount = preg_match_all('/\b(它|他们|她们|它们|这个|那个|这些|那些|他|她)\b/u', $content ?? '', $m);
        $pronounRatio = $wordCount > 0 ? $pronounCount / $wordCount : 0;
        if ($pronounRatio < 0.02) {
            $score += 25;
        } elseif ($pronounRatio < 0.04) {
            $score += 15;
        }

        // 有术语解释
        if (preg_match('/即[：:]/u', $content ?? '') || preg_match('/是指/u', $content ?? '')) {
            $score += 20;
        }

        return min(100, $score);
    }

    private function scoreStatisticalDensity(string $text, int $wordCount): int
    {
        $dataCount = $this->countDataPoints($text);
        $densityPer1k = $wordCount > 0 ? ($dataCount / $wordCount) * 1000 : 0;

        if ($densityPer1k >= 8) {
            return 90;
        }
        if ($densityPer1k >= 5) {
            return 70;
        }
        if ($densityPer1k >= 3) {
            return 50;
        }
        if ($densityPer1k >= 1) {
            return 30;
        }

        return 10;
    }

    private function scoreStructuralClarity(string $content, array $paragraphs): int
    {
        $score = 20;

        // 标题层级
        $h2Count = preg_match_all('/^#{2,3}\s/m', $content, $m);
        $score += min(35, $h2Count * 8);

        // 列表
        $listCount = preg_match_all('/^[-*]\s/m', $content, $m);
        $score += min(20, $listCount * 5);

        // 段落长度
        $longParaCount = 0;
        foreach ($paragraphs as $p) {
            if (mb_strlen($p) > 300) {
                $longParaCount++;
            }
        }
        $score -= min(15, $longParaCount * 5);

        // 内容分段
        if (count($paragraphs) >= 3) {
            $score += 20;
        }

        return max(0, min(100, $score));
    }

    private function scoreExpertiseSignals(string $text): int
    {
        $score = 10;

        // 引号内的专家引言
        if (preg_match_all('/“[^”]{10,}”/u', $text, $m) >= 1) {
            $score += 30;
        }

        // "某某专家/创始人/博士 表示"
        if (preg_match('/[（(].{2,8}[)）]\s*表示/u', $text) || preg_match('/[专家|创始人|博士|教授|工程师|主任]表示/u', $text)) {
            $score += 25;
        }

        // 数据来源引用
        if (preg_match('/据.{2,10}(报道|统计|数据显示|年报|研究)/u', $text)) {
            $score += 25;
        }

        // 日期/更新时间
        if (preg_match('/\d{4}[年-]\d{1,2}[月-]\d{1,2}[日号]/u', $text)) {
            $score += 10;
        }

        return min(100, $score);
    }

    /**
     * @return array{total:int, details:array, density:float}
     */
    private function analyzeHedgeWords(string $text): array
    {
        $total = 0;
        $details = [];
        $wordCount = $this->wordCount($text);

        foreach (self::HEDGE_WORDS as $category => $words) {
            $count = 0;
            foreach ($words as $word) {
                $count += mb_substr_count($text, $word);
            }
            if ($count > 0) {
                $details[$category] = $count;
                $total += $count;
            }
        }

        return [
            'total' => $total,
            'details' => $details,
            'density' => $wordCount > 0 ? ($total / $wordCount) * 100 : 0,
        ];
    }

    private function hedgePenaltyToScore(float $density): int
    {
        if ($density < 0.3) {
            return 95;
        }
        if ($density < 0.5) {
            return 80;
        }
        if ($density < 1.0) {
            return 60;
        }
        if ($density < 2.0) {
            return 30;
        }

        return 10;
    }

    // ── 辅助方法 ─────────────────────────────────────────

    private function wordCount(string $text): int
    {
        $text = trim(preg_replace('/\s+/u', '', $text));

        return $text === '' ? 0 : mb_strlen($text, 'UTF-8');
    }

    /**
     * @return array<int, string>
     */
    private function splitParagraphs(string $content): array
    {
        $paragraphs = preg_split('/\n{2,}/', trim($content));

        return array_values(array_filter($paragraphs ?: [], fn ($p) => trim((string) $p) !== ''));
    }

    private function countDataPoints(string $text): int
    {
        $count = 0;
        foreach (self::DATA_PATTERNS as $pattern) {
            $count += preg_match_all($pattern, $text);
        }

        return $count;
    }

    private function grade(int $score): string
    {
        return match (true) {
            $score >= 85 => 'A',
            $score >= 70 => 'B',
            $score >= 50 => 'C',
            $score >= 30 => 'D',
            default => 'F',
        };
    }

    /**
     * @return array<int, string>
     */
    private function generateSuggestions(int $answerQuality, int $statisticalDensity, int $structuralClarity, array $hedgeAnalysis, int $expertiseSignals): array
    {
        $suggestions = [];

        if ($answerQuality < 50) {
            $suggestions[] = '增加 Q&A 问答段落，用"问题是...答案是..."的格式，AI 最喜欢引用这种结构';
        }
        if ($statisticalDensity < 50) {
            $suggestions[] = '补充具体数据：百分比、年份、数量、对比数值。有数据的段落被 AI 引用概率提升 30%';
        }
        if ($hedgeAnalysis['density'] > 0.5) {
            $suggestions[] = '删除或替换虚词（如"可能""大概""似乎"），改用确定性的表述。虚词密度超过 0.5% 会显著降低 AI 引用率';
        }
        if ($structuralClarity < 50) {
            $suggestions[] = '增加 H2/H3 小标题和列表，让 AI 更容易理解文章结构';
        }
        if ($expertiseSignals < 40) {
            $suggestions[] = '加入专家引言或数据来源引用，有引用的文章被 AI 引用率提升 41%';
        }

        return $suggestions;
    }
}
