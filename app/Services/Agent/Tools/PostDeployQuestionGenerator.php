<?php

namespace App\Services\Agent\Tools;

/**
 * v2.6.0: 文章特化探针问题生成器。
 *
 * 在 DeployAgent 发布完成后，基于已发布文章的信息，用固定模板
 * 生成 3 类标准化探针问题，下发到 Scout 检测队列。
 *
 * 纯 PHP 模板填充，不调用 LLM，零 Token 消耗。
 * 输出格式直接适配 AiVisibilityService 的检测入参。
 */
class PostDeployQuestionGenerator
{
    /**
     * 生成标准化探针问题集。
     *
     * @param  int      $workspaceId  工作空间 ID（租户隔离）
     * @param  string   $brandName    品牌名
     * @param  string   $articleTitle 文章标题
     * @param  string   $keywords     文章关键词（逗号/空格分隔）
     * @param  string   $industry     行业场景（可选，空值时降级为通用检测）
     * @return list<array{query:string, type:string}>
     */
    public function generate(
        int    $workspaceId,
        string $brandName,
        string $articleTitle,
        string $keywords = '',
        string $industry = '',
    ): array {
        // ── 参数校验与降级 ──
        $brandName = trim($brandName);
        $articleTitle = trim($articleTitle);
        $keywords = trim($keywords);
        $industry = trim($industry);

        if ($brandName === '') {
            return $this->fallback($workspaceId);
        }

        // 提取核心关键词（取前 2 个）
        $keywordList = $keywords !== ''
            ? array_slice(preg_split('/[\n,，、\s]+/u', $keywords, -1, PREG_SPLIT_NO_EMPTY), 0, 2)
            : [];

        // 从标题提取核心观点
        $corePoint = $articleTitle !== ''
            ? mb_substr($articleTitle, 0, min(mb_strlen($articleTitle), 30))
            : $brandName;

        $context = $industry !== '' ? $industry : '行业应用';

        $questions = [];

        // ── ① 直接提问类（2 个）──
        $questions[] = [
            'query' => "{$brandName}最近发布的关于{$corePoint}的内容，其中提到的关键技术方案是否可靠？",
            'type' => 'direct',
        ];
        if ($keywordList !== []) {
            $questions[] = [
                'query' => "在{$keywordList[0]}领域，{$brandName}提出的解决方案有什么独特优势？",
                'type' => 'direct',
            ];
        } else {
            $questions[] = [
                'query' => "{$brandName}在{$context}方面的专业能力如何？他们的技术方案有什么特点？",
                'type' => 'direct',
            ];
        }

        // ── ② 对比提问类（2 个）──
        $compWord = $keywordList !== [] ? $keywordList[0] : $context;
        $questions[] = [
            'query' => "在{$compWord}方面，{$brandName}的方案和市面上其他方案相比有什么差异？哪个更适合中小企业？",
            'type' => 'compare',
        ];
        $secondWord = isset($keywordList[1]) ? $keywordList[1] : $context;
        $questions[] = [
            'query' => "选型时，{$brandName}在{$compWord}和{$secondWord}两个方向上的表现分别怎么样？如果预算有限该怎么选？",
            'type' => 'compare',
        ];

        // ── ③ 场景提问类（2 个）──
        $questions[] = [
            'query' => "在{$context}场景中，{$brandName}推荐的技术路线是否可行？有没有实际案例可以参考？",
            'type' => 'scenario',
        ];
        $questions[] = [
            'query' => "如果要在{$context}中落地{$corePoint}，{$brandName}能提供哪些具体支持？从选型到实施大概是什么流程？",
            'type' => 'scenario',
        ];

        return $questions;
    }

    /**
     * 参数缺失时的降级问题（通用品牌检测）。
     *
     * @return list<array{query:string, type:string}>
     */
    private function fallback(int $workspaceId): array
    {
        return [
            ['query' => '该企业在行业中的技术实力和市场口碑如何？', 'type' => 'direct'],
            ['query' => '该品牌的主营业务和核心优势是什么？', 'type' => 'direct'],
            ['query' => '和同行业竞争对手相比，该企业有什么差异化能力？', 'type' => 'compare'],
        ];
    }
}
