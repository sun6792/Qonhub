# Qonhub AI v2.4.0 — 摘星精华融入实施计划

> 基于 2026-07-13 全量代码审计 + 摘星逆向分析，所有提示词精准贴合现有代码底座
> 原则：复用优先、多租户隔离、架构一致、每阶段带反向验收标准

---

## 通用前置约束（所有阶段必须遵守）

| 约束项 | 规范 |
|--------|------|
| 技术栈 | Laravel 12 + PHP 8.2+ + PostgreSQL 16 + Redis + Blade + Tailwind CSS v4 |
| 多租户 | 所有业务数据通过 `workspace_id` 隔离，Service 层用 `assignedIds()` 过滤，禁止跨空间越权 |
| 复用优先 | 复用 `ContentPublishService`、`EnterpriseAnchorService`、`GeoContentScorer`、`AiVisibilityService` 等已有服务 |
| 安全规范 | 凭证数据经 `ApiKeyCrypto`（AES-256-CBC）加密，前端脱敏展示 |
| 队列规范 | 异步任务走 Redis 队列，复用 Laravel Horizon，走已有通道（`geoflow`/`distribution`/`theme-replication`） |
| 前端规范 | 客户端复用 `resources/views/client/layout.blade.php`，运营端复用 Blade 后台布局 |

---

## 现有代码底座清单（已审计确认）

### 已有且可复用的核心资产

| 类型 | 文件 | 已有能力 |
|------|------|---------|
| 发布服务 | `app/Services/GeoFlow/Publishing/ContentPublishService.php` | `createPublishTask()`、`createCertifyTask()`、`dispatchPublishTask()`、`retryFailed()`、`cancelTask()` |
| 队列Job | `app/Jobs/ProcessContentPublishJob.php` | 单条发布结果执行 |
| 模型 | `ContentPublishTask`、`ContentPublishResult`、`ContentPublisherAccount` | 发布任务/结果/账号三表完备 |
| 客户端控制器 | `app/Http/Controllers/Client/ContentPublishController.php` | `index()`、`create()`、`store()`、`show()`、`certify()`、`certifyStore()` 六方法已实现 |
| 客户端视图 | `resources/views/client/content-publish/` | `index.blade.php`、`create.blade.php`、`show.blade.php`、`certify.blade.php` 四页面已实现 |
| 客户端路由 | `routes/workspace.php:101-108` | `/client/content-publish/` 六路由已注册 |
| GEO评分 | `app/Services/GeoFlow/GeoContentScorer.php` | `score()`、`quickScore()`、`compare()`、`geoEnhance()` |
| 锚点服务 | `app/Services/GeoFlow/EnterpriseAnchorService.php` | `b2bAnchorPlatforms()`、`saveProfile()`、`markCertified()`、`certificationSummary()`、`llmCoverageReport()` |
| 企业档案 | `app/Models/EnterpriseProfile.php` | NAP+W 全字段、`isVerified()`、`isNapConsistent()`、`certifiedPlatformCount()` |
| AI可见性 | `app/Services/GeoFlow/AiVisibilityService.php` | `checkWorkspace()`、`snapshotForWorkspace()`、`clientVisibilityData()`、已覆盖6平台 |
| RPA引擎 | `rpa-engine/server.js` | 6个自动化脚本、Cookie持久化、缓存隔离、云端同步 |
| 调度命令 | `app/Console/Commands/GeoFlowScheduleTasksCommand.php` | 每分钟扫描活跃任务并入队 |
| 运维助手 | `rpa-engine/dashboard.html` | 本地 Web 控制台，三栏布局，127.0.0.1:9901 |
| 分发渠道 | `app/Models/DistributionChannel.php` | 三种类型：`geoflow_agent`/`wordpress_rest`/`generic_http` |

### 客户端现有路由（routes/workspace.php:88-113）

```
GET   /client                 → client.dashboard
GET   /client/login           → client.login
POST  /client/login           → client.login.attempt
POST  /client/logout          → client.logout
GET   /client/articles        → client.articles
GET   /client/ai-visibility   → client.ai-visibility
GET   /client/platforms       → client.platforms
POST  /client/platforms/bind  → client.platforms.bind
POST  /client/platforms/unbind→ client.platforms.unbind
GET   /client/content-publish → client.content-publish.index
GET   /client/content-publish/create → client.content-publish.create
POST  /client/content-publish/store  → client.content-publish.store
GET   /client/content-publish/certify→ client.content-publish.certify
POST  /client/content-publish/certify-store → client.content-publish.certify-store
GET   /client/content-publish/{taskId} → client.content-publish.show
```

### RPA 引擎现有端点（rpa-engine/server.js）

