# 豆流 AI (Qonhub) — 全栈架构文档

> 企业级 GEO 生成式引擎优化智能营销系统  
> v2.7.0 | Laravel 12 + PostgreSQL 16 (pgvector) + Node.js RPA 引擎  
> 307 PHP 文件 · 62K 行 · 74 张表 · 57 控制器 · 121 服务类

---

## 一、系统全景

```
┌────────────────────────────────────────────────────────────┐
│                     🖥️ 客户端 (Client Portal)               │
│   Vue/Tailwind v4 暗色主题 · WebGL2 特效 · 响应式           │
│   /client → 数字身份卡 / AI可见度 / 一键发布 / 平台授权     │
├────────────────────────────────────────────────────────────┤
│                     🖥️ 运营后台 (Admin Panel)               │
│   /geo_admin → 工作空间 / 任务管理 / 内容弹药库 / 数据分析  │
├────────────────────────────────────────────────────────────┤
│                  🤖 五智能体管道 (Agent Pipeline)            │
│   Scout → Strategy → Content → Deploy → Review              │
├────────────────────────────────────────────────────────────┤
│  Laravel 12 API Layer                                       │
│  ┌─────────┬─────────┬─────────┬─────────┬─────────┐       │
│  │ REST    │ Sanctum │ Horizon │ pgvector│GeoFlow  │       │
│  │ API v1  │  Auth   │  Queue  │  RAG    │Services │       │
│  └─────────┴─────────┴─────────┴─────────┴─────────┘       │
├────────────────────────────────────────────────────────────┤
│  💾 PostgreSQL 16 + pgvector │ ⚡ Redis 7 │ 🗄️ 队列/Horizon  │
├────────────────────────────────────────────────────────────┤
│  🌐 Node.js RPA Engine (Playwright)                         │
│  15 个 B2B/自媒体自动化脚本 · Cookie 持久化 · stealth 指纹  │
└────────────────────────────────────────────────────────────┘
```

## 二、核心技术栈

| 层级 | 技术 | 版本 | 为什么选它 |
|------|------|------|-----------|
| 后端框架 | Laravel | 12.x | 成熟的 PHP 生态，内置队列/事件/ORM |
| 语言 | PHP | 8.4 | 类型系统完善，JIT 性能提升 |
| 数据库 | PostgreSQL | 16 | pgvector 向量检索，HNSW 索引，tsvector 全文搜索 |
| 向量扩展 | pgvector | — | 知识库 RAG 检索的核心基础设施 |
| 缓存/队列 | Redis | 7 | Laravel Horizon 队列管理 + 分布式锁 |
| AI SDK | laravel/ai | 0.6.0 | Agent 模式，多 Provider 统一接口 |
| AI 模型 | DeepSeek V4 + 豆包 + 千问 + Kimi + 文心 | — | 5 家国产大模型，OpenAI 兼容协议 |
| RPA 引擎 | Playwright | 1.48 | 浏览器自动化，stealth 反检测 |
| RPA 后端 | Express.js | 4.x | 轻量 HTTP 微服务，本地部署 |
| 前端 | Tailwind CSS | v4 | CSS-first 设计系统，无 JS 运行时 |
| 容器化 | Docker Compose | — | 一键部署，7 个服务 |
| WebSocket | Laravel Reverb | 1.0 | 实时推送任务进度 |

## 三、五智能体管道（核心竞争力）

