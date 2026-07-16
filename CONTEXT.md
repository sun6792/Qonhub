# 豆流 AI — Domain Context

> 领域术语表。不含实现细节，不含表结构，不含类名。
> 这是团队讨论、架构设计、代码审查的统一词汇基准。
>
> 最后更新：2026-07-16（全量代码 + 数据库扫描后建立）

---

## 一、核心概念（按领域分组）

### 1. 租户与组织

**Workspace（工作空间）**
一个付费客户或服务对象。豆流 AI 的核心隔离单元。一个 Workspace 拥有自己的文章、任务、素材、知识库、分发渠道、企业档案、平台授权。多个工作空间之间数据完全隔离。

**Service Provider（服务商/运营方）**
使用豆流 AI 后台为多个 Workspace 提供 GEO 营销服务的团队。服务商内部有多个运营人员。

**Operator（运营人员）**
服务商团队中的一员。一个 Operator 可以被分配到多个 Workspace。

**Super Admin（超级管理员）**
服务商最高权限者。能看到所有 Workspace、所有 Operator 的跨空间聚合视图。

**Client User（客户用户）**
Workspace 的终端客户。登录客户门户（Client Portal）查看文章、AI 引用数据、平台授权状态。一个 Workspace 可以有多个 Client User。

**Client Portal（客户门户）**
面向 Client User 的自助看板。路径 `/client`。与运营后台（`/geo_admin`）完全分开。

---

### 2. 内容生产

**Article（文章）**
AI 生成的 GEO 优化内容。核心产出物。经历 草稿 → 审核 → 发布 三态流转。每篇文章可包含 SEO 元信息（OG 标签、Schema）、GEO 评分、所属行业/分类。

**Task（任务）**
文章生成任务。定义"用什么标题库、关键词库、知识库、AI 模型，按什么频率自动生成文章"。Task 是自动化的基本调度单元。

**Task Run（任务执行记录）**
Task 的一次实际执行。记录本次用了哪个标题、生成结果如何、耗时多少。

**Draft（草稿）**
Task 自动生成但尚未人工审核的文章。受 `draft_limit` 控制数量上限。

**Review（审核）**
运营人员对 AI 生成文章的人工把关。通过后文章进入"已发布"状态。

**GEO Score（GEO 评分）**
文章发布前的自动质量打分（0-100，A-F 六级）。六个维度：Q&A 结构、自包含性、数据密度、结构清晰度、专家信号、虚词扣分。低于 70 分自动打回 AI 重写，不进入审核流。

---

### 3. 素材与知识

**Material Library（素材库）**
四大素材池的统称——标题库、关键词库、图库、知识库。Task 从这里取素材喂给 AI。

**Title Library（标题库）**
预存文章标题的素材池。支持手动添加和 AI 批量生成。

**Keyword Library（关键词库）**
GEO 关键词的素材池。四类词：核心词、长尾词、地域词、意图词。支持从知识库中"蒸馏"提取。

**Image Library（图库）**
文章配图的素材池。AI 生成文章时从图库中选取配图。

**Knowledge Base（知识库）**
客户上传的文档（PDF/Word/Markdown/URL）经解析、切片、向量化后形成的 RAG 知识源。AI 生成文章时检索相关知识块作为写作素材。

**Knowledge Chunk（知识块）**
知识库中的一个语义片段。经 pgvector 向量化存储，供相似度检索。

**Knowledge Retrieval（知识检索）**
AI 生成文章时，根据关键词从知识库中检索最相关的知识块，注入 Prompt 上下文。

---

### 4. 发布与分发

**Distribution Channel（分发渠道）**
一个文章发布的目标站点。三种类型：
- **GeoFlow Agent**：部署在目标站点的 AI Agent，通过 HMAC 签名的 REST API 接收文章
- **WordPress**：WordPress 站点的 REST API（自动同步分类、标签、图片）
- **Generic HTTP**：自定义 HTTP API（支持 Bearer/Basic/HMAC/自定义 Header 认证）

**Distribution（分发）**
将一篇已发布文章推送到一个 Distribution Channel 的动作。