```
GET  /api/v1/health            → 健康检查
POST /api/v1/register          → B2B 企业注册
POST /api/v1/publish           → 内容发布
GET  /api/v1/tasks/:id         → 任务状态查询
GET  /api/cache/list           → 按 workspace 列出缓存
POST /api/cache/clear          → 清除缓存
GET  /api/tasks/pull           → 云端拉取待执行任务
POST /api/tasks/report         → 上报执行结果到云端
POST /api/captcha/submit       → 验证码提交
POST /api/captcha/await        → 等待验证码
GET  /                          → 运营助手 Dashboard
```

---

## P0 客户端发布工作台（现有基础增强）

### 现状评估
客户端发布控制器、路由、视图已 70% 完成。`index()` 缺分页和筛选；`store()` 缺 GEO 评分集成；`show()` 功能基本完备；`create()` 平台选择是平铺列表，缺级联分类。

### 开发任务

#### P0-1：增强 `index()` — 分页+筛选+状态标签

**改动文件**：`app/Http/Controllers/Client/ContentPublishController.php` 的 `index()` 方法

```
改造逻辑（伪代码）：
1. 接收 query 参数：status, task_name, date_from, date_to, page
2. 基础查询：ContentPublishTask::where('workspace_id', $workspaceId)
3. 可选筛选：->when($status, fn($q) => $q->where('status', $status))
4. 分页：->paginate(15) 替代 ->limit(30)->get()
5. 传递 $tasks、$filters、$statusOptions 到视图
```

**改动文件**：`resources/views/client/content-publish/index.blade.php`

```
视图增强：
1. 顶部筛选栏（横向排列）：
   - 任务名称搜索框（text input + 搜索按钮）
   - 状态下拉（全部/待处理/进行中/已完成/失败/部分失败/已取消）
   - 日期范围选择器（从→到）
2. 任务表格（替代现有卡片列表）：
   - 序号 | 任务名称 | 文章数 | 平台数 | 进度 | 状态标签 | 创建时间 | 操作
   - 状态标签：pending 灰 / running 蓝 / completed 绿 / failed 红 / partial_failed 橙 / cancelled 灰
3. 底部分页链接：{{ $tasks->links() }}
```

#### P0-2：增强 `store()` — GEO 评分集成

**改动文件**：`app/Http/Controllers/Client/ContentPublishController.php` 的 `store()` 方法

```
在 createPublishTask() 调用前插入 GEO 评分逻辑：

// 对每篇选中文章进行 GEO 评分
$scorer = app(GeoContentScorer::class);
$geoResults = [];
foreach ($payload['article_ids'] as $articleId) {
    $article = Article::find($articleId);
    $scoreResult = $scorer->score($article->title, $article->content);
    
    if ($scoreResult['score'] < 70) {
        // 自动增强
        $enhancedContent = $scorer->geoEnhance($article->title, $article->content);
        $enhancedScore = $scorer->quickScore($article->title, $enhancedContent);
        
        if ($enhancedScore >= 70) {
            // 更新文章内容为增强版
            $article->update(['content' => $enhancedContent]);
        } else {
            // 仍不达标，记录警告
            $geoResults[$articleId] = ['score' => $enhancedScore, 'grade' => $scorer->grade($enhancedScore), 'warning' => true];
        }
    }
    
    $geoResults[$articleId] = $geoResults[$articleId] ?? ['score' => $scoreResult['score'], 'grade' => $scoreResult['grade'], 'warning' => false];
}

// 传递 GEO 结果到 createPublishTask 的 options
$task = $this->publishService->createPublishTask(..., options: [
    ...,
    'geo_scores' => $geoResults,
    'avg_geo_score' => collect($geoResults)->avg('score'),
]);
```

**改动文件**：`app/Models/ContentPublishTask.php`

```
给 $fillable 新增字段：
'avg_geo_score'  // integer, nullable, 平均GEO评分
```

**改动文件**：数据库迁移

```php
Schema::table('content_publish_tasks', function (Blueprint $table) {
    $table->integer('avg_geo_score')->nullable()->after('total_jobs');
});
```

#### P0-3：增强 `create()` — 平台级联选择器

**改动文件**：`resources/views/client/content-publish/create.blade.php`

```
替换现有平铺 checkbox 列表为三级级联选择器：

一级：发布方式
  ├── 平台发布 (publish_mode=2)
  │   ├── 自媒体矩阵 → 头条/百家号/公众号/搜狐/小红书/网易/B站/企鹅/值得买
  │   ├── B2B 行业网站 → 天助网/八方资源网/K2/无忧/领商/万家/九州/查询123/商机导航/蜘蛛客
  │   ├── 智能体官网 → 已配置的 GeoFlow Agent 渠道
  │   └── 自营媒体 → 已配置的 WordPress/HTTP 渠道
  └── 媒体发布 (publish_mode=3)
      ├── 权威合作媒体 → (标记为商务渠道)
      └── 自媒体权威号 → 今日头条权威号/百家号权威号等

实现：用 Tailwind 手风琴/树形结构，每级可展开/收起，末级为 checkbox
```

