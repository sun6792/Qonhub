<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClientUser;
use App\Models\Workspace;
use App\Models\Admin;
use App\Services\GeoFlow\WorkspaceService;
use App\Support\AdminWeb;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class WorkspaceController extends Controller
{
    public function __construct(
        private readonly WorkspaceService $workspaceService,
    ) {}

    public function index(): View
    {
        /** @var Admin $admin */
        $admin = Auth::guard('admin')->user();
        $isSuperAdmin = $admin instanceof Admin && $admin->isSuperAdmin();

        $workspaces = $isSuperAdmin
            ? Workspace::query()->with('owner')->orderByDesc('last_activity_at')->orderBy('name')->get()
            : $this->workspaceService->listForOperator((int) $admin->id);

        return view('admin.workspaces.index', [
            'pageTitle' => '工作空间',
            'activeMenu' => 'workspaces',
            'adminSiteName' => AdminWeb::siteName(),
            'workspaces' => $workspaces,
            'isSuperAdmin' => $isSuperAdmin,
        ]);
    }

    public function create(): View
    {
        $operators = Admin::query()
            ->where('status', 'active')
            ->orderBy('display_name')
            ->get();

        return view('admin.workspaces.create', [
            'pageTitle' => '创建工作空间',
            'activeMenu' => 'workspaces',
            'adminSiteName' => AdminWeb::siteName(),
            'operators' => $operators,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
            'client_company_name' => ['nullable', 'string', 'max:200'],
            'client_contact_name' => ['nullable', 'string', 'max:100'],
            'client_email' => ['nullable', 'email', 'max:200'],
            'client_phone' => ['nullable', 'string', 'max:40'],
            'brand_keywords' => ['nullable', 'string'],
            'owner_admin_id' => ['nullable', 'integer', 'exists:admins,id'],
        ]);

        $payload['brand_keywords'] = $this->parseKeywords($payload['brand_keywords'] ?? '');

        /** @var Admin $admin */
        $admin = Auth::guard('admin')->user();
        $workspace = $this->workspaceService->create($payload, (int) $admin->id);

        if (! empty($payload['owner_admin_id']) && (int) $payload['owner_admin_id'] !== (int) $admin->id) {
            $this->workspaceService->assignOperator(
                (int) $workspace->id,
                (int) $payload['owner_admin_id'],
                'operator'
            );
        }

        return redirect()
            ->route('admin.workspaces.show', ['slug' => $workspace->slug])
            ->with('message', '工作空间创建成功！')
            ->with('workspace_created', [
                'id' => (int) $workspace->id,
                'slug' => (string) $workspace->slug,
                'access_token' => (string) $workspace->access_token,
                'client_url' => $workspace->clientPortalUrl(),
            ]);
    }

    public function show(string $slug): View
    {
        $workspace = Workspace::query()
            ->with(['owner', 'platformAccounts'])
            ->where('slug', $slug)
            ->firstOrFail();

        $workspace->touchActivity();

        // 获取关联的资源统计
        $taskIds = $this->workspaceService->assignedIds((int) $workspace->id, \App\Models\Task::class);
        $kbIds = $this->workspaceService->assignedIds((int) $workspace->id, \App\Models\KnowledgeBase::class);
        $articleIds = $this->workspaceService->assignedIds((int) $workspace->id, \App\Models\Article::class);

        $tasks = ! empty($taskIds) ? \App\Models\Task::query()->whereIn('id', $taskIds)->orderByDesc('id')->limit(10)->get() : collect();
        $articles = ! empty($articleIds) ? \App\Models\Article::query()->whereIn('id', $articleIds)->orderByDesc('id')->limit(10)->get() : collect();

        return view('admin.workspaces.show', [
            'pageTitle' => $workspace->name,
            'activeMenu' => 'workspaces',
            'adminSiteName' => AdminWeb::siteName(),
            'workspace' => $workspace,
            'tasks' => $tasks,
            'articles' => $articles,
            'clientPortalUrl' => $workspace->clientPortalUrl(),
            'stats' => [
                'tasks' => count($taskIds),
                'knowledge_bases' => count($kbIds),
                'articles' => count($articleIds),
            ],
        ]);
    }

    public function edit(string $slug): View
    {
        $workspace = Workspace::query()->where('slug', $slug)->firstOrFail();
        $operators = Admin::query()->where('status', 'active')->orderBy('display_name')->get();

        return view('admin.workspaces.edit', [
            'pageTitle' => '编辑工作空间',
            'activeMenu' => 'workspaces',
            'adminSiteName' => AdminWeb::siteName(),
            'workspace' => $workspace,
            'operators' => $operators,
        ]);
    }

    public function update(Request $request, string $slug): RedirectResponse
    {
        $workspace = Workspace::query()->where('slug', $slug)->firstOrFail();

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
            'client_company_name' => ['nullable', 'string', 'max:200'],
            'client_contact_name' => ['nullable', 'string', 'max:100'],
            'client_email' => ['nullable', 'email', 'max:200'],
            'client_phone' => ['nullable', 'string', 'max:40'],
            'brand_keywords' => ['nullable', 'string'],
            'status' => ['required', 'string', 'in:active,paused,archived'],
            'logo_url' => ['nullable', 'url', 'max:500'],
        ]);

        $payload['brand_keywords'] = $this->parseKeywords($payload['brand_keywords'] ?? '');

        $this->workspaceService->update($workspace, $payload);

        return redirect()
            ->route('admin.workspaces.show', ['slug' => $workspace->slug])
            ->with('message', '工作空间已更新');
    }

    public function assignResource(Request $request, string $slug): RedirectResponse
    {
        $workspace = Workspace::query()->where('slug', $slug)->firstOrFail();

        $payload = $request->validate([
            'type' => ['required', 'string', 'in:Task,Article,KnowledgeBase,TitleLibrary,KeywordLibrary,ImageLibrary'],
            'resource_ids' => ['required', 'array', 'min:1'],
            'resource_ids.*' => ['required', 'integer', 'min:1'],
        ]);

        $modelClass = match ($payload['type']) {
            'Task' => \App\Models\Task::class,
            'Article' => \App\Models\Article::class,
            'KnowledgeBase' => \App\Models\KnowledgeBase::class,
            'TitleLibrary' => \App\Models\TitleLibrary::class,
            'KeywordLibrary' => \App\Models\KeywordLibrary::class,
            'ImageLibrary' => \App\Models\ImageLibrary::class,
            default => throw new \InvalidArgumentException('Unknown resource type'),
        };

        $this->workspaceService->assignManyResources(
            (int) $workspace->id,
            $modelClass,
            array_map('intval', $payload['resource_ids'])
        );

        return redirect()
            ->route('admin.workspaces.show', ['slug' => $workspace->slug])
            ->with('message', count($payload['resource_ids']).' 个资源已挂入工作空间');
    }

    public function regenerateToken(Request $request, string $slug): RedirectResponse
    {
        $workspace = Workspace::query()->where('slug', $slug)->firstOrFail();

        $workspace->forceFill([
            'access_token' => \Illuminate\Support\Str::random(40),
        ])->save();

        return redirect()
            ->route('admin.workspaces.show', ['slug' => $workspace->slug])
            ->with('message', '客户访问链接已重置')
            ->with('workspace_token_reset', [
                'client_url' => $workspace->clientPortalUrl(),
            ]);
    }

    /**
     * 为客户创建前端看板登录账号。
     */
    public function createClientUser(Request $request, string $slug): RedirectResponse
    {
        $workspace = Workspace::query()->where('slug', $slug)->firstOrFail();

        $payload = $request->validate([
            'client_name' => ['required', 'string', 'max:100'],
            'client_username' => ['required', 'string', 'max:80', 'unique:client_users,username'],
            'client_password' => ['required', 'string', 'min:6', 'max:100'],
        ]);

        $plainPassword = (string) $payload['client_password'];
        $crypto = app(ApiKeyCrypto::class);

        ClientUser::query()->create([
            'workspace_id' => (int) $workspace->id,
            'name' => (string) $payload['client_name'],
            'username' => (string) $payload['client_username'],
            'email' => (string) $payload['client_username'] . '@client.local',
            'password' => bcrypt($plainPassword),
            'password_ciphertext' => $crypto->encrypt($plainPassword),
            'status' => 'active',
        ]);

        return redirect()
            ->route('admin.workspaces.show', ['slug' => $workspace->slug])
            ->with('message', '客户账号创建成功！')
            ->with('client_created', [
                'email' => (string) $payload['client_username'],
                'password' => $plainPassword,
            ]);
    }

    /**
     * 重置客户账号密码。
     */
    public function resetClientPassword(Request $request, string $slug): RedirectResponse
    {
        $workspace = Workspace::query()->where('slug', $slug)->firstOrFail();

        $payload = $request->validate([
            'client_user_id' => ['required', 'integer', 'exists:client_users,id'],
            'new_password' => ['required', 'string', 'min:6', 'max:100'],
        ]);

        $client = ClientUser::query()
            ->where('workspace_id', (int) $workspace->id)
            ->whereKey((int) $payload['client_user_id'])
            ->firstOrFail();

        $plainPassword = (string) $payload['new_password'];
        $crypto = app(ApiKeyCrypto::class);

        $client->forceFill([
            'password' => bcrypt($plainPassword),
            'password_ciphertext' => $crypto->encrypt($plainPassword),
        ])->save();

        return redirect()
            ->route('admin.workspaces.show', ['slug' => $workspace->slug])
            ->with('message', '密码已重置！')
            ->with('client_created', [
                'email' => (string) $client->username,
                'password' => $plainPassword,
            ]);
    }

    public function revealClientPassword(string $slug, int $clientUserId): \Illuminate\Http\JsonResponse
    {
        $workspace = Workspace::query()->where('slug', $slug)->firstOrFail();

        $client = ClientUser::query()
            ->where('workspace_id', (int) $workspace->id)
            ->whereKey($clientUserId)
            ->firstOrFail();

        $ciphertext = $client->password_ciphertext;
        if (empty($ciphertext)) {
            return response()->json(['ok' => false, 'error' => '该账号密码不可查看，请先重置密码']);
        }

        $crypto = app(ApiKeyCrypto::class);
        $plainPassword = $crypto->decrypt($ciphertext);

        return response()->json([
            'ok' => true,
            'username' => $client->username,
            'password' => $plainPassword,
        ]);
    }

    /**
     * 运营人员标记/取消平台授权状态。
     */
    public function togglePlatformStatus(Request $request, string $slug): RedirectResponse
    {
        $workspace = Workspace::query()->where('slug', $slug)->firstOrFail();

        $payload = $request->validate([
            'platform_key' => ['required', 'string'],
            'platform_account_name' => ['nullable', 'string', 'max:200'],
            'credential' => ['nullable', 'string'],
        ]);

        $platformKey = (string) $payload['platform_key'];
        $accountName = (string) ($payload['platform_account_name'] ?? '');
        $credential = (string) ($payload['credential'] ?? '');

        $existing = ClientPlatformAccount::query()
            ->where('workspace_id', (int) $workspace->id)
            ->where('platform_key', $platformKey)
            ->first();

        if ($existing && $existing->isActive()) {
            // 取消授权
            $existing->forceFill(['status' => 'revoked', 'last_error_message' => null])->save();

            return back()->with('message', $platformKey.' 授权已取消');
        }

        // 创建或重新激活
        $data = [
            'workspace_id' => (int) $workspace->id,
            'platform_key' => $platformKey,
            'platform_account_name' => $accountName !== '' ? $accountName : null,
            'status' => 'active',
            'last_verified_at' => now(),
            'expires_at' => now()->addDays(30),
        ];

        if ($credential !== '') {
            $data['credential_ciphertext'] = app(ApiKeyCrypto::class)->encrypt($credential);
        }

        if ($existing) {
            $existing->forceFill($data)->save();
        } else {
            ClientPlatformAccount::query()->create($data);
        }

        return back()->with('message', $platformKey.' 已标记为已授权');
    }

    public function destroy(string $slug): RedirectResponse
    {
        $workspace = Workspace::query()->where('slug', $slug)->firstOrFail();
        $workspace->delete();

        return redirect()
            ->route('admin.workspaces.index')
            ->with('message', '工作空间已归档');
    }

    /**
     * @return list<string>
     */
    private function parseKeywords(string $input): array
    {
        if (empty(trim($input))) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', preg_split('/[\n,，、]+/u', $input) ?: []),
            static fn (string $kw): bool => $kw !== ''
        ));
    }
}
