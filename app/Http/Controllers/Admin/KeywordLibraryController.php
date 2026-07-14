<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Models\KnowledgeBase;
use App\Services\GeoFlow\KnowledgeKeyExtractor;
use App\Support\AdminWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * 关键词库管理控制器。
 */
class KeywordLibraryController extends Controller
{
    private const DETAIL_PER_PAGE = 50;

    /**
     * 列表页。
     */
    public function index(): View
    {
        return view('admin.keyword-libraries.index', [
            'pageTitle' => __('admin.keyword_libraries.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'libraries' => $this->loadLibraries(),
            'stats' => $this->loadStats(),
        ]);
    }

    /**
     * 关键词库详情页。
     */
    public function detail(Request $request, int $libraryId): View|RedirectResponse
    {
        $this->authorizeOperatorAccess($libraryId, KeywordLibrary::class);
        $library = KeywordLibrary::query()->whereKey($libraryId)->firstOrFail();

        $search = trim((string) $request->query('search', ''));
        $keywords = $this->loadDetailKeywords($libraryId, $search);
        $usageTotal = $this->loadUsageTotal($libraryId);

        return view('admin.keyword-libraries.detail', [
            'pageTitle' => (string) $library->name.__('admin.keyword_detail.page_title_suffix'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'library' => $library,
            'search' => $search,
            'keywords' => $keywords,
            'usageTotal' => $usageTotal,
            'knowledgeBases' => KnowledgeBase::query()->select(['id', 'name'])->orderBy('name')->get(),
        ]);
    }

    /**
     * 从知识库蒸馏关键词 — AI 读取知识库内容，自动提取核心词/长尾词/地域词/意图词。
     */
    public function aiGenerateFromKnowledge(Request $request, int $libraryId): RedirectResponse
    {
        $this->authorizeOperatorAccess($libraryId, KeywordLibrary::class);
        $library = KeywordLibrary::query()->whereKey($libraryId)->firstOrFail();

        $payload = $request->validate([
            'knowledge_base_id' => ['required', 'integer', 'exists:knowledge_bases,id'],
            'count' => ['nullable', 'integer', 'min:5', 'max:50'],
        ]);

        $count = min(50, max(5, (int) ($payload['count'] ?? 20)));
        $kb = KnowledgeBase::query()->findOrFail((int) $payload['knowledge_base_id']);

        // 用 KnowledgeKeyExtractor 提取知识库的结构化素材
        $extractor = app(KnowledgeKeyExtractor::class);
        $extracted = $extractor->extract($kb);
        $kbContext = $extractor->toPromptContext($extracted, 3000);

        if (empty(trim($kbContext))) {
            return back()->withErrors('知识库内容为空，无法蒸馏关键词');
        }

        // 取第一个可用 Chat 模型
        $aiModel = \App\Models\AiModel::query()
            ->where('status', 'active')
            ->where(function ($q): void {
                $q->whereNull('model_type')->orWhere('model_type', '')->orWhere('model_type', 'chat');
            })
            ->orderBy('failover_priority')
            ->first();

        if (! $aiModel) {
            return back()->withErrors('没有可用的 AI 模型');
        }

        try {
            $providerUrl = \App\Support\GeoFlow\OpenAiRuntimeProvider::resolveChatBaseUrl((string) ($aiModel->api_url ?? ''));
            $apiKey = app(\App\Support\GeoFlow\ApiKeyCrypto::class)->decrypt((string) ($aiModel->getRawOriginal('api_key') ?? ''));
            $driver = \App\Support\GeoFlow\OpenAiRuntimeProvider::resolveChatDriver($providerUrl, (string) ($aiModel->model_id ?? ''));
            $providerName = \App\Support\GeoFlow\OpenAiRuntimeProvider::registerProvider('kw_kb_gen', $driver, $providerUrl, $apiKey);

            $agent = new \App\Ai\Agents\MarkdownContentWriterAgent(
                instructions: '你是 GEO 关键词蒸馏专家。根据提供的企业知识库内容，蒸馏出 ' . $count . ' 个精准的搜索关键词，每个关键词一行，不要编号，不要解释。关键词应该包含四类：1）核心词（产品/服务词根，2-5字）2）长尾词（搜索意图精准的短语）3）地域词（城市+产品/服务）4）意图词（如"XX多少钱""XX怎么选""XX哪家好"）。严格基于知识库内容，不要虚构。',
                maxTokens: 2000,
            );

            $response = $agent->prompt("请从以下企业知识库内容中蒸馏关键词：\n\n{$kbContext}", [], $providerName, (string) ($aiModel->model_id ?? ''));
            $text = (string) ($response->text ?? '');

            // 解析生成的文本，每行一个关键词（逻辑同 aiGenerate）
            $lines = preg_split('/\n+/', trim($text));
            $added = 0;
            foreach ($lines as $line) {
                $kw = trim((string) $line);
                $kw = preg_replace('/^\d+[\.\、\)\s]+\s*/u', '', $kw);
                $kw = trim($kw);
                if ($kw === '' || mb_strlen($kw) > 100) {
                    continue;
                }

                $exists = \App\Models\Keyword::where('library_id', $libraryId)
                    ->where('keyword', $kw)
                    ->exists();
                if ($exists) {
                    continue;
                }

                \App\Models\Keyword::query()->create([
                    'library_id' => $libraryId,
                    'keyword' => $kw,
                    'usage_count' => 0,
                ]);
                $added++;
            }

            $library->forceFill([
                'keyword_count' => \App\Models\Keyword::where('library_id', $libraryId)->count(),
            ])->save();

            return redirect()
                ->route('admin.keyword-libraries.detail', ['libraryId' => $libraryId])
                ->with('message', "从知识库「{$kb->name}」蒸馏出 {$added} 个关键词");

        } catch (\Throwable $e) {
            return back()->withErrors('知识库蒸馏失败：'.$e->getMessage());
        }
    }

    /**
     * AI 批量生成关键词并入关键词库。
     */
    public function aiGenerate(Request $request, int $libraryId): RedirectResponse
    {
        $this->authorizeOperatorAccess($libraryId, KeywordLibrary::class);
        $library = KeywordLibrary::query()->whereKey($libraryId)->firstOrFail();

        $payload = $request->validate([
            'prompt' => ['required', 'string', 'max:500'],
            'count' => ['nullable', 'integer', 'min:5', 'max:50'],
        ]);

        $prompt = (string) $payload['prompt'];
        $count = min(50, max(5, (int) ($payload['count'] ?? 20)));

        // 取第一个可用 Chat 模型
        $aiModel = \App\Models\AiModel::query()
            ->where('status', 'active')
            ->where(function ($q): void {
                $q->whereNull('model_type')->orWhere('model_type', '')->orWhere('model_type', 'chat');
            })
            ->orderBy('failover_priority')
            ->first();

        if (! $aiModel) {
            return back()->withErrors('没有可用的 AI 模型');
        }

        try {
            $providerUrl = \App\Support\GeoFlow\OpenAiRuntimeProvider::resolveChatBaseUrl((string) ($aiModel->api_url ?? ''));
            $apiKey = app(\App\Support\GeoFlow\ApiKeyCrypto::class)->decrypt((string) ($aiModel->getRawOriginal('api_key') ?? ''));
            $driver = \App\Support\GeoFlow\OpenAiRuntimeProvider::resolveChatDriver($providerUrl, (string) ($aiModel->model_id ?? ''));
            $providerName = \App\Support\GeoFlow\OpenAiRuntimeProvider::registerProvider('kw_gen', $driver, $providerUrl, $apiKey);

            $agent = new \App\Ai\Agents\MarkdownContentWriterAgent(
                instructions: '你是 SEO 关键词专家。根据用户输入的主题/行业/产品，生成 ' . $count . ' 个精准的搜索关键词，每个关键词一行，不要编号，不要解释。关键词应该包含：核心词、长尾词、地域词、意图词（如"XX多少钱""XX怎么选"）。',
                maxTokens: 2000,
            );

            $response = $agent->prompt("为以下内容生成 {$count} 个关键词：\n\n{$prompt}", [], $providerName, (string) ($aiModel->model_id ?? ''));
            $text = (string) ($response->text ?? '');

            // 解析生成的文本，每行一个关键词
            $lines = preg_split('/\n+/', trim($text));
            $added = 0;
            foreach ($lines as $line) {
                $kw = trim((string) $line);
                // 去除编号前缀（如 "1. "、"1、"）
                $kw = preg_replace('/^\d+[\.\、\)\s]+\s*/u', '', $kw);
                $kw = trim($kw);
                if ($kw === '' || mb_strlen($kw) > 100) {
                    continue;
                }

                // 去重
                $exists = \App\Models\Keyword::where('library_id', $libraryId)
                    ->where('keyword', $kw)
                    ->exists();
                if ($exists) {
                    continue;
                }

                \App\Models\Keyword::query()->create([
                    'library_id' => $libraryId,
                    'keyword' => $kw,
                    'usage_count' => 0,
                ]);
                $added++;
            }

            $library->forceFill([
                'keyword_count' => \App\Models\Keyword::where('library_id', $libraryId)->count(),
            ])->save();

            return redirect()
                ->route('admin.keyword-libraries.detail', ['libraryId' => $libraryId])
                ->with('message', "AI 已生成 {$added} 个关键词");

        } catch (\Throwable $e) {
            return back()->withErrors('AI 生成失败：'.$e->getMessage());
        }
    }

    /**
     * 在详情页中新增关键词。
     */
    public function storeKeyword(Request $request, int $libraryId): RedirectResponse
    {
        $this->authorizeOperatorAccess($libraryId, KeywordLibrary::class);
        $library = KeywordLibrary::query()->whereKey($libraryId)->firstOrFail();

        $payload = $request->validate([
            'keyword' => ['required', 'string', 'max:200'],
        ], [
            'keyword.required' => __('admin.keyword_detail.error.keyword_required'),
        ]);

        $keyword = trim((string) $payload['keyword']);
        if ($keyword === '') {
            return back()->withErrors(__('admin.keyword_detail.error.keyword_required'));
        }

        $exists = Keyword::query()
            ->where('library_id', $libraryId)
            ->where('keyword', $keyword)
            ->exists();
        if ($exists) {
            return back()->withErrors(__('admin.keyword_detail.error.keyword_exists'));
        }

        Keyword::query()->create([
            'library_id' => $libraryId,
            'keyword' => $keyword,
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $this->refreshKeywordLibraryCount($libraryId);

        return redirect()->route('admin.keyword-libraries.detail', ['libraryId' => $libraryId])->with('message', __('admin.keyword_detail.message.add_success'));
    }

    /**
     * 在详情页中删除关键词（支持单条/批量）。
     */
    public function destroyKeywords(Request $request, int $libraryId): RedirectResponse
    {
        $this->authorizeOperatorAccess($libraryId, KeywordLibrary::class);
        $library = KeywordLibrary::query()->whereKey($libraryId)->firstOrFail();

        /** @var array<int, mixed> $rawIds */
        $rawIds = (array) $request->input('keyword_ids', []);
        $keywordIds = collect($rawIds)
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values();

        if ($keywordIds->isEmpty()) {
            return back()->withErrors(__('admin.keyword_detail.error.select_required'));
        }

        $deletedCount = Keyword::query()
            ->where('library_id', $libraryId)
            ->whereIn('id', $keywordIds->all())
            ->delete();
        $this->refreshKeywordLibraryCount($libraryId);

        return redirect()->route('admin.keyword-libraries.detail', ['libraryId' => $libraryId])->with(
            'message',
            __('admin.keyword_detail.message.delete_success', ['count' => $deletedCount])
        );
    }

    /**
     * 在详情页中更新关键词库基础信息。
     */
    public function updateFromDetail(Request $request, int $libraryId): RedirectResponse
    {
        $this->authorizeOperatorAccess($libraryId, KeywordLibrary::class);
        $library = KeywordLibrary::query()->whereKey($libraryId)->firstOrFail();

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
        ], [
            'name.required' => __('admin.keyword_detail.error.library_name_required'),
        ]);

        $library->update([
            'name' => trim((string) $payload['name']),
            'description' => trim((string) ($payload['description'] ?? '')),
        ]);

        return redirect()->route('admin.keyword-libraries.detail', ['libraryId' => $libraryId])->with('message', __('admin.keyword_detail.message.update_success'));
    }

    /**
     * 在详情页中导入关键词（逐行 + 逗号分隔）。
     */
    public function importKeywords(Request $request, int $libraryId): RedirectResponse
    {
        $this->authorizeOperatorAccess($libraryId, KeywordLibrary::class);
        $library = KeywordLibrary::query()->whereKey($libraryId)->firstOrFail();

        $payload = $request->validate([
            'keywords_text' => ['required', 'string'],
        ], [
            'keywords_text.required' => __('admin.keyword_libraries.error.keywords_required'),
        ]);

        $keywords = $this->parseKeywordImportText((string) $payload['keywords_text']);
        if ($keywords->isEmpty()) {
            return back()->withErrors(__('admin.keyword_libraries.error.keywords_required'));
        }

        $importedCount = 0;
        $duplicateCount = 0;

        DB::transaction(function () use ($keywords, $libraryId, &$importedCount, &$duplicateCount): void {
            foreach ($keywords as $keyword) {
                $exists = Keyword::query()
                    ->where('library_id', $libraryId)
                    ->where('keyword', $keyword)
                    ->exists();
                if ($exists) {
                    $duplicateCount++;

                    continue;
                }

                Keyword::query()->create([
                    'library_id' => $libraryId,
                    'keyword' => $keyword,
                    'used_count' => 0,
                    'usage_count' => 0,
                ]);
                $importedCount++;
            }

            $this->refreshKeywordLibraryCount($libraryId);
        });

        $message = __('admin.keyword_libraries.message.import_success', ['count' => $importedCount]);
        if ($duplicateCount > 0) {
            $message .= __('admin.keyword_libraries.message.import_skip', ['count' => $duplicateCount]);
        }

        return redirect()->route('admin.keyword-libraries.detail', ['libraryId' => $libraryId])->with('message', $message);
    }

    /**
     * 创建表单页。
     */
    public function create(): View
    {
        return view('admin.keyword-libraries.form', [
            'pageTitle' => __('admin.keyword_libraries.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'isEdit' => false,
            'libraryId' => 0,
            'libraryForm' => $this->emptyForm(),
        ]);
    }

    /**
     * 创建关键词库。
     */
    public function store(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
        ], [
            'name.required' => __('admin.keyword_libraries.error.name_required'),
        ]);

        $library = KeywordLibrary::query()->create([
            'name' => trim((string) $payload['name']),
            'description' => trim((string) ($payload['description'] ?? '')),
            'keyword_count' => 0,
        ]);

        $this->assignToOperatorWorkspaces((int) $library->id, KeywordLibrary::class);

        return redirect()->route('admin.keyword-libraries.index')->with('message', __('admin.keyword_libraries.message.create_success'));
    }

    /**
     * 编辑表单页。
     */
    public function edit(int $libraryId): View|RedirectResponse
    {
        $this->authorizeOperatorAccess($libraryId, KeywordLibrary::class);
        $library = KeywordLibrary::query()->whereKey($libraryId)->firstOrFail();

        return view('admin.keyword-libraries.form', [
            'pageTitle' => __('admin.keyword_libraries.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'isEdit' => true,
            'libraryId' => (int) $library->id,
            'libraryForm' => [
                'name' => (string) $library->name,
                'description' => (string) ($library->description ?? ''),
            ],
        ]);
    }

    /**
     * 更新关键词库。
     */
    public function update(Request $request, int $libraryId): RedirectResponse
    {
        $this->authorizeOperatorAccess($libraryId, KeywordLibrary::class);
        $library = KeywordLibrary::query()->whereKey($libraryId)->firstOrFail();

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
        ], [
            'name.required' => __('admin.keyword_libraries.error.name_required'),
        ]);

        $library->update([
            'name' => trim((string) $payload['name']),
            'description' => trim((string) ($payload['description'] ?? '')),
        ]);

        return redirect()->route('admin.keyword-libraries.index')->with('message', __('admin.keyword_libraries.message.update_success'));
    }