### P0 反向验证清单

```
✅ 功能正确性
□ 客户端可正常查看任务列表，支持按状态/名称/日期筛选，分页正常
□ store() 提交前自动执行 GEO 评分，<70 分文章自动增强后再次评分
□ 任务创建成功后，运营端 /geo_admin/content-publish 可同步看到该任务
□ 任务详情页展示每篇文章在各平台的发布状态和失败原因

✅ 多租户隔离
□ A 工作空间客户登录，无法看到 B 工作空间的发布任务
□ 手动修改 URL 中的 taskId，无法越权访问其他空间的任务详情
□ create 页面的文章列表仅展示当前 workspace 的文章

✅ 兼容性
□ 运营端原有发布功能、弹药库分发功能不受影响
□ Horizon 监控正常运行，新增任务走 distribution 队列

✅ 安全
□ 渠道凭证在前端全程脱敏
□ 未登录访问 /client/content-publish/* 自动跳转登录页
```

---

## P1 B2B 分步注册向导

### 现状评估
- `certify()` 和 `certifyStore()` 已实现基础 B2B 认证任务提交
- RPA 引擎已有 6 个 B2B 注册脚本（天助网/八方资源网/无忧商务网/K2/领商网/万家商务网）
- `EnterpriseProfile` 模型已有 NAP+W 字段，缺联系人字段
- `EnterpriseAnchorService` 已有 `markCertified()`、`certificationSummary()`

### 开发任务

#### P1-1：扩展 EnterpriseProfile 字段

**迁移文件**：`database/migrations/2026_07_13_000001_add_contact_fields_to_enterprise_profiles.php`

```php
Schema::table('enterprise_profiles', function (Blueprint $table) {
    $table->string('contact_name', 50)->nullable()->after('company_website');
    $table->string('contact_phone', 20)->nullable()->after('contact_name');
    $table->string('company_logo', 500)->nullable()->after('contact_phone');
});
```

**改动**：`app/Models/EnterpriseProfile.php` 的 `$fillable` 新增三个字段

#### P1-2：新增分步状态检查方法

**改动文件**：`app/Models/EnterpriseProfile.php`

```php
/**
 * 返回四步注册完成状态。
 * @return array{step1:bool, step2:bool, step3:bool, step4:bool, total:int, completed:int, can_register:bool}
 */
public function getRegisterStepStatus(): array
{
    $steps = [
        'step1' => !empty($this->company_full_name) 
                && !empty($this->unified_social_credit_code) 
                && !empty($this->legal_person) 
                && !empty($this->business_scope),
        'step2' => !empty($this->contact_name) 
                && !empty($this->contact_phone) 
                && !empty($this->company_email),
        'step3' => !empty($this->company_province) 
                && !empty($this->company_city) 
                && !empty($this->industry),
        'step4' => !empty($this->products_services),
    ];
    
    $completed = count(array_filter($steps));
    
    return [
        'step1' => $steps['step1'],     // 公司资料
        'step2' => $steps['step2'],     // 联系人
        'step3' => $steps['step3'],     // 地区行业
        'step4' => $steps['step4'],     // 产品服务
        'total' => 4,
        'completed' => $completed,
        'can_register' => $completed === 4,
    ];
}
```

#### P1-3：新增 RPA 注册方法

**改动文件**：`app/Services/GeoFlow/EnterpriseAnchorService.php`