**Content Publish Task（内容发布任务）**（v2.6.0+）
"一键发布"的业务入口。选择若干文章 + 若干目标平台 → 系统自动拆分发布作业 → 入队执行。与旧 Distribution 体系并列，是新架构的首选入口。

**Content Publish Result（发布结果）**
Content Publish Task 的逐条执行明细。每条记录对应"一篇文章在一个平台上的一次发布尝试"。

**Publisher Account（发布账号）**
在某个平台上用于发布内容的账号凭证。凭证经 AES-256-CBC 加密存储。一个 platform_key 下可以有多个发布账号（账号池）。

**Account Pool（账号池）**
同一 platform_key 下的多个 Publisher Account 的集合。系统按健康度、失败率、日配额自动选择最优账号。

**B2B Certification（B2B 认证/入驻）**
在 B2B 行业平台（如天助网、八方资源网）上注册企业信息、建立企业页面的动作。与"发布文章"并列——认证是建页面，发布是发内容。

---

### 5. 平台与锚点

> ⚠️ **领域冲突警告**：以下三个概念在当前代码中**共用 "platform" 一词**，但它们是三个不同的领域概念。
> 在讨论和设计时，必须明确使用本文定义的精确定术语。

**Self-Media Platform（自媒体平台）**
客户需要授权的内容发布平台。如头条号、百家号、小红书。客户在 Client Portal 中标记授权状态，运营人员通过 RPA 引擎在这些平台上实际操作。

**B2B Anchor Platform（B2B 锚点平台）**
企业信息被收录的 B2B 行业网站。如天助网、八方资源网、1688。目标是让大模型在回答行业问题时引用这些平台上的企业页面。共 30 个。

**Media Anchor Platform（媒体锚点平台）**
企业新闻稿被发布的官方媒体或行业媒体。如山西科技报、博客园。共 24 个。

**Anchor（信息锚点）**
B2B Anchor Platform 和 Media Anchor Platform 的统称。共 54 个。

**Enterprise Profile（企业档案）**
一个 Workspace 的企业工商信息。包含公司全称、统一社会信用代码、法人、注册资本、经营范围、联系方式等。用于 B2B 锚点平台的注册认证。

**NAP+W（企业信息一致性）**
Name（公司名）+ Address（地址）+ Phone（电话）+ Website（官网）的缩写。这四个字段在各平台上的信息必须一致，否则大模型引用时会出现信息矛盾。

**Anchor Certification（锚点认证）**
一个 Enterprise Profile 在某个 Anchor Platform 上的认证状态记录。状态：pending（待认证）/ certified（已认证）/ expired（已过期）。

**LLM Coverage（大模型引用覆盖）**
统计已认证的锚点平台被哪些大模型（豆包、DeepSeek、元宝、文心一言、通义千问、Kimi 等）引用。

---

### 6. AI 品牌监测

**AI Visibility（AI 品牌可见度）**
品牌词在 AI 平台上被提及的程度。系统定期向各 AI 平台发送品牌词查询，检测是否被提及及提及内容。

**AI Visibility Check（品牌检测记录）**
一次品牌词检测的执行记录。包含查询的品牌词、目标 AI 平台、是否被提及、提及内容片段。

**AI Visibility Snapshot（品牌可见度快照）**
某个时间点的品牌可见度聚合数据。用于生成趋势图。

**AI Competitor（AI 竞品）**
Workspace 设置的竞品品牌。系统同时监测自身品牌和竞品品牌在各 AI 平台上的提及率，生成对比报告。

**AI Platform（AI 平台）**
被监测的大模型产品。当前 12 个：豆包、DeepSeek、元宝、文心一言、通义千问、Kimi、讯飞星火、纳米AI、百度AI搜索、微信AI、抖音AI、夸克AI。

---

### 7. 智能体工作流（v2.6.0+）

**Agent Workflow（智能体工作流）**
五阶段自动内容营销流水线：Scout → Strategy → Content → Deploy → Review。

**Scout（侦察阶段）**
检测品牌在 AI 平台上的提及情况 + B2B 锚点认证状态，输出收录缺口清单。

**Strategy（策略阶段）**
基于侦察结果，由 LLM 制定内容选题、平台选择和发布计划。