    /**
     * 删除关键词库（包含词条）。
     */
    public function destroy(int $libraryId): RedirectResponse
    {
        $this->authorizeOperatorAccess($libraryId, KeywordLibrary::class);
        $library = KeywordLibrary::query()->whereKey($libraryId)->firstOrFail();

        Keyword::query()->where('library_id', $libraryId)->delete();
        $library->delete();

        return redirect()->route('admin.keyword-libraries.index')->with('message', __('admin.keyword_libraries.message.delete_success'));
    }

    /**
     * @return array<int, array{id:int,name:string,description:string,actual_count:int,created_at:?string,updated_at:?string}>
     */
    private function loadLibraries(): array
    {
        $query = KeywordLibrary::query()
            ->select(['id', 'name', 'description', 'created_at', 'updated_at'])
            ->withCount('keywords as actual_count')
            ->orderByDesc('created_at');

        $this->scopeByOperatorWorkspaces($query, KeywordLibrary::class);

        return $query->get()->map(static function (KeywordLibrary $library): array {
            return [
                'id' => (int) $library->id,
                'name' => (string) $library->name,
                'description' => (string) ($library->description ?? ''),
                'actual_count' => (int) ($library->actual_count ?? 0),
                'created_at' => $library->created_at?->format('Y-m-d H:i:s'),
                'updated_at' => $library->updated_at?->format('Y-m-d H:i:s'),
            ];
        })->all();
    }