```php
/**
 * 启动单平台 RPA 自动注册。
 * 返回 RPA 任务 ID，前端轮询状态。
 */
public function startRpaRegister(int $workspaceId, string $platformKey): array
{
    $profile = EnterpriseProfile::where('workspace_id', $workspaceId)->first();
    if (!$profile) {
        throw new \RuntimeException('企业档案不存在');
    }
    
    $stepStatus = $profile->getRegisterStepStatus();
    if (!$stepStatus['can_register']) {
        throw new \RuntimeException('企业资料未完成，请先完善四步资料');
    }
    
    $platforms = self::anchorPlatforms();
    $platformInfo = $platforms[$platformKey] ?? null;
    if (!$platformInfo || empty($platformInfo['supports_rpa'])) {
        throw new \RuntimeException('该平台暂不支持自动注册');
    }
    
    // 调用 RPA 引擎
    $rpaUrl = config('geoflow.rpa_engine_url') . '/api/v1/register';
    $payload = [
        'platform' => $platformKey,
        'account' => [
            'username' => $profile->company_full_name,
            'credential' => null, // RPA 自动生成
        ],
        'enterprise' => [
            'workspace_id' => $workspaceId,
            'company_name' => $profile->company_full_name,
            'credit_code' => $profile->unified_social_credit_code,
            'legal_person' => $profile->legal_person,
            'business_scope' => $profile->business_scope,
            'address' => $profile->company_address,
            'province' => $profile->company_province,
            'city' => $profile->company_city,
            'phone' => $profile->contact_phone ?: $profile->company_phone,
            'email' => $profile->company_email,
            'website' => $profile->company_website,
            'products' => is_array($profile->products_services) ? implode('、', $profile->products_services) : $profile->products_services,
        ],
        'options' => [
            'workspace_id' => $workspaceId,
            'timeout_seconds' => 180,
        ],
    ];
    
    $response = Http::withHeaders([
        'X-Api-Key' => config('geoflow.rpa_engine_api_key'),
        'Content-Type' => 'application/json',
    ])->post($rpaUrl, $payload);
    
    if (!$response->successful()) {
        throw new \RuntimeException('RPA 引擎调用失败: ' . $response->body());
    }
    
    $result = $response->json();
    
    // 更新锚点认证状态
    $cert = EnterpriseAnchorCertification::updateOrCreate(
        ['enterprise_profile_id' => $profile->id, 'anchor_platform_key' => $platformKey],
        ['rpa_task_id' => $result['task_id'], 'certification_status' => 'in_progress']
    );
    
    Log::info('RPA register started', [
        'workspace_id' => $workspaceId,
        'platform' => $platformKey,
        'rpa_task_id' => $result['task_id'],
        'cert_id' => $cert->id,
    ]);
    
    return ['rpa_task_id' => $result['task_id'], 'cert_id' => $cert->id];
}
```

#### P1-4：新增 RPA 结果回写端点

**改动文件**：`app/Http/Controllers/Admin/RpaSyncController.php` 的 `report()` 方法（已有，需增强）

```
report() 方法增强：
当 payload['success'] === true：
  1. 更新 EnterpriseAnchorCertification 状态为 'certified'
  2. 写入 platform_page_url（店铺链接）
  3. 如有 Cookie/Session，加密存入 ClientPlatformAccount
  4. 日志记录完整过程

当 payload['success'] === false：
  1. 保留原认证状态不变
  2. 记录失败原因到 certification 的 verification_notes
  3. 日志记录错误详情
```

#### P1-5：迁移文件 — EnterpriseAnchorCertification 加 rpa_task_id

```php
Schema::table('enterprise_anchor_certifications', function (Blueprint $table) {
    $table->string('rpa_task_id', 100)->nullable()->after('expires_at');
});
```

#### P1-6：客户端 B2B 注册向导视图

**新文件**：`resources/views/client/b2b-register/wizard.blade.php`

```
页面结构：
┌─────────────────────────────────────────────┐
│  📋 B2B 企业注册向导                          │
├─────────────────────────────────────────────┤
│  四步进度卡片（横向排列）：                     │
│  [✅ 公司资料] → [✅ 联系人] → [⭕ 地区行业] → [⭕ 产品服务] │
│  已完成 2/4，还差 2 步即可开启自动注册           │
├─────────────────────────────────────────────┤
│  B2B 平台卡片网格（2列或3列）：                  │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐      │
│  │ 天助网    │ │ 八方资源网│ │ 无忧商务网│      │
│  │ ✅ 已开通  │ │ 🚀 一键注册│ │ ⚠️ 完善资料│    │
│  │ 查看店铺→ │ │          │ │           │      │
│  └──────────┘ └──────────┘ └──────────┘      │
│                                              │
│  已适配RPA的6个高权重平台优先展示                │
│  其余平台显示「手动注册」入口                    │
└─────────────────────────────────────────────┘

注册过程：
  点击「一键注册」→ 调用 POST /client/b2b/register/{platformKey}
  → 异步执行 RPA → 轮询 /api/tasks/pull 获取进度
  → 前端显示进度动画 → 完成后卡片自动刷新为「已开通」
```

#### P1-7：新增路由和控制器

**路由**（`routes/workspace.php` 客户端组内）：

```php
// B2B 注册向导
Route::get('/b2b-register', [ClientPortalController::class, 'b2bRegisterWizard'])
    ->name('b2b-register.wizard');
Route::post('/b2b-register/{platformKey}', [ClientPortalController::class, 'b2bRegisterStart'])
    ->name('b2b-register.start');
Route::get('/b2b-register/status/{rpaTaskId}', [ClientPortalController::class, 'b2bRegisterStatus'])
    ->name('b2b-register.status');
```

