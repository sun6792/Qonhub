# 豆流 AI — Claude Code 工作规范

> 每次在本项目工作前，先读这个文件。

## 项目概况

豆流 AI = Laravel 12 + PostgreSQL 16 (pgvector) + Redis 7 + Node.js RPA 引擎。
企业级 GEO 营销系统：AI 写文章 → 多平台分发 → 监测 AI 收录。

## 编码四准则（每次改前先调技能）

```
改 queue 配置        → /configuring-horizon
改 LLM 调用/AI适配器 → /ai-sdk-development
加 Blade 页面        → /tailwindcss-development
改 Eloquent/Service  → /laravel-best-practices
改 Agent 管道        → /codebase-design
系统级改动           → 先读 CONTEXT.md 对齐术语
```

## 架构底线

1. **API Key 必须解密后才传给适配器。** `getRawOriginal('api_key')` 返回 `enc:v1:...` 密文，必须经 `app(ApiKeyCrypto::class)->decrypt()` 才能用。
2. **Agent 输出 key 必须与下游 Agent 读取 key 一致。** 改任何一个 Agent 的 return 结构前，检查下游 Agent 期望的字段名。
3. **不改 schema 不加表 → 优先修复现有代码。** 这个项目已经有 66 个 migration + 60+ 张表。
4. **所有 updateOrCreate/多表写入必须包裹在 `DB::transaction()` 中。**
5. **Queue Job `$tries` 默认 3，非特殊情况不设 1。**

## 项目知识速查

```
CONTEXT.md      ← 领域术语表（94 个术语、歧义消除表）
docs/豆流AI功能全景说明.md  ← v2.6.1 全功能文档
```

### 五智能体管道

```
AgentDispatcherService::start(workspaceId, inputData)
  → Scout  (AI品牌检测 + B2B锚点巡检 + 实时对话)
  → Strategy (关键词规划 + 选题 + 渠道排期)
  → Content (RAG检索 → AI写作 → GEO评分 → <70重写)
  → Deploy (ContentPublishTask → 分发队列 → 等120s结果)
  → Review (汇总 → 建议 → 客户端可见)
```

### 关键配置

```
config/geoflow.php     ← RPA引擎URL、admin路径
config/horizon.php     ← 队列supervisor
docker-compose.yml     ← 全部服务
.env                  ← API Keys(注释掉说明不生效)
```

### 队列体系

```
geoflow          ← AI文章生成
distribution     ← 旧分发系统
content-publish  ← 新分发系统(ProcessContentPublishJob)
agent_scout      ← Scout检测
theme-replication ← 主题复制
```

### Timeout 链

```
Job timeout: 600s < Horizon timeout: 650s < retry_after: 900s
```

## 常见错误速查

| 症状 | 根因 | 位置 |
|------|------|------|
| Agent 管道 401 | ApiKeyCrypto 未注入 → 密文当 Key | 检查是否用 `app(ApiKeyCrypto::class)` 直解析 |
| Deploy 超时 | queue:work 未启动 | `php artisan queue:work redis --queue=content-publish` |
| Content geo_score=0 | DeepSeek 401 或 Redis 配额 | 检查 `storage/logs/` |
| Review 建议为空 | 工作空间未跑过 Agent 管道 | 先触发一次 AgentController::start |
| Blade `$anchorData` undefined | 直接渲染视图无 Controller 数据 | 通过路由访问,不走 tinker |

## 禁止事项

- ❌ 不要新建表（优先用现有 schema）
- ❌ 不要把 Blade 内联 style 硬编码颜色（用 Tailwind v4 CSS 变量）
- ❌ 不要在 Controller 里写业务逻辑（放 Service 层）
- ❌ 不要绕过 LlmOrchestratorService 直调 curl（用统一入口）
- ❌ 不要改 Agent 输出 key 名不改下游读取
- ❌ 不要用 `DB::raw` 拼接用户输入（防注入）

## 联网搜索接入方法论

**每个 AI 平台的联网搜索参数格式不同，集中在 ScoutAgent 按平台分发：**

| 平台 | 参数格式 | 端点 |
|------|----------|------|
| 豆包(火山方舟) | `tools:[{"type":"search"}]` | 标准 ark /v3 |
| 通义千问(DashScope) | `enable_search:true` | 标准 OpenAI 兼容 |
| DeepSeek | `tools:[{"type":"web_search_20260209","name":"web_search"}]` | **Anthropic端点** `/anthropic/v1/messages` + `x-api-key` 认证 |
| 讯飞星火 | `tools:[{"type":"web_search","web_search":{"enable":true}}]` | 标准 REST |

**DeepSeek 联网搜索特殊处理：** 不走 OpenAI 兼容端点，不走 Laravel Adapter，直接在 `ScoutAgentService::deepseekAnthropicSearch()` 用 `Http` facade 调 Anthropic API。因为 DeepSeek 的 `web_search` 工具只在 Anthropic 格式下工作。

**接入新平台联网搜索的标准流程：**
1. 先手动测试（tinker 直调 chat() 验证参数有效）
2. 再入管（ScoutAgent 加平台 + web_search 标志）
3. 最后跑全管道验证稳定性

## 三层 Bug 叠加调试心法

**v2.7.0 最典型的调试案例——API Key 401：**

```
症状：所有 LLM 调用 401
根因链：
  第1层：LlmOrchestratorService 把密文当明文传给适配器（没解密）
  第2层：Adapter 的 sendRequestWithAuth() 加了 Token，但 sendRequest() 创建了新的空客户端（Token 丢失）
  第3层：LlmOrchestratorService 和 LlmAdapterFactory 的 ApiKeyCrypto 依赖为 null（Laravel 容器不注入带默认值的参数）
修法：三层必须同时修，缺一不可
  1. app(ApiKeyCrypto::class)->decrypt() 直解析（绕开 DI）
  2. sendRequest() 接收可选 $http 参数 / 或直接在 sendRequestWithAuth() 中调 $http->post()
  3. 统一 resolveApiKey() 智能判断 enc:v1: 前缀再解密
```

**调试原则：**
- 当 API 返回 401 但直接 Http::withToken() 返回 200 时 → Adapter 的 Token 传递链断了
- 当手动测试通过但管道里失败时 → 检查 workspaceId 是否影响配额/代理逻辑
- 当 `构造参数 = null` 生效时 → Laravel DI 没注入，改用 `app()` 服务定位器
- 当 RPA 浏览器闪退时 → 加 `--disable-dev-shm-usage --disable-gpu`
- 当 Cookie 保存后仍要登录时 → 检查 state 文件所在 workspace 是否匹配
- 当 3+ 个 Bug 叠加时 → 逐层剥离验证，不要假设"上一个修好了"