```
AgentDispatcherService::start(workspaceId, inputData)  ← 运营手动触发
  │
  ├─ ① Scout (侦察) — 15s
  │   ├─ AiVisibilityService: DB历史品牌提及数据
  │   ├─ EnterpriseAnchorService: B2B锚点认证状态
  │   ├─ executeLiveBrandScout(): 5家AI平台实时对话 → 快照存储
  │   └─ 输出: brand_mentions + anchor_status + gaps + live_snapshots
  │
  ├─ ② Strategy (策略) — 2s
  │   ├─ extractKeywordsFromScout(): Scout快照高频词提取
  │   ├─ 合并初始关键词 + Scout发现词
  │   └─ 输出: keywords + channel_plan + task_config (含 target_platforms, geo_threshold)
  │
  ├─ ③ Content (内容) — 30-60s
  │   ├─ KnowledgeRetrievalService: Hybrid RAG (向量 0.6 + BM25 0.4 加权)
  │   ├─ LlmOrchestratorService: 多模型智能路由 + 故障切换
  │   ├─ GeoContentScorer: 6维评分 (Q&A结构/数据密度/虚词清洗/结构清晰/专家信号/自包含性)
  │   ├─ GEO < 70 → 自动重写 (最多3次，独立quality计数器)
  │   └─ 输出: article_id + geo_score + geo_grade
  │
  ├─ ④ Deploy (分发) — 120s (等待队列)
  │   ├─ ContentPublishService: 创建任务 → 拆分为 N×M 条作业
  │   ├─ AccountPoolService: 智能选号 (健康度+失败率+日配额排序)
  │   ├─ ContentPublishRateLimiter: 指数退避 + 全局Redis锁
  │   ├─ ProcessContentPublishJob: content-publish 队列，tries=3
  │   ├─ 轮询120s等队列完成 → 汇总 published_channels + failed_channels
  │   └─ 输出: task_id + total_jobs + published/failed_channels + timed_out标记
  │
  └─ ⑤ Review (复盘) — 3s
      ├─ 汇总全链路数据 → needs_iteration 判断
      ├─ 生成 recommendations (GEO建议/分发故障/锚点缺口/超时)
      ├─ iteration<3 且 needs_iteration → 回到 Scout (刷新数据)
      └─ 输出: recommendations + visibility_report → 客户端面板展示
```

**数据流关键**：每个 Agent 的输出通过 `AgentExecution` 表（8态状态机）传递给下一个 Agent。`saveAgentOutput()` 方法在写入时推进状态，确保状态转换合法性。

## 四、RAG 知识检索架构

```
用户关键词
  └→ KnowledgeRetrievalService::retrieveContextFromMany()
      ├─ pgvector HNSW 余弦相似度 (向量检索)
      ├─ tsvector + ts_rank (BM25 全文检索)
      ├─ 0.6:0.4 加权融合
      └→ top-N chunks → 注入 AI prompt
```

## 五、多模型 LLM 调度架构

```
LlmOrchestratorService::chat(ChatRequest)
  ├─ TokenQuotaService: 租户配额检查
  ├─ resolveAiModel(): 查 ai_models 表
  ├─ ApiKeyCrypto::decrypt(): AES-256-CBC 解密
  ├─ LlmAdapterFactory::createByCode(): 适配器工厂
  │   ├─ OpenAiCompatibleAdapter: DeepSeek/豆包/千问/Kimi/智谱/硅基
  │   └─ ErnieQianfanAdapter: 文心一言 (OAuth 2.0)
  ├─ Adapter::chat(): 统一接口 → sendRequestWithAuth()
  ├─ TokenQuotaService::deduct(): 扣减额度
  └─ AgentConversationSnapshot: 自动快照存储
```

## 六、多租户 Workspace 架构

```
Service Provider (运营方)
  └── Super Admin (全部权限)
      ├── Operator A → Workspace 1, 2 (operator_workspaces 绑定)
      └── Operator B → Workspace 3

Workspace (客户隔离单元)
  ├── EnterpriseProfile (1:1 企业档案)
  │   └── EnterpriseAnchorCertifications (1:N B2B锚点)
  ├── ClientUsers (1:N 客户登录)
  ├── Articles (via workspace_assignments 多态表)
  ├── Tasks (via workspace_assignments)
  ├── KnowledgeBases (via workspace_assignments)
  ├── DistributionChannels (1:N 分发渠道)
  ├── ClientPlatformAccounts (1:N 自媒体授权)
  └── ContentPublisherAccounts (1:N 发布账号池)

隔离机制:
  - Controller 基类: scopeByOperatorWorkspaces() 列表过滤
  - Controller 基类: authorizeOperatorAccess() 单条校验
  - Controller 基类: authorizeWorkspaceAccess() workspace操作校验
  - RPA API: localhost白名单 + operator身份绑定 (Tier 1+2)
```

## 七、发布分发架构

```
ContentPublishService (统一入口)
  ├─ createPublishTask(): 文章 × 平台 → 拆分为 ContentPublishResult 明细
  ├─ dispatchPublishTask(): 智能错峰延迟入队
  └─ ProcessContentPublishJob (content-publish 队列, tries=3)
      ├─ AccountPoolService: 按健康度/失败率/日配额选最优账号
      ├─ ContentPublishRateLimiter: 平台级限流 (指数退避+全局锁)
      ├─ PlatformAdapterFactory → BasePlatformAdapter (7个适配器)
      │   ├─ ToutiaoOAuthAdapter: 头条 OAuth 2.0
      │   ├─ BaijiahaoCookieAdapter: 百家号 RPA Cookie
      │   ├─ SohuCookieAdapter: 搜狐号 RPA Cookie
      │   ├─ B2b168RpaAdapter: B2B平台 RPA
      │   ├─ GenericRpaAdapter: 通用 RPA
      │   ├─ MediaBoxApiAdapter: 媒介盒子 API Key
      │   └─ GenericOAuthAdapter: 通用 OAuth 2.0
      └─ BasePlatformAdapter::syncAnchorCertification() → 锚点自动回写
```