### P1 反向验证清单

```
✅ 功能正确性
□ 企业资料未完成时，「一键注册」按钮置灰，提示完善对应步骤
□ 资料完整后点击注册 → 异步执行 RPA → 成功后自动标记锚点为「已认证」→ 同步凭证
□ 注册失败时返回明确错误原因，原有锚点/档案数据不变
□ RPA 任务执行过程前端有进度反馈

✅ 多租户隔离
□ 客户仅能触发自己 workspace 的 B2B 注册
□ 注册生成的凭证、锚点数据仅归属当前 workspace

✅ 兼容性
□ 原有 B2B 锚点手动标记功能不受影响
□ 已有 RPA 脚本、Cookie 持久化正常运行

✅ 安全
□ 注册获取的平台 Cookie 经 AES-256-CBC 加密存储
□ RPA 任务仅可由已认证客户/运营触发
```

---

## P2 自动跑词引擎

### 现状评估
- `GeoFlowScheduleTasksCommand` 已有每分钟扫描、入队逻辑
- `ProcessGeoFlowTaskJob` 已有 AI 生成全流程
- `JobQueueService` 已有并发控制、失败重试、stale 恢复
- `ContentPublishService` 已有统一发布
- 缺：关键词组关联、自动分发渠道配置、run_mode 标记

### 开发任务

#### P2-1：扩展 Task 模型

**迁移文件**：`database/migrations/2026_07_13_000002_add_auto_run_fields_to_tasks.php`

```php
Schema::table('tasks', function (Blueprint $table) {
    $table->unsignedBigInteger('keyword_group_id')->nullable()->after('image_library_id');
    $table->json('auto_distribute_channels')->nullable()->after('keyword_group_id');
    $table->string('run_mode', 20)->default('manual')->after('auto_distribute_channels');
    // run_mode: 'manual' | 'auto'
    $table->timestamp('last_auto_run_at')->nullable()->after('run_mode');
    $table->integer('last_keyword_index')->default(0)->after('last_auto_run_at');
});

// 可选：外键
$table->foreign('keyword_group_id')->references('id')->on('keyword_libraries')->nullOnDelete();
```

**改动**：`app/Models/Task.php` 的 `$fillable` 新增上述字段，新增关联：

```php
public function keywordGroup(): BelongsTo
{
    return $this->belongsTo(KeywordLibrary::class, 'keyword_group_id');
}
```

#### P2-2：扩展 GeoFlowScheduleTasksCommand（自动跑词）

**改动文件**：`app/Console/Commands/GeoFlowScheduleTasksCommand.php`

```php
/**
 * 自动跑词模式：每分钟轮转关键词，为符合条件的 auto 任务创建 TaskRun。
 */
protected function autoKeywordRun(): void
{
    $autoTasks = Task::query()
        ->where('run_mode', 'auto')
        ->where('status', 'active')
        ->whereNotNull('keyword_group_id')
        ->with('keywordGroup.keywords')
        ->get();

    foreach ($autoTasks as $task) {
        // 频率控制：检查上次执行时间
        if ($task->last_auto_run_at && 
            $task->last_auto_run_at->diffInSeconds(now()) < $task->publish_interval) {
            continue;
        }

        // 草稿池控制
        if ($task->draft_limit > 0) {
            $currentDrafts = Article::where('task_id', $task->id)
                ->where('status', 'draft')->count();
            if ($currentDrafts >= $task->draft_limit) {
                continue; // 草稿池已满，暂停
            }
        }

        // 轮转取关键词
        $keywords = $task->keywordGroup->keywords;
        if ($keywords->isEmpty()) continue;

        $index = $task->last_keyword_index % $keywords->count();
        $keyword = $keywords[$index];

        // 创建 TaskRun 入队（复用现有 JobQueueService）
        $taskRun = app(JobQueueService::class)->enqueueTaskRun($task, [
            'keyword' => $keyword->keyword,
            'keyword_id' => $keyword->id,
            'auto_distribute' => true,
            'distribute_channels' => $task->auto_distribute_channels,
        ]);

        // 更新指针
        $task->update([
            'last_keyword_index' => $index + 1,
            'last_auto_run_at' => now(),
        ]);

        // 并发上限（复用现有逻辑）
        if ($task->taskRuns()->where('status', 'running')->count() >= 10) {
            break; // 达到并发上限，下个周期继续
        }
    }
}

// 在 handle() 方法中调用：
public function handle(): void
{
    // ... 原有逻辑 ...
    $this->autoKeywordRun(); // 新增
}
```

#### P2-3：扩展 ProcessGeoFlowTaskJob（自动分发分支）

