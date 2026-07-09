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

        $originalContent = strip_tags((string) $article->content);
        if (mb_strlen($originalContent, 'UTF-8') > 3000) {
            $originalContent = mb_substr($originalContent, 0, 3000, 'UTF-8').'...';
        }

        $systemPrompt = "原始标题：{$article->title}\n原始关键词：{$article->keywords}\n\n{$template['prompt']}\n\n请输出改写后的文章（只输出正文，不要输出标题，不要重复指令）：";

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
}