    /**
     * @return array{total_libraries:int,total_keywords:int,avg_keywords:float}
     */
    private function loadStats(): array
    {
        $totalLibraries = KeywordLibrary::query()->count();
        $totalKeywords = Keyword::query()->count();

        return [
            'total_libraries' => $totalLibraries,
            'total_keywords' => $totalKeywords,
            'avg_keywords' => $totalLibraries > 0 ? round($totalKeywords / $totalLibraries, 1) : 0.0,
        ];
    }

    /**
     * @return array{name:string,description:string}
     */
    private function emptyForm(): array
    {
        return [
            'name' => '',
            'description' => '',
        ];
    }

    /**
     * @return LengthAwarePaginator<int, Keyword>
     */
    private function loadDetailKeywords(int $libraryId, string $search): LengthAwarePaginator
    {
        $query = Keyword::query()
            ->where('library_id', $libraryId)
            ->orderByDesc('created_at');
        if ($search !== '') {
            $query->where('keyword', 'like', '%'.$search.'%');
        }

        return $query->paginate(self::DETAIL_PER_PAGE)->withQueryString();
    }

    /**
     * @return Collection<int, string>
     */
    private function parseKeywordImportText(string $keywordsText): Collection
    {
        return collect(preg_split('/\R/u', $keywordsText) ?: [])
            ->flatMap(static function (string $line): array {
                return array_map('trim', explode(',', $line));
            })
            ->map(static fn (string $keyword): string => trim($keyword))
            ->filter(static fn (string $keyword): bool => $keyword !== '')
            ->unique()
            ->values();
    }

    /**
     * 维护关键词库缓存计数，避免列表统计偏差。
     */
    private function refreshKeywordLibraryCount(int $libraryId): void
    {
        $count = Keyword::query()->where('library_id', $libraryId)->count();
        KeywordLibrary::query()->whereKey($libraryId)->update([
            'keyword_count' => $count,
        ]);
    }

    /**
     * 按 legacy 页面口径统计关键词总使用次数。
     *
     * 统计规则与 bak/admin/keyword-library-detail.php 一致：
     * 通过文章表 original_keyword 与关键词库中的 keyword 进行匹配计数。
     */
    private function loadUsageTotal(int $libraryId): int
    {
        if (! Schema::hasColumn('articles', 'original_keyword')) {
            return 0;
        }

        return (int) Article::query()
            ->whereIn('original_keyword', function ($query) use ($libraryId): void {
                $query->select('keyword')
                    ->from('keywords')
                    ->where('library_id', $libraryId);
            })
            ->count();
    }
}