**Content（内容阶段）**
RAG 检索知识库 → AI 生成文章 → GEO 评分 → 低分自动重写 → 合规预检。

**Deploy（分发阶段）**
将生成的文章通过 Content Publish Task 管线分发到选定平台。自动继承账号池、限流、重试、锚点同步能力。

**Review（复盘阶段）**
评估分发效果，输出迭代建议。如果开启自动迭代（`auto_optimize_iteration`），可自动启动新一轮 Strategy → Content → Deploy。

**Agent Execution（智能体执行记录）**
一次五阶段工作流的完整执行记录。包含每个阶段的输入输出、状态转换、重试次数。

---

### 8. RPA 引擎

**RPA Engine（RPA 引擎）**
基于 Playwright 的 Node.js 浏览器自动化微服务。运行在运营人员本地电脑（`localhost:9901`），负责在自媒体平台和 B2B 平台上执行实际操作（登录、填表、发布）。

**Operator Dashboard（运营助手）**
RPA 引擎自带的本地 Web 控制台（`dashboard.html`）。三栏布局：B2B 待注册平台 / Cookie 缓存状态 / 文章分发操作。

**RPA Auth-Login（RPA 授权登录）**
运营人员在 RPA 引擎中手动登录某个平台，登录完成后 Cookie 持久化保存，后续操作跳过登录。

**RPA Cookie State（RPA Cookie 持久化）**
Playwright 的 `storageState` 文件。按 `{workspace_id}/{platform_key}.json` 组织。有效期内自动加载，到期后用 RPA Auth-Login 刷新。

**CDP（常驻浏览器）**（v2.6.1+）
Chrome DevTools Protocol 常驻模式。浏览器一次启动，持续运行。所有脚本连接同一浏览器实例，共享 Cookie，永不卡退。

**Scout（收录侦察）**（v2.6.1+）
用 Playwright 实测 AI 平台的品牌词搜索——打开豆包/元宝/百度AI，输入品牌词，提取回答内容，判断是否被收录。

---

### 9. 系统运维

**System Update（系统自更新）**
豆流 AI 自身的版本更新体系。从 GitHub Release 检测新版本 → 下载 → 备份 → 应用 → 验证 → 必要时回滚。

**Admin Activity Log（管理员操作日志）**
运营人员在后台的关键操作审计记录。由 `admin.activity` 中间件自动捕获。

**Horizon（队列仪表盘）**
Laravel Horizon 提供的 Redis 队列可视化监控。展示队列积压、处理速度、失败 Job。

---

## 二、术语歧义消除表

> 以下术语在当前代码中容易被混淆。本文档规定精确含义。

| 模糊词 | 精确术语 | 定义 | 切勿混淆为 |
|--------|---------|------|-----------|
| platform | **Self-Media Platform** | 客户要授权的自媒体平台（头条/百家号/小红书） | B2B Anchor Platform |
| platform | **B2B Anchor Platform** | 企业信息被收录的 B2B 网站 | Self-Media Platform |
| platform | **Media Anchor Platform** | 新闻稿发布的目标媒体 | Self-Media Platform |
| platform | **AI Platform** | 被监测品牌提及的大模型产品 | 上述三种都不是 |
| account | **Publisher Account** | 用于发布内容的平台账号凭证 | Client Platform Account |
| account | **Client Platform Account** | 客户标记"已授权"的记录（不一定有凭证） | Publisher Account |
| distribution | **Distribution Channel** | 一个发布目标站点 | Content Publish Task |
| distribution | **Content Publish Task** | 一次"一键发布"操作 | Distribution Channel |
| task | **Task** | AI 文章生成任务 | Content Publish Task |
| task | **Content Publish Task** | 文章分发任务 | Task |
| run | **Task Run** | AI 文章生成任务的一次执行 | Agent Execution |
| run | **Agent Execution** | 五智能体工作流的一次执行 | Task Run |
| scout | **Agent Scout** | 五智能体的侦察阶段 | RPA Scout（Playwright 搜索） |
| scout | **RPA Scout** | Playwright 实测 AI 平台搜索 | Agent Scout |

---

## 三、已知的代码-术语冲突