**改动文件**：`app/Jobs/ProcessGeoFlowTaskJob.php`

```
在文章生成完成、审核通过后，新增自动分发分支：

if ($taskRun->auto_distribute && !empty($taskRun->distribute_channels)) {
    // 1. GEO 评分
    $scorer = app(GeoContentScorer::class);
    $scoreResult = $scorer->score($article->title, $article->content);
    
    if ($scoreResult['score'] < 70) {
        $article->content = $scorer->geoEnhance($article->title, $article->content);
        $article->save();
    }
    
    // 2. 自动分发到配置的渠道
    $publishService = app(ContentPublishService::class);
    $publishTask = $publishService->createPublishTask(
        workspace: $task->workspace,
        articleIds: [$article->id],
        platformKeys: $taskRun->distribute_channels,
        options: [
            'use_smart_scheduling' => true,
            'use_content_rewrite' => false,
            'avg_geo_score' => $scoreResult['score'],
        ],
    );
    $publishService->dispatchPublishTask($publishTask);
    
    // 记录到 task_runs
    $taskRun->update(['publish_task_id' => $publishTask->id]);
}
```

#### P2-4：运营端 — 任务创建页新增自动跑词开关

**改动文件**：`resources/views/admin/tasks/create.blade.php`（或编辑页）

```
在「发布频率」区域后新增：

┌─ 自动跑词模式 ─────────────────────────────┐
│  [🔘 开启自动跑词]                          │
│                                            │
│  选择关键词组：[下拉选择 KeywordLibrary]     │
│  自动分发渠道：[多选级联选择器]              │
│  发布间隔：[同原有 publish_interval 字段]    │
│  草稿池上限：[同原有 draft_limit 字段]       │
│                                            │
│  ⚠️ 开启后每个周期自动轮转关键词生成文章     │
│     并自动分发到所选渠道                     │
└────────────────────────────────────────────┘
```

### P2 反向验证清单

```
✅ 功能正确性
□ run_mode=auto 的任务自动按间隔轮转关键词生成文章
□ 草稿池满时自动暂停，空位释放后恢复
□ 关键词全部轮转完后任务标记为暂停，不报错
□ 生成文章自动 GEO 评分，<70 分自动增强
□ 配置了自动分发渠道的任务，文章审核通过后自动分发

✅ 兼容性
□ run_mode=manual 的任务完全不受影响
□ 现有调度、队列、Horizon 监控正常运行
□ 数据分析看板正常展示 auto 任务数据

✅ 并发控制
□ 单任务每分钟生成量不超过上限
□ 悲观行锁避免重复生成
□ 多 workspace 任务互不干扰

✅ 边界
□ 无关键词组时任务不崩溃
□ RPA/分发失败时不影响后续轮转
□ stale 任务恢复机制对 auto 任务同样生效
```

---

## P3 发布渠道平台树

### 现状评估
- `DistributionChannel` 模型已有三种 `channel_type`
- 客户端凭证中心有 11 个自媒体平台
- `EnterpriseAnchorService` 有 10 个 B2B + 24 个媒体平台配置
- 缺：统一的 `distribute_type` 分类和级联展示

### 开发任务

#### P3-1：扩展 DistributionChannel

**迁移文件**：`database/migrations/2026_07_13_000003_add_distribute_type_to_distribution_channels.php`

```php
Schema::table('distribution_channels', function (Blueprint $table) {
    $table->string('distribute_type', 30)->default('self_build')->after('channel_type');
    // 值：self_media, b2b, news_media, authoritative, website_agent, self_build
    $table->json('platform_meta')->nullable()->after('distribute_type');
    // 存储关联的平台配置快照（key/name/icon），冗余加速
});
```

**改动**：`app/Models/DistributionChannel.php` 的 `$fillable` + `$casts`

#### P3-2：新建 ChannelPlatformTree 服务

**新文件**：`app/Services/GeoFlow/ChannelPlatformTree.php`