## 八、RPA 浏览器自动化引擎

```
Node.js Express (:9901)
  ├─ POST /api/v1/register    → B2B企业注册认证
  ├─ POST /api/v1/publish     → 自媒体文章发布
  ├─ POST /api/v1/auth-login  → 手动登录授权 (Cookie持久化)
  ├─ GET  /api/cache/list     → Cookie缓存状态
  ├─ POST /api/cache/clear    → 清除缓存
  └─ GET  /                    → 运营助Web控制台

BasePlatformScript (399行基类):
  ├─ playwright-extra + stealth 插件 (20+指纹维度)
  ├─ 国产平台补丁 (navigator.webdriver 兜底 + Chrome插件模拟)
  ├─ 真人行为模拟 (随机延时/逐字输入/鼠标轨迹/随机滚动)
  ├─ WAF检测 (30秒轮询等Cloudflare/火山引擎/验证码)
  ├─ storageState Cookie持久化 (workspace_id × platform 隔离)
  └─ browserless Docker支持 (RPA_BACKEND=browserless)

15 个自动化脚本 (14个继承BasePlatformScript, 1个重构完成):
  B2B注册: b2b168 / tz1288 / wjw / k2b2b / lswang / cn5135 / chaxun123 / 
           jiuzhouziyuan / wanjiabiz / huangye88 / shunqi (11个)
  自媒体发布: toutiao_publish / baijiahao_publish / xiaohongshu_publish / sohu_publish (4个)
```

## 九、安全体系

```
传输层:  HTTPS + ApiKeyCrypto (AES-256-CBC, enc:v1:格式)
认证层:  Sanctum (API Token) + Session (Web Auth) + RPA_API_KEY
授权层:  Gate/Policy + scopeByOperatorWorkspaces + authorizeOperatorAccess
防守层:  AdminLoginLockService (暴力破解 5次/15min 锁定)
         ClientAuthController (status=active 校验)
         RPA API 双层: localhost白名单 + operator身份绑定
         CORS 严格限制 + CSRF 保护
日志层:  AdminActivityLog 操作审计
```

## 十、部署架构 (Docker Compose)

```yaml
services:
  postgres (pgvector:pg16)     ← 主数据库
  redis (redis:7-alpine)       ← 缓存/队列
  assets (node:22)             ← 前端构建
  init (php:8.4)               ← 启动初始化 (migrate/install)
  app (php:8.4)                ← Laravel HTTP (:18080)
  queue (php:8.4)              ← 队列 Worker (7个队列, tries=3, timeout=600)
  scheduler (php:8.4)          ← 定时任务
  reverb (php:8.4)             ← WebSocket (:18081)
  rpa-engine (node:18)         ← RPA 自动化引擎 (:9901)
  browserless (chrome)         ← 可选: Chrome 集群 (RPA_BACKEND=browserless)
```

Timeout 链: `Job(600s) < Horizon supervisor(650s) < retry_after(900s)`

## 十一、项目规模与复杂度

| 指标 | 数值 | 说明 |
|------|------|------|
| PHP 代码 | 62,342 行 | 307 个文件 |
| 数据模型 | 62 个 | Eloquent ORM |
| 数据库表 | 74 张 | PostgreSQL |
| 服务类 | 121 个 | 业务逻辑层 |
| 控制器 | 57 个 | HTTP 接口层 |
| 队列 Job | 11 个 | 异步任务 |
| Artisan 命令 | 11 个 | CLI 工具 |
| Blade 视图 | 344 个 | 前后端模板 |
| RPA 脚本 | 15 个 | 浏览器自动化 |
| 迁移文件 | 69 个 | 版本化 schema |
| 支持语言 | 6 种 | zh_CN/en/ja/es/ru/pt_BR |
| AI 平台 | 5 家 | DeepSeek/豆包/千问/Kimi/文心 |
| B2B 锚点 | 30 个 | 企业认证平台 |
| 新闻媒体 | 24 个 | 发稿追踪 |
| 自媒体 | 6 个 | 头条/百家号/小红书/搜狐号等 |
| 前端主题 | 23 套 | GeoFlow 模板 |
