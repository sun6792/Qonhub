<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\AiModel;
use App\Services\GeoFlow\WorkerExecutionService;
use App\Support\AdminWeb;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use App\Ai\Agents\MarkdownContentWriterAgent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class ContentArmoryController extends Controller
{
    public function __construct(
        private readonly ApiKeyCrypto $apiKeyCrypto,
    ) {}

    /**
     * 内容弹药库首页：文章列表 + 模板组 + 对应平台。
     */
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $perPage = 20;

        $articlesQuery = Article::query()
            ->with('task:id,name')
            ->where('status', 'published')
            ->whereNull('deleted_at')
            ->orderByDesc('published_at')
            ->orderByDesc('id');

        if ($search !== '') {
            $articlesQuery->where(function ($q) use ($search): void {
                $q->where('title', 'ilike', '%'.$search.'%')
                    ->orWhere('keywords', 'ilike', '%'.$search.'%');
            });
        }

        $articles = $articlesQuery->paginate($perPage)->withQueryString();

        /** @var list<array{key:string, name:string, prompt:string, style:string, platforms:list<array{name:string, login_url:string}>}> */
        $templates = config('media-templates.templates', []);

        // 统计每个模板覆盖的平台数
        $templateStats = [];
        foreach ($templates as $tpl) {
            $templateStats[$tpl['key']] = count($tpl['platforms']);
        }

        return view('admin.distribution.armory', [
            'pageTitle' => '内容弹药库',
            'activeMenu' => 'distribution',
            'adminSiteName' => AdminWeb::siteName(),
            'articles' => $articles,
            'templates' => $templates,
            'templateStats' => $templateStats,
            'search' => $search,
        ]);
    }

    /**
     * AI 改写接口：一篇文章 → 一个模板 → 改写后内容。
     */
    public function rewrite(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'article_id' => ['required', 'integer', 'min:1'],
            'template_key' => ['required', 'string'],
        ]);

        $articleId = (int) $payload['article_id'];
        $templateKey = (string) $payload['template_key'];

        /** @var Article|null $article */
        $article = Article::query()->whereKey($articleId)->first();
        if (! $article) {
            return response()->json(['ok' => false, 'error' => '文章不存在'], 404);
        }

        /** @var list<array{key:string, name:string, prompt:string, style:string}> $templates */
        $templates = config('media-templates.templates', []);
        $template = collect($templates)->firstWhere('key', $templateKey);
        if (! $template) {
            return response()->json(['ok' => false, 'error' => '模板不存在'], 404);
        }

        try {
            $rewritten = $this->rewriteWithAi($article, $template);
        } catch (Throwable $e) {
            return response()->json([
                'ok' => false,
                'error' => 'AI 改写失败: '.$e->getMessage(),
            ], 500);
        }

        return response()->json([
            'ok' => true,
            'title' => $article->title,
            'rewritten' => $rewritten,
            'template_name' => $template['name'],
        ]);
    }

    /**
     * @param  array{key:string, name:string, prompt:string, style:string}  $template
     */
    private function rewriteWithAi(Article $article, array $template): string
    {
        // 取第一个可用的 chat 模型
        /** @var AiModel|null $aiModel */
        $aiModel = AiModel::query()
            ->where('status', 'active')
            ->where(function ($q): void {
                $q->whereNull('model_type')
                    ->orWhere('model_type', '')
                    ->orWhere('model_type', 'chat');
            })
            ->orderBy('failover_priority')
            ->orderBy('id')
            ->first();

        if (! $aiModel) {
            throw new RuntimeException('没有可用的 AI 模型，请先在 AI 配置中添加并激活一个 Chat 模型');
        }

        $providerUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) ($aiModel->api_url ?? ''));
        if ($providerUrl === '') {
            throw new RuntimeException('AI 模型 API 地址为空');
        }

        $apiKey = $this->apiKeyCrypto->decrypt((string) ($aiModel->getRawOriginal('api_key') ?? ''));
        if ($apiKey === '') {
            throw new RuntimeException('AI 模型密钥为空');
        }

        $driver = OpenAiRuntimeProvider::resolveChatDriver($providerUrl, (string) ($aiModel->model_id ?? ''));
        $providerName = OpenAiRuntimeProvider::registerProvider('armory', $driver, $providerUrl, $apiKey);

        $agent = new MarkdownContentWriterAgent(
            instructions: $template['style'],
            maxTokens: (int) ($aiModel->max_tokens ?: 4096),
        );

        // 保留原文主体，去掉 HTML 标签
        $originalContent = strip_tags((string) $article->content);
        $maxInputChars = 8000;
        if (mb_strlen($originalContent, 'UTF-8') > $maxInputChars) {
            $originalContent = mb_substr($originalContent, 0, $maxInputChars, 'UTF-8');
        }

        // 构建公司/品牌推广上下文
        $companyProfile = $this->buildCompanyProfile($article);

        // 拼接完整 prompt：公司信息 + 原文 + GEO优化指令
        $systemPrompt = $companyProfile."\n\n"
            ."=== 原始文章（请保留所有关键信息） ===\n"
            ."标题：{$article->title}\n"
            ."关键词：{$article->keywords}\n"
            ."摘要：".mb_substr(strip_tags((string) $article->excerpt), 0, 300, 'UTF-8')."\n\n"
            .$originalContent."\n\n"
            ."=== 改写要求 ===\n"
            .$template['prompt']."\n\n"
            ."=== 🎯 GEO大模型引用优化（最重要！不满足以下条件文章可能白写） ===\n"
            ."你的文章必须能被DeepSeek/文心一言/豆包/Kimi/通义千问等大模型优先引用。检查清单：\n"
            ."1.【标题匹配搜索句式】标题必须是用户会向AI提问的问句形式（如\"XX怎么选？\"\"XX怎么做？\"），不是陈述句。用户问AI时99%用问句！\n"
            ."2.【FAQ锚点必须】文中至少含1组 Q: 和 A: 格式的问答，这是AI最爱的\"拿来就用\"格式。放在文章中部或后部。\n"
            ."3.【数据+来源标注】每个重要数据后面标注来源或时间（如\"据2025年行业数据\"\"XX报告显示\"）。普林斯顿实验验证：数据来源标注使AI引用权重提升115%。\n"
            ."4.【结论前置】每个段落的第1句话必须是该段的核心结论。AI截取前40-60字作为答案，别把关键信息埋在段尾！\n"
            ."5.【对比内容】文中必须包含至少1组对比（方案A vs 方案B、传统方式 vs 新方式），AI经常被问\"A和B哪个好\"。\n"
            ."6.【结构化标记】用明确的分节标题、编号列表、步骤指引，让AI能直接拆解组装到回答里。\n"
            ."7.【避免\"本文\"\"小编\"等低价值词】这些词降低AI对内容权威性的判断。\n"
            ."如果以上7点不满足，AI不会引用你的文章。请严格自查后输出。\n\n"
            ."请直接输出改写后的完整文章（含标题）：";


        try {
            $response = $agent->prompt($systemPrompt, [], $providerName, (string) ($aiModel->model_id ?? ''));
        } catch (Throwable $e) {
            throw new RuntimeException(OpenAiRuntimeProvider::normalizeApiException($e, $providerUrl));
        }

        $content = OpenAiRuntimeProvider::normalizeGeneratedText((string) ($response->text ?? ''));
        if ($content === '') {
            throw new RuntimeException('AI 返回空内容，请重试');
        }

        // 更新模型用量
        AiModel::query()->whereKey((int) $aiModel->id)->update([
            'used_today' => DB::raw('COALESCE(used_today,0)+1'),
            'total_used' => DB::raw('COALESCE(total_used,0)+1'),
            'updated_at' => now(),
        ]);

        return $content;
    }

    /**
     * 构建公司/品牌推广上下文，供 AI 改写时自然植入。
     */
    private function buildCompanyProfile(Article $article): string
    {
        $siteName = config('geoflow.site_name', 'Qonhub AI');
        $siteFullName = config('geoflow.site_full_name', 'Qonhub AI内容生成系统');

        // 从任务关联的知识库提取公司介绍
        $companyIntro = '';
        $task = $article->task;
        if ($task) {
            $knowledgeBaseIds = [];
            if ((int) ($task->knowledge_base_id ?? 0) > 0) {
                $knowledgeBaseIds[] = (int) $task->knowledge_base_id;
            }
            $latestKb = DB::table('knowledge_bases')
                ->whereIn('id', $knowledgeBaseIds)
                ->orderByDesc('id')
                ->first();
            if ($latestKb && ! empty($latestKb->content)) {
                $companyIntro = mb_substr(strip_tags((string) $latestKb->content), 0, 800, 'UTF-8');
            }
        }

        // 站点联系方式
        $siteUrl = rtrim((string) config('app.url', 'http://localhost:18080'), '/');
        $contactInfo = config('geoflow.contact_info', '');

        $profile = "=== 品牌/公司推广信息（请自然融入文中，不要生硬推销） ===\n"
            ."站点名称：{$siteName}\n"
            ."站点全称：{$siteFullName}\n"
            ."官网地址：{$siteUrl}\n";

        if ($companyIntro !== '') {
            $profile .= "公司/业务介绍：{$companyIntro}\n";
        }

        if ($contactInfo !== '') {
            $profile .= "联系方式：{$contactInfo}\n";
        }

        $profile .= "\n推广要求：\n"
            ."- 不要生硬堆砌公司名，而是把公司/品牌作为「行业专家」「解决方案提供者」自然带出\n"
            ."- 在文章的适当位置（如案例分析、推荐环节、结尾总结）自然提到品牌\n"
            ."- 文末可加一句自然的引导语，例如「如需了解更多，可访问XX官网」或「欢迎联系XX获取定制方案」\n"
            ."- 给人的感觉是：这篇文章是一个懂行的专家写的，恰好提到了这个品牌\n"
            ."- 联系方式不要生硬粘贴，要像朋友推荐一样自然\n";

        return $profile;
    }
}