```php
<?php

namespace App\Services\GeoFlow;

use App\Models\ClientPlatformAccount;
use App\Models\DistributionChannel;
use App\Models\EnterpriseAnchorCertification;
use App\Models\EnterpriseProfile;

class ChannelPlatformTree
{
    /**
     * 返回完整平台树（二级分类+三级平台），按 workspace 过滤。
     */
    public function build(int $workspaceId): array
    {
        $profile = EnterpriseProfile::where('workspace_id', $workspaceId)->first();
        
        return [
            [
                'value' => 2,
                'label' => '平台发布',
                'children' => [
                    $this->buildSelfMediaNode($workspaceId),
                    $this->buildB2bNode($workspaceId),
                    $this->buildWebsiteAgentNode($workspaceId),
                    $this->buildSelfBuildNode($workspaceId),
                ],
            ],
            [
                'value' => 3,
                'label' => '媒体发布',
                'children' => [
                    $this->buildAuthoritativeNode($workspaceId),
                    $this->buildAuthMediaNode($workspaceId),
                ],
            ],
        ];
    }

    private function buildSelfMediaNode(int $workspaceId): array
    {
        $platforms = ClientPlatformAccount::supportedPlatforms();
        $accounts = ClientPlatformAccount::where('workspace_id', $workspaceId)
            ->where('status', 'active')->get()->keyBy('platform_key');
        
        return [
            'value' => 'self_media',
            'label' => '自媒体矩阵',
            'children' => collect($platforms)->map(fn($p, $k) => [
                'value' => $k,
                'label' => $p['name'],
                'icon' => $p['icon'] ?? 'globe',
                'connected' => $accounts->has($k),
            ])->values()->all(),
        ];
    }

    private function buildB2bNode(int $workspaceId): array
    {
        $platforms = EnterpriseAnchorService::anchorPlatforms();
        $certs = EnterpriseAnchorCertification::whereHas('enterpriseProfile', 
            fn($q) => $q->where('workspace_id', $workspaceId)
        )->get()->keyBy('anchor_platform_key');
        
        return [
            'value' => 'b2b',
            'label' => 'B2B行业网站',
            'children' => collect($platforms)->map(fn($p, $k) => [
                'value' => $k,
                'label' => $p['name'],
                'icon' => $p['icon'] ?? 'b2b',
                'connected' => $certs->has($k) && $certs->get($k)->isCertified(),
                'supports_rpa' => !empty($p['supports_rpa']),
            ])->values()->all(),
        ];
    }

    // ... buildWebsiteAgentNode, buildSelfBuildNode, buildAuthoritativeNode, buildAuthMediaNode ...
}
```

#### P3-3：前端封装通用级联选择器 Blade 组件

**新文件**：`resources/views/components/cascading-platform-selector.blade.php`

```
一个纯 Tailwind + Alpine.js 的三级级联选择器：
- 左侧：一级分类列表
- 中间：二级分类列表
- 右侧：三级平台 checkbox 列表 + 搜索框
- 已选标签展示在底部
- 支持受控/非受控模式
```

#### P3-4：改造客户端创建发布页 + 运营端渠道管理页

**改动**：`resources/views/client/content-publish/create.blade.php`
→ 平台选择区域替换为 `<x-cascading-platform-selector>`

**改动**：运营端渠道管理页 → 左侧分类树 + 右侧列表布局

### P3 反向验证清单

```
✅ 功能正确性
□ 平台树两级分类清晰，三级平台数据与各模块配置一致
□ 级联选择器可正常选择，选中的平台可正常用于发布
□ 智能体官网渠道发布时自动生成 llms.txt + Schema

✅ 数据一致性
□ 修改客户端凭证中心/B2B锚点的平台配置后，平台树同步更新
□ 历史渠道数据自动匹配 distribute_type，无需手动迁移

✅ 多租户
□ 不同 workspace 只看自己已绑定/已开通的平台

✅ 扩展性
□ 新增平台分类仅需在枚举和 ChannelPlatformTree 中补充
```

---

## P4 AI 品牌监测扩展

### 现状评估
- `AiVisibilityService` 已覆盖 6 个平台（豆包/DeepSeek/文心一言/通义千问/Kimi/百度AI搜索）
- 已有定时检测、趋势分析、客户端看板
- 缺：6 个新平台、竞品对比、竞争力报告

### 开发任务

#### P4-1：扩展 AiVisibilityService 到 12 平台

**改动文件**：`app/Services/GeoFlow/AiVisibilityService.php`

```
新增 6 个平台的采集适配器（每个平台一个 private method）：

private function checkXunfeiStarfire(string $brandName): array
private function checkNamiAI(string $brandName): array
private function checkWeChatAI(string $brandName): array
private function checkDouyinAI(string $brandName): array
private function checkQuarkAI(string $brandName): array
private function checkYuanbao(string $brandName): array

每个方法返回统一结构：
[
    'mentioned' => true/false,
    'position' => 1-10,     // 排名位置
    'snippet' => '...',     // 提及片段
    'url' => '...',         // AI平台URL
]

修改 checkWorkspace() 方法：
  遍历 12 个平台（PC+移动=24采集点），聚合结果
```

#### P4-2：新增竞品模型 + 对比服务

**迁移文件**：`2026_07_13_000004_create_ai_competitors_table.php`