> 以下问题在代码扫描中发现。代码使用了与本文术语冲突的命名。

### 冲突 1：三张表共用一个 `platform_key`
- `client_platform_accounts.platform_key` — 实际语义是 **Self-Media Platform**
- `content_publisher_accounts.platform_key` — 实际语义是 **Publisher Account** 所属的平台
- `enterprise_anchor_certifications.anchor_platform_key` — 实际语义是 **B2B/Media Anchor Platform**

PlatformSyncService 假设这三个 key 可以在同一个 `platform_key` 值上同步，但实测数据中三张表的 platform_key 只有部分重叠（baijiahao 和 toutiao 三表都有，但 b2b168 只在 publisher 和 certification 表有）。

**这是当前架构的核心张力**：一个自媒体授权（Self-Media Platform）和一个 B2B 锚点认证（B2B Anchor Platform）是不同的业务操作，但它们被"同步"到一个假设的 1:1:1 映射中。

### 冲突 2：两套分发体系并存
- **Distribution（旧）**：`DistributionChannel` + `ArticleDistribution` + `DistributionOrchestrator`
- **Publish（新）**：`ContentPublishTask` + `ContentPublishResult` + `ContentPublishService`

新体系（v2.6.0+）引入了 ContentPublishService 作为首选入口，但旧 Distribution 体系的 Controller 和 Service 仍在运行。两个体系共享同一个 `distribution` 队列。

### 冲突 3：RPA `platform` 概念比 PHP `platform_key` 多一层
- PHP 侧用 `platform_key` 标识一个平台（如 `toutiao`）
- RPA 引擎的 `automations/` 目录用文件名区分"做什么"，但同一个平台可能有多个脚本（如 B2B 平台有注册脚本，自媒体平台有发布脚本）
- RPA 引擎的 API endpoint `/api/v1/register` 和 `/api/v1/publish` 用同一个 `platform` 参数，但实际上调用的是不同的 automation 脚本

---

## 四、领域关系速查

```
Workspace
├── owns → Article (via workspace_assignments)
├── owns → Task (via workspace_assignments)
├── owns → Knowledge Base (via workspace_assignments)
├── owns → Distribution Channel
├── has → Enterprise Profile (1:1)
│   └── has → Anchor Certifications (1:N per Anchor Platform)
├── has → Client Users (1:N)
├── has → Client Platform Accounts (1:N per Self-Media Platform)
├── has → Publisher Accounts (1:N per platform_key)
├── has → AI Competitors (1:N)
├── has → AI Visibility Checks (1:N)
└── assigned → Operators (N:M via operator_workspaces)

Article
├── generated by → Task
├── distributed via → Distribution Channel
├── has → GEO Score
└── published to → Self-Media Platform (via Content Publish Task)

Task
├── uses → Title Library
├── uses → Keyword Library
├── uses → Knowledge Base
├── uses → AI Model
├── produces → Article
└── executed as → Task Run

Agent Workflow
├── Scout → reads AI Visibility + Anchor Certifications
├── Strategy → produces Content Plan
├── Content → produces Article (via Task or direct AI call)
├── Deploy → creates Content Publish Task
└── Review → produces Iteration Recommendations

RPA Engine
├── executes on → Self-Media Platforms (publish)
├── executes on → B2B Anchor Platforms (register/certify)
├── stores → Cookie States (per workspace per platform)
└── syncs → Anchor Certifications (via reportToCloud)
```

---

## 五、状态机速查

### Article 生命周期
```
draft → review → published
  ↓        ↓
deleted  rejected
```

### Task 生命周期
```
active → (paused) → active
                  → archived
```

### Agent Execution 状态转换
```
idle → scouting → planning → writing → deploying → reviewing → completed
  ↓       ↓         ↓          ↓          ↓           ↓
  └───────┴─────────┴──────────┴──────────┴───────────→ failed
                                                    reviewing → planning (auto-iteration loop)
```

### Anchor Certification 状态
```
pending → certified → (expired)
           ↓
         revoked
```

### Publisher Account 健康状态
```
healthy → degraded (consecutive_failures ≥ 3)
        → unhealthy (consecutive_failures ≥ 5 → locked)
```