```php
Schema::create('ai_competitors', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('workspace_id');
    $table->string('brand_name', 100);
    $table->string('brand_website', 500)->nullable();
    $table->string('status', 20)->default('active');
    $table->timestamps();
    $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
});
```

**新方法**：`AiVisibilityService::brandCompare(int $workspaceId)`

```
维度对比：
1. 总提及率（自身品牌 vs 竞品在各AI平台的提及比例）
2. 覆盖平台数（被多少个AI平台提及过）
3. TOP3排名占比（出现在搜索结果前3名的比例）
4. 正/负向舆情占比（用已有AI模型判断提及内容情感倾向）
```

#### P4-3：扩展客户端 AI 看板 + 新增竞争力页面

**改动**：`resources/views/client/ai-visibility.blade.php`
→ 从 6 平台扩展到 12 平台

**新文件**：`resources/views/client/competitiveness.blade.php`
→ 品牌 vs 竞品对比卡片 + 平台覆盖雷达图 + 趋势折线图

**新路由**：`GET /client/competitiveness` → `client.competitiveness`

### P4 反向验证清单

```
✅ 功能正确性
□ 12 个 AI 平台均可正常执行品牌检测，数据正常入库
□ 竞品对比指标与单平台检测数据一致
□ 正负向舆情判断逻辑正常

✅ 兼容性
□ 原有 6 平台检测功能、历史数据完全不受影响
□ 客户端 AI 看板正常展示新平台数据
□ 原有定时任务正常运行

✅ 多租户
□ 每个 workspace 只能管理自己的竞品、查看自己的报告

✅ 边界
□ 单个平台检测失败不影响其他平台
□ 未配置竞品的企业显示引导提示
```

---

## 三端连通性保障

### 当前连通架构

```
┌──────────────┐    REST API     ┌──────────────┐    X-Api-Key     ┌──────────────┐
│  运营端       │←──────────────→│  Laravel      │←───────────────→│  RPA 引擎     │
│  :18080/     │   Blade渲染     │  Backend      │  localhost:9901  │  :9901        │
│  geo_admin   │                 │  :18080       │                  │               │
└──────┬───────┘                 └──────┬────────┘                  └──────────────┘
       │                                │
       │ 共享 DB                        │ 共享 DB
       │                                │
       ▼                                ▼
┌──────────────┐                 ┌──────────────┐
│  PostgreSQL  │                 │  客户端       │
│  + Redis     │                 │  :18080/client│
└──────────────┘                 └──────────────┘
```

### 连通性保证措施

1. **数据层**：三端共享同一 PostgreSQL + Redis，workspace_id 统一隔离
2. **RPA 同步**：`RpaSyncController` 已有 `pending-tasks`、`report`、`my-workspaces`、`articles`、`client-platforms`、`distribution-channels` 六个端点
3. **运营助手**：`dashboard.html` 通过 `GEOFLOW_API_URL` 指向 Laravel 后端，切换客户拉取对应 workspace 数据
4. **运营端 ↔ 客户端**：运营端创建的任务，客户端通过 `ContentPublishTask::where('workspace_id', ...)` 可见；客户端提交的发布请求，运营端同步可见
5. **缓存隔离**：RPA 引擎缓存按 `workspace_id` 分目录存储（`storage/states/{workspace_id}/{platform}.json`）

### 三端验证清单

```
✅ 运营端 ↔ RPA 引擎
□ 运营端触发 B2B 注册 → RPA 引擎收到任务 → Playwright 执行 → 结果回写
□ 运营助手 Dashboard 切换客户后，平台缓存/文章/渠道数据正确刷新
□ RPA 引擎不可达时，运营端前端显示明确错误提示，不白屏

✅ 运营端 ↔ 客户端
□ 运营端创建的发布渠道，客户端创建发布任务时可选择
□ 客户端提交的发布任务，运营端列表页可查看+管理
□ 运营端标记 B2B 锚点为「已认证」后，客户端平台卡片实时更新

✅ 客户端 ↔ RPA 引擎
□ 客户端触发 B2B 一键注册 → Laravel 转发到 RPA → 执行 → 结果回写到 Cert + ClientPlatformAccount
□ 客户端查看平台授权状态时，数据与 RPA 引擎缓存状态一致
```

---

## 实施顺序建议

```
第1周：P0（客户端发布工作台）+ P3（发布渠道平台树）
  → 客户端可自助发布，渠道分类清晰

第2周：P1（B2B分步注册向导）
  → 填资料→匹配→RPA自动注册→认证闭环

第3周：P2（自动跑词引擎）
  → 定时自动生成+分发，摘星核心差异化

第4周：P4（AI品牌监测扩展）
  → 12平台监测+竞争力报告

每阶段完成后执行对应反向验证清单，全部通过后再进入下一阶段。
```
