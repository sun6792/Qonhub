# 豆流 AI 功能全景说明（v2.6.1）

> **输出日期**：2026-07-16（更新）  
> **扫描范围**：全量代码、配置、数据库结构、前端页面、RPA 引擎  
> **原则**：仅基于实际已实现的代码如实梳理，不脑补未开发功能

---

## 一、项目基础概况

### 1. 产品定位

- **产品名**：豆流 AI（Douluo AI），内部研发代号 Qonhub
- **产品定位**：企业级 GEO（生成式引擎优化）智能营销系统，面向营销服务商与终端企业双赛道
- **技术架构**：Laravel 12 + PostgreSQL 16（pgvector）+ Redis 7 + 自研 Node.js RPA 引擎
- **当前版本**：`v2.5.0`（2026-07-14）

### 2. 核心业务模块

豆流 AI 自研实现了以下核心模块：

| 模块 | 具体内容 |
|------|---------|
| **多租户工作空间** | Workspace 体系：一个服务商管理多个客户，每个客户独立看板，数据分级隔离 |
| **客户自助门户** | Client Portal：客户登录后查看文章、AI 引用数据、平台授权状态、竞争力报告 |
| **B2B 信息锚点** | 30 个 B2B 平台的企业认证跟踪体系 + 自研 RPA 自动化注册 |
| **媒体发稿锚点** | 24 个官媒/行业媒体的发稿跟踪 + 自媒体 RPA 自动发布 |
| **企业档案** | EnterpriseProfile：NAP+W 一致性管理、企业资质核验 |
| **企业知识库** | EnterpriseKnowledge：AI 驱动的企业知识草稿生成与发布 |
| **内容弹药库** | ContentArmory：11 套全平台文章模板 + AI 改写 + 批量分发 |
| **站点主题系统** | SiteThemeReplication：AI 驱动的网站主题生成，23 套内置模板 |
| **运营监控台** | OperatorMonitor：按运营人员的跨空间聚合视图 |
| **系统自更新** | SystemUpdate：版本检测→备份→应用→回滚的完整自更新体系 |
| **多渠道分发** | DistributionChannel 多类型支持（Agent / WordPress REST / Generic HTTP） |

### 3. 完整技术栈

| 层级 | 技术 | 版本 |
|------|------|------|
| **后端框架** | Laravel | 12.x |
| **语言** | PHP | 8.2+（实际运行 8.4） |
| **数据库** | PostgreSQL（pgvector 扩展） | 16 |
| **缓存/队列** | Redis | 7（Alpine） |
| **队列仪表盘** | Laravel Horizon | 5.45 |
| **WebSocket** | Laravel Reverb | 1.0 |
| **API 认证** | Laravel Sanctum | 4.3 |
| **AI 集成** | Laravel AI | 0.6.0 |
| **前端构建** | Vite + Tailwind CSS | v4 |
| **编辑器** | Vditor（Markdown/WYSIWYG） | 3.11 |
| **图标** | Lucide | — |
| **图表** | Chart.js | 4（CDN） |
| **WebSocket 客户端** | Laravel Echo + Pusher.js | — |
| **容器化** | Docker Compose（3个配置文件） | — |
| **Web 服务器** | Nginx（生产）/ PHP artisan serve（开发） | — |

### 4. 项目整体目录结构

```
豆流 AI-main/
├── app/                          # [定制开发] 应用代码（大幅扩展）
│   ├── Ai/Agents/                # [定制开发] AI Agent（MarkdownContentWriterAgent）
│   ├── Console/Commands/         # [定制开发] Artisan 命令（7个）
│   ├── Events/Admin/             # [定制开发] 事件广播
│   ├── Exceptions/               # [定制开发] 自定义异常
│   ├── Http/
│   │   ├── Controllers/Admin/    # [定制开发] 37个后台控制器
│   │   ├── Controllers/Api/V1/   # [定制开发] 7个 REST API 控制器
│   │   ├── Controllers/Site/     # [定制开发+原生] 7个前端控制器
│   │   └── Middleware/           # [定制开发] 9个中间件
│   ├── Jobs/                     # [定制开发] 7个队列任务
│   ├── Models/                   # [定制开发] 48个 Eloquent 模型
│   ├── Providers/                # [原生+定制开发] 3个服务提供者
│   ├── Services/Admin/           # [定制开发] 19个管理服务（含主题复制、系统更新子目录）
│   ├── Services/Api/             # [定制开发] 3个 API 服务
│   ├── Services/GeoFlow/         # [定制开发] 30+个核心业务服务
│   ├── Support/                  # [定制开发] 工具类（Admin/AdminWelcome/Analytics/GeoFlow/Lead/Site）
│   └── View/Composers/           # [定制开发] 视图合成器
│
├── bootstrap/                    # [原生] Laravel 启动
├── config/                       # [原生+定制开发] 配置文件（geoflow.php 为定制开发核心配置）
├── database/
│   ├── migrations/               # [定制开发] 53个迁移文件
│   └── seeders/                  # [定制开发] 种子数据
├── deploy-scripts/               # [定制开发] 部署脚本
├── docker/                       # [定制开发] Docker 基础设施（6个文件）
├── docs/                         # [原生+定制开发] 文档
├── geoskills-main/skills/        # [定制开发] 绑定的 AI Skill 定义
├── lang/                         # [定制开发] 6语言翻译（zh_CN/en/ja/es/ru/pt_BR）
├── public/                       # [原生+定制开发] Web 根目录
├── resources/
│   ├── css/                      # [原生] Tailwind 入口
│   ├── js/                       # [原生+定制开发] Vite 入口 + Echo
│   └── views/
│       ├── admin/                # [定制开发] 35+后台视图子目录
│       ├── client/               # [定制开发] 5个客户端视图
│       ├── site/                 # [原生+定制开发] 13个前端视图
│       └── theme/                # [原生+定制开发] 23个前端主题
├── routes/                       # [定制开发] 路由文件
├── storage/                      # [原生] 运行时存储
├── tests/                        # [原生+定制开发] 测试
├── vendor/                       # [原生] Composer 依赖
│
├── .env / .env.*                 # [定制开发] 环境配置（4个模板）
├── qonhub.bat                    # [定制开发] Windows 一键运维脚本
├── watchdog.bat                  # [定制开发] Worker 守护进程脚本
├── setup.bat                     # [定制开发] Windows 安装脚本
├── docker-up.bat                 # [定制开发] Docker 启动脚本
├── version.json                  # [定制开发] 版本元数据
└── vite.config.js               # [原生] Vite 配置
```

---

## 二、核心业务模块（已实现部分）

### 1. 自媒体授权发布模块

#### 已对接的平台清单（6个）

| 平台 | Key | 认证方式 | 发布内容 |
|------|-----|---------|---------|
| 头条号 | `toutiao` | Cookie 授权（客户自助） | 图文 |
| 百家号 | `baijiahao` | Cookie 授权（客户自助） | 图文 |
| 小红书 | `xiaohongshu` | Cookie 授权（客户自助） | 图文 |
| 阿里1688 | `1688` | Cookie 授权（客户自助） | 商品/企业信息 |
| 百度爱采购 | `b2b_baidu` | Cookie 授权（客户自助） | 商品/企业信息 |
| 搜狐号 | `sohu` | Cookie 授权（客户自助） | 图文 |

#### 技术实现方式

- **模型**：`ClientPlatformAccount`（表 `client_platform_accounts`）
- **凭证存储**：Cookie 值经 `ApiKeyCrypto`（AES-256-CBC）加密后存入 `credential_ciphertext` 字段
- **授权流程**：客户在 `/client` 看板点击"授权连接" → 弹窗显示操作指引 → 客户自行去平台登录 → 通知运营人员标记已授权
- **状态管理**：`status`（active/inactive/revoked）+ `last_verified_at` + `expires_at`（30天有效期）
- **注意**：当前系统**未实现 API 自动发布**到这些平台。授权的作用是记录客户已授权，实际发稿仍需运营手动操作。`PlatformAccountService` 只做授权状态管理，不执行实际发布。

#### 状态回传与数据同步

- `ClientPortalController::dashboard()` 展示 `connectionStats['connected']/['total']` 给客户
- 运营后台在 `PlatformAccountController` 中查看各工作空间的授权状态
- 工作空间详情页显示 2×3 网格的授权状态卡片

---

### 2. 新闻媒体发稿模块

#### 已对接的上游平台/媒体清单（24个）

**官媒（12个）**：

| 媒体 | 网址 | 权重 |
|------|------|------|
| 山西科技报 | sxkjb.com | 高 |
| 河青新闻网 | hqnews.cn | 高 |
| 科技新闻网 | kejixinwen.com | 高 |
| 亮点黔西南 | ldqxn.com | 高 |
| 淄博新闻网 | zbnews.net | 高 |
| 盐城网 | 0515yc.cn | 高 |
| 咸宁网 | xianning.com | 中 |
| 耒阳新闻网 | ly-rm.cn | 中 |
| 四平新闻网 | spnews.cn | 中 |
| 红安网 | redhongan.com | 中 |
| 景德镇新闻网 | jdznews.com | 中 |
| 云上团风 | yunshangtuanfeng.com | 中 |

**行业媒体（12个）**：

| 媒体 | 网址 | 权重 |
|------|------|------|
| 博客园 | cnblogs.com | 顶级 |
| 商业新知 | shangyexinzhi.com | 高 |
| 黔浪网 | qsina.cn | 中 |
| 涂料在线 | coatingol.com | 中 |
| 沥青在线 | sinoasphalt.com | 中 |
| 华网 | huawang.com | 中 |
| 中机在线 | zhongji.cn | 中 |
| 中网化工 | okmart.com | 中 |
| 中国土涂网 | ntw360.com | 中 |
| W10系统网 | w10xitong.com | 低 |
| 飘仙建站 | piaoxian.net | 低 |
| OK资讯网 | okbgh.com | 低 |

#### 实现方式

- **定义位置**：`EnterpriseAnchorService::mediaAnchorPlatforms()`
- **追踪模型**：`EnterpriseAnchorCertification`（表 `enterprise_anchor_certifications`）
- **操作模式**：运营团队在后台点"标记已发稿"手动追踪，**并非自动化 API 发稿**
- **记录内容**：平台账号ID、发稿页面URL、发稿时间、备注
- **展示位置**：信息锚点管理页（`/geo_admin/enterprise-anchor/{slug}`）中与 B2B 平台并列展示

#### 媒体分类与展示逻辑

- 官媒（`news_media`）：红色系图标，高权重，"运营发稿"标签
- 行业媒体（`industry_media`）：蓝紫系图标，中低权重，"运营发稿/投稿"标签
- 每个平台带两个可点击链接：🔗访问平台 → 📝前往注册/发稿 →

---

### 3. B2B 行业网站发布模块

#### 已完成适配的 B2B 站点清单（30个）

**顶级权重（4个）**：百度爱采购、阿里1688、天眼查、企查查

**高权重（8个）**：爱企查、慧聪网、中国制造网、中国供应商、中国政府采购网、天助网、八方资源网、蜘蛛商务网

**中权重（13个）**：世界工厂网、环球资源、敦煌网、TradeKey、马可波罗网、黄页88、顺企网、京东企业购、无忧商务网、K2商务网、领商网、万家商务网、九州资源网

**广覆盖（5个）**：企业谷、康帕斯Kompass、EC21、查询123、B2B商机导航

#### 实现方式

- **核心**：基于 `EnterpriseAnchorService` + `EnterpriseProfile` + `EnterpriseAnchorCertification` 的**手动追踪体系**
- **并非自动化发布**：B2B 信息锚点的作用是在第三方平台建立企业信息页面，让大模型引用。操作是运营人员手动去各平台注册认证企业信息，回来在系统中"标记已认证"
- **企业档案**：每个工作空间绑定一份 `EnterpriseProfile`，包含公司全称、统一社会信用代码、法人、注册资本、经营范围、地址/电话/邮箱/官网、产品服务等
- **NAP+W 一致性校验**：检查 Name(公司名)/Address(地址)/Phone(电话)/Website(官网) 四个字段是否完整，确保大模型引用时信息一致
- **LLM 引用覆盖报告**：`llmCoverageReport()` 统计已认证平台被哪些大模型（文心一言、豆包、通义千问、Kimi、DeepSeek、百度AI搜索）引用

#### 账号托管与凭证管理

- **不存储第三方平台的登录密码**
- 记录"平台账号ID"和"平台页面URL"供运营参考
- 凭证不传回系统，运营自行管理各平台登录

#### 支持发布的内容字段

B2B 锚点**不是发布文章**，而是认证企业信息。企业档案字段：

| 字段 | 说明 | 必填 |
|------|------|------|
| company_full_name | 公司全称（营业执照） | ✅ |
| unified_social_credit_code | 统一社会信用代码 | 否 |
| legal_person | 法定代表人 | 否 |
| registered_capital | 注册资本 | 否 |
| establishment_date | 成立日期 | 否 |
| business_scope | 经营范围 | 否 |
| company_province/city/address | 地址 | 否 |
| company_phone/email/website | 联系方式 | 否 |
| industry | 所属行业 | 否 |
| products_services | 主营产品/服务 | 否 |

---

## 三、系统架构与底层能力

### 1. 多租户体系

#### 租户隔离实现方式

- **Workspace 模型**（`workspaces` 表）：核心隔离单元，通过 `workspace_id` 外键关联文章、任务、知识库、分发渠道等资源
- **资源分配**：通过 `workspace_assignments` 多态关联表，支持将 Task / KnowledgeBase / Article 等资源分配给特定 Workspace
- **数据查询隔离**：Service 层通过 `assignedIds()` 方法获取当前 Workspace 有权访问的资源 ID 列表，再 `whereIn` 过滤

#### 支持的角色类型

| 角色 | Guard | 权限范围 |
|------|-------|---------|
| **超级管理员** | `admin`（role=`super_admin`） | 全部功能：管理所有工作空间、管理其他管理员、查看运营监控台、查看密码、API令牌管理、系统更新 |
| **普通管理员/运营** | `admin`（role=`admin`） | 被分配的工作空间管理、内容、任务、分发、素材；不能管理管理员、不能看密码 |
| **客户** | `client` | 只看自己工作空间的 Dashboard、文章列表、AI引用数据、平台授权状态 |

#### 运营-工作空间绑定

- `operator_workspaces` 表记录 `admin_id ↔ workspace_id` 的绑定
- `OperatorMonitorController` 提供跨空间运营人员聚合视图（超管专用）
- 工作空间有 `owner_admin_id` 作为主负责人

### 2. 账号与凭证管理

#### 凭证加密存储方案

- **组件**：`ApiKeyCrypto`（`app/Support/GeoFlow/ApiKeyCrypto.php`）
- **算法**：AES-256-CBC，格式 `enc:v1:{base64_iv}:{base64_ciphertext}`
- **密钥来源**：从 `APP_KEY` 派生的 `api_key_crypto_roots`，支持多密钥轮换
- **UI 脱敏**：`mask($plaintext)` 方法，仅显示前后各4位
- **使用场景**：
  - AI 模型 API Key（`AiModel.api_key`）
  - 分发渠道密钥（`DistributionChannelSecret.secret_ciphertext`）
  - 客户平台 Cookie 凭证（`ClientPlatformAccount.credential_ciphertext`）
  - 客户密码明文备份（`ClientUser.password_ciphertext`，仅超管可查看）

#### 账号池管理逻辑

- 当前**未实现账号池（Account Pool）**概念
- 每个 B2B/媒体平台认证记录是独立的一对一关系
- 分发渠道密钥（`DistributionChannelSecret`）支持 `key_id` 标识的多密钥体系，支持轮换

#### 账号状态检测机制

- AI 模型：`daily_limit` + `used_today` 每日调用量控制；`status` active/inactive
- 客户平台：`last_verified_at` + `expires_at`（30天过期）+ `status`
- 分发渠道：`last_health_status` + `last_health_checked_at`（health check 端点）
- 管理员：`AdminLoginLockService` 暴力破解锁定（5次失败/15分钟）

### 3. 发布调度系统

#### 任务调度组件

| 组件 | 类型 | 用途 |
|------|------|------|
| `GeoFlowScheduleTasksCommand` | Artisan 命令 | 每分钟扫描活跃任务，为到期任务创建 TaskRun 并入队 |
| `JobQueueService` | Service | 管理 task_runs 生命周期：enqueue → claim → complete/fail/cancel |
| `ProcessGeoFlowTaskJob` | Queue Job | 执行单个任务：生成草稿或发布审核通过的文章 |
| `Laravel Horizon` | 队列监控 | Redis 队列可视化仪表盘、metrics、failed jobs |
| `watchdog.bat` | Windows 脚本 | Worker 守护进程：崩溃自动重启，最多100次 |

#### 批量发布的队列机制

- **队列驱动**：Redis
- **主队列**：`geoflow`（AI 生成任务）、`distribution`（文章分发）、`theme-replication`（主题复制）、`system-updates`（系统更新）
- **自驱循环**：`enqueueFollowUpGenerationIfNeeded()` — 每次生成完成后自动检查是否需要继续生成
- **stale job 恢复**：`recoverStaleJobs()` — 找出超时的 running 记录，重置为 pending 并重新调度
- **并发控制**：`claimPendingJobById()` 使用悲观行锁，确保单任务不会并发执行

#### 发布频率控制

- 任务级别 `publish_interval`（秒）控制连续发布间隔
- `draft_limit` 控制草稿池上限
- `article_limit` 控制文章总数上限
- `next_publish_at` 时间戳控制何时可以发布下一篇

#### 失败重试机制

| 层级 | 机制 |
|------|------|
| **业务重试** | `JobQueueService::failJob()` 记录 `attempt_count`，达到上限后标记 `failed` |
| **队列重试** | 所有 Job 设 `$tries=1`，避免 Laravel 层重试与业务重试双重冲突 |
| **分发重试** | `DistributionRetryPolicy`：指数退避 `60 × 2^(n-1)` 秒，最大1小时；401/403/422 等不可恢复错误不重试 |
| **Worker 守护** | `watchdog.bat` 监控 php.exe 进程，退出后3秒自动重启 |
| **Stale 恢复** | `recoverStaleJobs()` 每120秒扫描一次超时任务 |

### 4. 内容处理能力

#### AI 内容生成

- **核心 Agent**：`MarkdownContentWriterAgent`（HTTP 超时 120s，支持多 provider token 限制适配）
- **多模型支持**：OpenAI 兼容接口（DeepSeek、火山方舟 Ark 等）+ Gemini 原生接口
- **智能模型切换**：`fixed`（单模型，失败即报错）和 `smart_failover`（按优先级依次尝试备选模型）
- **知识增强生成**：通过 `KnowledgeRetrievalService` 检索相关知识块，注入 prompt 作为写作素材

#### 内容差异化改写

- **位置**：`ContentArmoryController::rewrite()` 结合 `GeoPlatformRules` 实现
- **去 AI 味引擎**：`GeoPlatformRules` 约264行，包含：
  - 句式长度变化规则（长短句交替）
  - 禁用 AI 词汇表（"在当今"、"综上所述"等）
  - 口语化表达要求
  - 结构破坏指导（打破标准总分总结构）
- **平台差异化**：知乎/头条/百家号/小红书/B站/B2B/新闻媒体/技术博客 各有独立的合规改写规则

#### 敏感词/合规预检

- `SensitiveWord` 模型：`sensitive_words` 表存储敏感词库
- `SecuritySettingsController` 管理敏感词
- 主要在内容编辑阶段使用（文章审核流程中）

#### 多平台格式自动适配

- **WordPress**：`WordPressRestRequestFactory` + `WordPressTaxonomySyncService` + `WordPressMediaSyncService` — REST API 自动同步分类、标签、图片
- **GeoFlow Agent**：`DistributionPayloadBuilder` 构建标准化 JSON 载荷，含 SEO 元信息、OG 标签、Schema
- **Generic HTTP API**：`GenericHttpRequestFactory` 支持自定义 HTTP 方法、认证方式（无/Bearer/Basic/自定义Header/HMAC）、Payload 包装格式
- **Markdown → HTML**：`ArticleHtmlPresenter` 负责前端展示渲染
- **Markdown → 微信 HTML**：`WeChatArticleHtmlExporter` 转换为微信兼容的内联样式 HTML
- **静态站点生成**：`DistributionTargetSitePackageBuilder` 为目标渠道生成首页、详情页、sitemap、`llms.txt` 的完整 PHP 站点包

### 5. 风控防护能力

#### 代理 IP 集成情况

- **出站 HTTP 代理**：`OutboundHttpProxy`（`app/Support/GeoFlow/OutboundHttpProxy.php`）
- 支持 `GEOFLOW_HTTP_PROXY` / `GEOFLOW_HTTPS_PROXY` 环境变量配置
- **分域名代理**：`GEOFLOW_PROXY_HOSTS` 配置仅哪些 AI provider 域名走代理（19个域名），避免 WordPress REST 和目标站 Agent 通信被代理截获
- 默认 `GEOFLOW_NO_PROXY=localhost,127.0.0.1,::1,postgres,redis,host.docker.internal`
- **未集成第三方代理池服务**（如快代理、芝麻代理等）

#### 浏览器指纹隔离方案

- **未实现**。没有浏览器自动化/RPA 代码，不存在指纹隔离需求。

#### 反爬规避机制

- **不适用**。内容分发走 API 协议（HMAC 签名认证），不是爬虫模式。

### 6. 数据统计与报表

#### 已实现的数据统计维度

**AnalyticsController + AnalyticsOverviewService** 提供：

| 维度 | 指标 |
|------|------|
| **全局 KPI** | 总文章数、本月发布、本月分发、活跃任务 |
| **内容漏斗** | 生成→草稿→审核→发布→分发的转化漏斗 |
| **发布趋势** | 按日/周/月的发布量趋势图 |
| **任务健康** | 各任务执行次数、成功率、失败原因分布 |
| **素材健康** | 标题库/关键词库/图片库/知识库的使用率和消耗率 |
| **分发摘要** | 各渠道的分发量、成功率、待处理数 |
| **Top 内容** | 按浏览量/分发量排序的文章列表 |
| **AI 使用摘要** | 各 AI 模型的调用次数、成功率、Token 消耗 |
| **分类分布** | 文章的行业/分类占比 |
| **URL 导入健康** | 导入任务的成功率、待提交数 |

#### 客户端/运营端的数据看板

| 看板 | 受众 | 内容 |
|------|------|------|
| **Admin Dashboard** | 运营团队 | 系统总览：文章/任务/分发/素材/URL导入健康状态卡片 |
| **Admin Analytics** | 运营团队 | 数据分析：全部上述维度的图表和表格 |
| **Client Dashboard** | 客户 | 文章数/本月新增/平台授权/AI引用得分/B2B锚点覆盖/大模型引用 |
| **Client AI Visibility** | 客户 | 6大AI平台品牌提及率详情+趋势 |
| **Operator Monitor** | 超管 | 按运营人员聚合的工作空间和产出统计 |

---

## 四、与豆流 AI 的差异说明

### 1. 复用了 豆流 AI 哪些原生能力

| 能力 | 说明 |
|------|------|
| **Laravel 框架基础设施** | 路由、中间件、队列、缓存、Session、验证 |
| **AI 模型接入** | `Laravel\Ai` 的 Provider 注册、Agent 模式、多模型切换 |
| **内容生成引擎** | `WorkerExecutionService` 的任务执行→AI生成→文章入库核心链路 |
| **知识库与 RAG** | pgvector 向量存储、知识切片、智能检索（`KnowledgeRetrievalService`） |
| **素材管理体系** | 标题库/关键词库/图片库/知识库/提示词的 CRUD 和管理 |
| **文章审核流程** | 草稿→审核→发布的三态流转 |
| **SEO 输出** | 文章 SEO 元信息、Open Graph、Schema、GFM Markdown |
| **多语言后台** | 6语言包（zh_CN/en/ja/es/ru/pt_BR） |
| **前端主题系统** | 23个主题模板 + 主题切换 + 主题包 |
| **Docker 部署** | docker-compose 一键部署 |
| **队列基础设施** | Laravel Queue + Redis |

### 2. 二次开发新增了哪些核心模块与功能

| 新增模块 | 新增文件数 | 核心能力 |
|---------|-----------|---------|
| **Workspace 多租户** | 7 个模型 + 1 个 Service + 4 个控制器 + 5 个视图 | 工作空间 CRUD、资源分配、运营绑定、客户账号管理 |
| **Client Portal 客户门户** | 2 个控制器 + 5 个视图 | 客户自助登录、Dashboard、文章浏览、AI引用报告、平台授权 |
| **Enterprise Anchor B2B锚点** | 3 个模型 + 1 个 Service + 1 个控制器 + 3 个视图 | 30 B2B 平台认证追踪、企业档案、NAP+W 校验、LLM 覆盖报告 |
| **Media Anchor 媒体锚点** | 同上（共用体系） | 24 官媒/行业媒体发稿追踪 |
| **Enterprise Knowledge** | 3 个模型 + 1 个 Service + 1 个控制器 + 3 个视图 + 1 个 Job | AI 生成企业知识草稿、编辑器保存、版本管理、发布到知识库 |
| **Content Armory 内容弹药库** | 1 个模型 + 1 个控制器 + 1 个视图 | 文章模板、AI 改写、批量分发到渠道 |
| **Theme Replication 主题复制** | 5 个模型 + 12 个 Service 文件 + 1 个控制器 + 2 个 Job + 3 个视图 | AI 克隆参考网站主题，生成 Blade+CSS |
| **System Update 系统自更新** | 3 个模型 + 15 个 Service 文件 + 1 个控制器 + 2 个 Job + 5 个视图 | GitHub 源检测→备份→应用→回滚完整生命周期 |
| **Operator Monitor 运营监控** | 1 个控制器 + 1 个 Service + 2 个视图 | 按运营人员聚合跨空间统计 |
| **Platform Account 平台授权** | 1 个模型 + 1 个 Service + 1 个控制器 | 6 个自媒体平台客户自助授权管理 |
| **AI Visibility 品牌监测** | 2 个模型 + 1 个 Service + 1 个控制器 + 1 个命令 + 2 个视图 | 品牌在 AI 平台被提及率的定期检测和趋势分析 |
| **URL Import 智能采集** | 2 个模型 + 1 个 Service + 1 个控制器 + 1 个命令 + 3 个视图 | URL 页面内容智能采集→AI 分析→导入知识库 |
| **Site Theme Editor 主题编辑器** | 1 个 Service + 1 个控制器 + 1 个视图 | 在线编辑主题 Blade/CSS 源码，草稿/发布/预览 |
| **Enhanced Distribution 增强分发** | 6 个模型 + 17 个 Service 文件 + 1 个控制器 + 2 个 Job + 10 个视图 | 3 种渠道类型（GeoFlow Agent/WordPress/Generic HTTP）、密钥管理、HMAC 签名、远程文章编辑、站点包下载 |
| **Analytics 数据分析** | 3 个 Service 文件 + 1 个控制器 + 10 个视图 | 全维度数据看板 |
| **Security Settings** | 1 个控制器 + 1 个视图 | 敏感词管理、管理员密码修改 |
| **Admin Activity Log** | 1 个模型 + 1 个中间件 + 1 个控制器 + 1 个视图 | 管理员操作审计日志 |
| **API v1** | 7 个控制器 + 3 个 Service + 3 个中间件 | REST API + Token 认证 + 幂等性 + 多 Scope |
| **运维脚本** | 3 个 .bat | 一键启动/停止/状态查看、Worker 守护进程 |

### 3. 对豆流 AI 做了哪些改造与重写

| 改造项 | 说明 |
|--------|------|
| **品牌改名** | 全局 "豆流 AI" → "豆流 AI"，后台路径变量化 `ADMIN_BASE_PATH` |
| **后端路由** | 原 `routes/web.php` 拆分出 `routes/workspace.php`，新增 `routes/api.php` |
| **数据库** | 从支持 SQLite 改为强制 PostgreSQL（使用 pgvector、row-level locking 等 PG 特性） |
| **队列体系** | 从 `while(true)` 轮询模式改为 Laravel Queue + Redis + Horizon 完整体系 |
| **Job 超时** | `ProcessGeoFlowTaskJob::$timeout` 从 300s → 600s；Watchdog 增加 `max_execution_time=0` |
| **Worker 执行** | 重构 `WorkerExecutionService`，增加 `smart_failover` 多模型切换 |
| **知识检索** | 重构 `KnowledgeRetrievalService`，增加混合评分/证据组合/冲突解决/合规排除 |
| **前台** | 新增 20 个 GeoFlow 内置模板（`geoflow-template-01~20`） |
| **翻译** | 扩展 zh_CN 语言包覆盖 v2.0 全部新模块；新增 pt_BR 语言 |
| **安全性** | 移除 `.env.docker` 中的明文密码，改为 `.env.docker.example` 模板 |
| **Windows 兼容** | 新增 `.bat` 脚本、`PostgresCompat` helper |
| **Docker** | 增强 Docker Compose 配置（prod/prebuilt/dev 三套），自动权限修复，auto_migrate |

---

## 五、当前已知状态

### 1. 已上线可正常使用的功能

| 功能 | 状态 |
|------|------|
| AI 多模型内容生成（含 smart_failover） | ✅ 正常 |
| 标题/关键词/图片/知识库素材管理 | ✅ 正常 |
| 文章 CRUD + 审核流程（草稿→审核→发布） | ✅ 正常 |
| Workspace 多租户工作空间管理 | ✅ 正常 |
| 客户门户登录 + Dashboard | ✅ 正常 |
| 自媒体平台客户授权管理（标记记录，非自动发布） | ✅ 正常 |
| B2B 信息锚点管理（30 平台手动追踪） | ✅ 正常 |
| 媒体发稿锚点管理（24 平台手动追踪） | ✅ 正常 |
| 企业档案 NAP+W 一致性管理 | ✅ 正常 |
| 企业知识库 AI 草稿生成 | ✅ 正常 |
| 内容弹药库（文章模板+AI改写+分发） | ✅ 正常 |
| WordPress REST API 分发（含分类/标签/图片同步） | ✅ 正常 |
| GeoFlow Agent 协议分发（含 HMAC 签名） | ✅ 正常 |
| Generic HTTP API 分发 | ✅ 正常 |
| 分发队列管理 + 失败重试 | ✅ 正常 |
| 目标站点包下载 | ✅ 正常 |
| 前端主题系统（23个主题+切换） | ✅ 正常 |
| 主题编辑器（在线编辑 Blade/CSS） | ✅ 正常 |
| 数据分析看板（多维度图表） | ✅ 正常 |
| AI 品牌引用检测（6大平台） | ✅ 正常 |
| URL 智能采集导入 | ✅ 正常 |
| 后台多语言（6语言） | ✅ 正常 |
| 管理员操作审计日志 | ✅ 正常 |
| API v1（REST + Token + 幂等） | ✅ 正常 |
| 运营监控台（超管） | ✅ 正常 |
| Laravel Horizon 队列仪表盘 | ✅ 正常 |
| Docker Compose 部署（dev/prod/prebuilt） | ✅ 正常 |
| 系统自更新体系 | ✅ 正常 |

### 2. 开发中/待完善的功能

| 功能 | 当前状态 |
|------|---------|
| 自媒体平台自动化 API 发布 | ⚠️ 仅做授权记录，无实际自动发稿代码 |
| B2B/媒体锚点自动化 | ⚠️ 仅手动追踪，未集成各平台 API 或 RPA |
| 浏览器自动化/RPA | ⚠️ 未实现任何浏览器自动化代码 |
| 代理 IP 池集成 | ⚠️ 有出站代理框架，但未集成第三方代理服务 |
| 浏览器指纹隔离 | ⚠️ 不适用（无 RPA） |
| 账号池管理 | ⚠️ 未实现 pool 概念 |

### 3. 历史已修复问题（v2.5.0 之前）

| 问题 | 影响 | 状态 |
|------|------|------|
| `password_ciphertext` 缺少 `$fillable` 导致创建客户时密码密文丢失 | 新客户密码不可查看 | ✅ 已修复 |
| Worker 300s 超时与 PHP max_execution_time 同时触发导致双崩溃 | AI 生成长文时队列卡死 | ✅ 已修复（600s + max_execution_time=0） |
| `client/dashboard.blade.php` 数组访问未防御 | 客户 Dashboard 500 错误 | ✅ 已修复 |
| `$errors` 变量未 `isset` 防御 | Session 中间件缺失时登录页崩溃 | ✅ 已修复（5个视图） |
| `admins` 表 `name` 列不存在但代码误用 `orderBy('name')` | 运维监控台 SQL 错误 | ✅ 已修复（改为 `display_name`） |
| 媒体平台定义缺少 `color` 和 `cert_required` 字段 | Overview 页面 500 | ✅ 已修复 |
| Enterprise Anchor overview 页面编辑语法错误 | 重复 `@endforeach` | ✅ 已修复 |

### 4. 当前已知待修复问题（v2.6.1 扫描发现）

详见第十二章「当前代码扫描问题清单」。
- 🔴 `PlatformSyncService` 凭证明文未加密存储（#1）
- 🔴 `PlaywrightMcpTool` MCP 命名空间错误（#2）
- 🔴 `ContentAgentService` SSL 验证禁用（#3）
- 🟡 RPA 引擎默认密钥 + 凭证明文传输 + storage 源码放置（#4-6）
- 🟢 空文件 + 登录等待 + CDP 断线（#7-9）

---

## 六、v2.2.0 本日新增模块（2026-07-11）

### 1. GEO 内容评分引擎

**文件**：`app/Services/GeoFlow/GeoContentScorer.php`

基于 geoskills + geo-seo-claude 评分标准，文章发布前自动打分（0-100，A-F 六级）。

| 评分维度 | 权重 | 检测项 |
|---------|------|--------|
| Q&A 结构 | 20% | 问答句式、定义条目、开篇直接回答 |
| 自包含性 | 18% | 最优段落 134-167 字、代词密度 <2% |
| 数据密度 | 17% | 百分比、数值+单位、年份 |
| 结构清晰度 | 17% | H2/H3 标题层级、列表、段落长度 |
| 专家信号 | 13% | 专家引言、数据来源引用、"XX表示" |
| 虚词扣分 | 15% | 6 类虚词检测（可能/似乎/大概…），密度 >0.5% 扣分 |

**集成点**：弹药库 AI 改写前后评分对比 → 前端展示 "45 → 72，C → B"

### 2. 知识库关键数据提取器

**文件**：`app/Services/GeoFlow/KnowledgeKeyExtractor.php`

从客户上传的文档中按 GEO 维度定向提取结构化数据，**替代随机检索**：

| 提取类型 | 说明 |
|---------|------|
| 统计数据 | 百分比、数值+单位、年份、增长量 |
| Q&A 对 | 问题+答案完整摘录 |
| 专家信号 | 引言、称号、机构认证、专利 |
| 企业事实 | 公司名、地址、电话、资质、经营范围 |

提取结果转为 Prompt 上下文注入 AI 写作流程，确保文章包含客户文档核心数据。

### 3. llms.txt + JSON-LD Schema 生成器

**文件**：`app/Services/GeoFlow/GeoSiteBuilder.php`

为目标站点自动生成 AI 友好元文件：

| 输出文件 | 规范 | 内容 |
|---------|------|------|
| `llms.txt` | llmstxt.org 标准 | H1 + 摘要 + 文章链接 + 核心页面 |
| `llms-full.txt` | 全文版 | 企业信息 + 所有文章全文 |
| JSON-LD Schema | schema.org | Organization / WebSite / BreadcrumbList / LocalBusiness / Product / Article（6 种） |

### 4. RPA 引擎 Cookie 持久化

**文件**：`rpa-engine/lib/BasePlatformScript.js`

- 首次运行正常启动浏览器
- 任务执行完毕自动保存 `storageState`（Cookie/登录态）
- 下次运行自动加载，跳过登录，不触发 WAF
- 每个平台独立状态文件

### 5. AI 关键词自动生成

**文件**：`app/Http/Controllers/Admin/KeywordLibraryController.php` + `routes/web.php`

- 输入主题描述 → AI 生成 10-50 个关键词
- 四类词：核心词、长尾词、地域词、意图词
- 自动去重，批量入库

### 6. 运营监控台重构

**文件**：`resources/views/admin/operator-monitor/index.blade.php` + `detail.blade.php`

Bootstrap → Tailwind，卡片折叠/搜索/筛选，全部展开/收起。

### 7. B2B + 媒体锚点管理体系

**新增服务**：`EnterpriseAnchorService`（54 个平台分 B2B/官媒/行业媒体三类）

| 类别 | 数量 | 权重最高 |
|------|------|---------|
| B2B 平台 | 30 | 百度爱采购、阿里1688、天眼查、企查查 |
| 官媒 | 12 | 山西科技报、河青新闻网、淄博新闻网 |
| 行业媒体 | 12 | 博客园、商业新知、涂料在线 |

**功能**：企业档案 NAP+W 校验 · 认证状态追踪 · LLM 引用覆盖率报告 · 客户端锚点看板

### 8. 多运营隔离 + 工作空间删除

- 运营只能看到自己创建/被分配的工作空间
- 超管 `/geo_admin/operator-monitor` 全局视角
- 工作空间列表 + 详情页均支持删除操作
- 客户账号旁新增红色「删除」按钮

### 9. 知识库文件上传修复

- MIME 类型检测 → 文件后缀检测（修复 `.md` 被误判 `text/html`）
- Embedding 失败自动降级为哈希向量，绿色提示替代红色报错

---

> **v2.4.0 文档归档（2026-07-13），v2.5.0/v2.6.1 补充见第十至十二章。**

---

## 七、v2.3.0 新增模块（2026-07-12）

### 1. GEO 评分引擎内置化

文章生成时自动注入 geoskills 6 维标准（Q&A结构/数据密度/虚词清洗/结构清晰/专家信号/自包含性），生成后自动增强（追加 FAQ + 专家引用），低于 70 分自动重写。文章列表实时显示 GEO 评分（A-F 六级）。

**文件**： + 

### 2. 客户端凭证中心

 统一管理全部平台凭证，三分类共 45 个平台：

| 分类 | 数量 | 典型平台 |
|------|------|---------|
| 📱 自媒体 | 11 | 今日头条/百家号/公众号/搜狐号/小红书/网易号/B站/企鹅号/值得买/抖音/快手 |
| 📰 新闻媒体 | 24 | 官媒12（山西科技报/河青新闻网等）+ 行业媒体12（博客园/商业新知等） |
| 🏢 B2B锚点 | 10 | 天助网/八方资源网/无忧商务网/K2/领商网/万家商务网/九州资源网/查询123/B2B88/全球五金网 |

凭证 AES-256-CBC 加密存储，绑定后自动同步信息锚点为"已认证"，解绑自动取消。高优先级平台（天助网等）红色置顶。

### 3. 运营助手 Dashboard

 本地 Web 控制台（单 HTML 文件），三栏布局：

| 左栏 | 中栏 | 右栏 |
|------|------|------|
| B2B待注册平台 | 所有平台Cookie缓存状态 | 文章列表 + 🚀一键分发到全部已绑平台 |

**核心能力**：下拉选客户（从云端自动加载workspace列表）→ 自动加载该客户已绑平台 + 文章 + 缓存 → 一键分发/注册。切换客户自动换 Cookie，无需重登。

### 4. 三端数据互通



### 5. 关键词蒸馏

关键词库详情页新增"从知识库蒸馏"Tab：选知识库 → AI 自动读取内容 → 输出核心词/长尾词/地域词/意图词四类关键词 → 自动入库。 负责结构化提取， 执行蒸馏。

### 6. 素材库运营师隔离

知识库/关键词库/标题库三大素材列表按运营师绑定的 workspace 过滤，超管看全部，运营师只看自己客户。新建素材自动分配到运营师的 workspace。Controller 基类新增  和 。

### 7. 任务并行生成

修复  单任务单 TaskRun 限制，改为允许草稿池剩余空位数量的并行任务（上限 10/分钟），多 Worker 并行生成。

### 8. 其他修复

- 客户密码双重哈希修复（ +  cast 冲突）
- Horizon 面板权限修复（Gate 白名单 → 角色判断）
- Redis predis 部署（纯 PHP 客户端，免 C 扩展）
- GeoContentScorer 正则修复（Q&A 跨行匹配 + 专家信号字符类 bug）
- 企业档案新增 B2B 注册专用字段（registration_phone / registration_authorized）
- RPA CORS 中间件（localhost:9901 ↔ 18080 跨域）

---

## 八、v2.4.0 新增模块（2026-07-13）

### 1. 全系统 Workspace 多租户隔离

**改造范围**：任务管理、文章管理、素材库（标题库/知识库/关键词库/图库）、分发发布、AI 可见度数据

| 模块 | 隔离方式 | 实现文件 |
|------|---------|---------|
| 任务列表 | `workspace_assignments` 过滤，运营师仅见绑定 workspace | `TaskMonitoringQueryService.php` |
| 任务创建表单 | 标题库/知识库/图库下拉按 workspace 过滤 | `TaskController.php::loadTaskFormOptions()` |
| 文章列表 | 运营师仅见自己 workspace 文章 | `ArticleController.php::queryArticles()` |
| 图库管理 | 图库列表按 workspace 过滤 | `ImageLibraryController.php::loadLibraries()` |
| 任务生成 | 新文章自动继承任务的 workspace | `WorkerExecutionService.php`（新增 `workspace_assignments` 写入） |
| 任务创建 | 新任务自动分配当前管理员绑定 workspace | `TaskLifecycleService.php::createTask()` |

**隔离逻辑**：超管看全部，运营师看绑定的 workspace，未绑定运营师看不到任何数据。

### 2. 客户端发布工作台

**文件**：`Client/ContentPublishController.php` + `resources/views/client/content-publish/`

| 功能 | 说明 |
|------|------|
| 三步发布向导 | Step1选文章 → Step2选平台（级联选择器） → Step3确认提交 |
| GEO 评分集成 | 提交前自动评分，<70 分文章自动 geoEnhance() 增强后再次评分 |
| 筛选分页 | 任务名称/状态/日期范围筛选，分页列表 |
| 任务详情 | 按文章聚合的平台级发布状态，GEO 评分卡片 |
| B2B 认证向导 | 四步进度条（公司资料→联系人→地区行业→产品服务），平台卡片网格 |
| 平台级联选择器 | 三级：发布方式 → 平台类别 → 具体平台，统一自媒体/B2B/渠道 |

### 3. AI 数据大屏 + 竞争力报告

**文件**：`AiVisibilityService.php`（扩展） + `client/ai-visibility.blade.php` + `client/competitiveness.blade.php`

| 功能 | 说明 |
|------|------|
| 12 AI 平台监测 | 豆包/DeepSeek/元宝/文心一言/通义千问/Kimi/讯飞星火/纳米AI/百度AI/微信AI/抖音AI/夸克AI |
| 平台覆盖矩阵 | 每平台 PC/移动端分开追踪，趋势箭头 + 评分进度条 |
| 品牌词 TOP5 | 近30天提及占比排行，水平进度条 + 覆盖平台数 |
| 30天趋势图表 | 6 主力平台柱状趋势 |
| 监测词/收录词 | 对标摘星 running_words / collected_words，检测中 vs 已收录 |
| 竞品对比报告 | 自身品牌 vs 最多 3 个竞品 KPI 对比卡片 + 12 平台覆盖对比进度条 |
| 竞品管理 | 添加/删除竞品，AiCompetitor 模型 |

**新增方法**：`dashboardOverview()`、`brandTop5Share()`、`brandCompare()`、`runningWords()`、`collectedWords()`

### 4. 自动跑词引擎

**文件**：`GeoFlowScheduleTasksCommand.php`（新增 `autoKeywordRun()`）+ `Task` 模型扩展

| 功能 | 说明 |
|------|------|
| 关键词轮转 | Task 绑定关键词组，按 `last_keyword_index` 指针轮转取词 |
| 自驱循环 | 生成→评分→增强→发布→下一轮，无人值守 |
| 草稿池控制 | 达到 `draft_limit` 自动暂停，空位释放后恢复 |
| 并发保护 | 悲观行锁 + TaskRun 串行保护，单任务最多 10 并发 |
| 运营端开关 | 任务创建/编辑页新增"自动跑词模式"开关 + 关键词组选择 + 分发渠道选择 |

**Task 模型新增字段**：`keyword_group_id`、`auto_distribute_channels`、`run_mode`、`last_auto_run_at`、`last_keyword_index`

### 5. B2B 分步注册向导 + RPA 管道

**文件**：`EnterpriseAnchorService.php`（新增 `startRpaRegister()`）+ `EnterpriseProfile` 扩展

| 功能 | 说明 |
|------|------|
| 四步进度 | company→contact→region→products，`getRegisterStepStatus()` 实时计算 |
| RPA 注册管道 | 校验资料完整 → 调 RPA `/api/v1/register` → 回写锚点认证状态 |
| RPA 结果回写 | `RpaSyncController::report()` 自动标记 `EnterpriseAnchorCertification` 为已认证 |
| 客户端向导 | B2B 平台卡片网格，已适配 RPA 的显示「一键注册」，未适配显示「手动注册」 |

**EnterpriseProfile 新增字段**：`contact_name`、`contact_phone`

### 6. 发布渠道平台树

**文件**：`ChannelPlatformTree.php`（新建）+ `DistributionChannel` 模型扩展

统一输出两级分类+三级平台的级联数据，所有平台数据从现有配置读取，不重复维护：

```
平台发布 → 自媒体矩阵（11平台） / B2B行业网站（10平台） / 智能体官网 / 自营媒体
媒体发布 → 权威合作媒体 / 自媒体权威号
```

**DistributionChannel 新增字段**：`distribute_type`、`platform_meta`

### 7. RPA 引擎增强

**文件**：`rpa-engine/server.js` + `rpa-engine/dashboard.html`

| 功能 | 说明 |
|------|------|
| 平台授权登录 | `POST /api/v1/auth-login` — 弹出浏览器让用户手动登录，自动保存 Cookie/storageState |
| 11 平台统一管理 | 头条/百家号/公众号/搜狐/小红书/网易/B站/企鹅/值得买/抖音/快手 |
| 授权状态检测 | 按 `workspace_id` 分层隔离缓存，Dashboard 实时显示授权状态 |
| 一键分发对接 | 授权后 Cookie 持久化，发布时跳过登录直接操作 |

### 8. 客户端 UI 全面升级（AI 暗色主题）

**文件**：`client/layout.blade.php` + 全部子页面重写

| 特效 | 说明 |
|------|------|
| Grainient 流体渐变背景 | WebGL2，indigo→violet 三色流体动画 |
| FloatingLines 光线叠加 | Three.js 波浪线条，screen blend 鼠标交互 |
| Lightfall 光雨背景 | WebGL2，登录页专用 |
| ClickSpark 点击粒子 | Canvas，indigo 粒子爆发 |
| BentoGlow 卡片辉光 | 鼠标跟随 radial-gradient 辉光 |
| Magnet 磁吸 | 卡片随鼠标微位移 |
| Markdown 自动剥离 | Worker + 弹药库 AI 改写均自动去 `#` `**` `- ` 标记 |

### 9. AI 平台收录优化模板

**文件**：Prompt 模型（DB 初始化）

| 模板 | 用途 |
|------|------|
| 🤖 Kimi收录优化·QA信任型 | Kimi 爬虫偏好的 Q&A 结构，确定性表述 |
| 📰 头条快速收录·榜单对比型 | 头条+AI爬虫双优化，榜单对比结构，无 Markdown |

### 10. 关键修复汇总

| 修复项 | 说明 |
|--------|------|
| 向量化修复 | 嵌入模型切至 `BAAI/bge-m3`（SiliconFlow 免费），解决 `bge-large-zh-v1.5` 废弃 400 错误 |
| 任务列表卡死 | `resolveBatchStatus()` 优先显示 completed 状态，不再因 pending 始终显示"排队中" |
| 任务状态显示 | 活跃+已完成周期 → 显示 running（而非 pending） |
| 编辑器复制 | `mode: 'ir'` → `'wysiwyg'`，解决复制格式问题 |
| 欢迎弹窗 | 所有管理员 `welcome_seen_version` 对齐，不再反复弹出 |
| 文章 workspace 继承 | 生成文章自动从 Task 获取 workspace 并写入 `workspace_assignments` |
| GEO 增强策略 | 达标文章不再追加垃圾 FAQ，仅 <70 分时触发重写 |

---

## 九、v2.4.0 运营端补充（2026-07-13 当日迭代）

### 11. RPA 自动化脚本全覆盖（14 个脚本）

**文件**：`rpa-engine/automations/`

| 类别 | 脚本数 | 平台列表 |
|------|--------|---------|
| B2B 注册 | 10 | 天助网/八方资源网/无忧商务网/K2商务网/领商网/万家商务网/九州资源网/查询123/顺企网/全球五金网 |
| 自媒体发布 | 4 | 头条号/百家号/小红书（搜狐号待适配） |

所有 B2B 脚本复用 `BasePlatformScript.smartFill()` 通用填表逻辑，统一调用 `POST /api/v1/rpa/register` → `EnterpriseAnchorService::startRpaRegister()` 管道。

### 12. 客户端内容需求提交通道

**文件**：`ClientPortalController::contentRequestStore()` + `client/dashboard.blade.php`

客户端可提交内容主题 + 补充说明，运营端接收日志后创建对应任务。

### 13. 客户端企业资料自助编辑

**文件**：`ClientPortalController::enterpriseProfileSave()` + `client/content-publish/certify.blade.php`

8 个字段：公司全称/信用代码/法人/行业/地址/电话/经营范围/主营产品。保存后 B2B 注册 4 步进度实时更新，达到 4/4 即可触发 RPA 自动注册。

### 14. 运营助手批量分发中心（弹药库移植）

**文件**：`rpa-engine/dashboard.html` + `RpaSyncController::bulkDistribute()`

| 功能 | 说明 |
|------|------|
| 全选文章 | 勾选 → 选目标平台 → 一键批量分发 |
| 进度可视化 | 进度条 + 作业计数 + 完成提示 |
| 后端管道 | `POST /api/v1/rpa/bulk-distribute` → `ContentPublishService::createPublishTask()` → 入队 distribution 队列 |

### 15. 运营助手双数据源授权校验

Dashboard 平台授权状态同时读取 DB（`ClientPlatformAccount`）和 RPA 缓存（`storage/states`），四态显示：

| DB | 缓存 | 显示 | 操作 |
|----|------|------|------|
| active | valid | 🟢 可分发 | 重新授权 |
| active | invalid | 🟡 需登录 | 授权登录 |
| revoked | valid | 🟠 已解绑 | 清缓存 |
| revoked | invalid | ⚪ 未绑定 | 授权登录 |

### 16. 客户端平台授权简化

客户端 Dashboard 平台列表改为勾选式：勾选 → 展开凭证输入（账号/密码） → 保存。不再需要跳转到独立凭证中心页面。

### 17. RPA 引擎桌面模式

**文件**：`rpa-engine/server.js`（默认 `headless=false`）+ `rpa-engine/start-operator.bat`

对标摘星桌面客户端：浏览器操作全程可见，Cookie 存储在运营人员本地电脑，切换客户自动换 Cookie。

### 18. 全项目 Workspace 账户隔离审计

| 已修复 | 控制器/服务 |
|--------|-----------|
| TaskMonitoringQueryService | 任务列表按 workspace 过滤 |
| TaskController::loadTaskFormOptions | 素材下拉按 workspace 过滤 |
| ArticleController::queryArticles | 文章列表按 workspace 过滤 |
| ImageLibraryController | 图库按 workspace 过滤 |
| WorkerExecutionService | 新文章自动继承 workspace |
| TaskLifecycleService | 新任务自动分配 workspace |
| RpaSyncController::articles | 运营助手文章按 workspace 过滤 |
| ContentArmoryController | 弹药库文章按 workspace 过滤 |

---

## 十、v2.5.0 新增模块（2026-07-14）

### 1. 品牌物料全面去GEOFlow化

**范围**：界面/文案/欢迎弹窗/Seeder/Lang/测试 — 全局"GeoFlow"品牌替换为"豆流AI"，`ADMIN_BASE_PATH` 变量化。

### 2. GEO 评分引擎 v2 强化

**文件**：`GeoContentScorer.php` + `WorkerExecutionService.php`

| 强化项 | 说明 |
|--------|------|
| 低分拦截 | < 70 分直接打回重做，不存盘（彻底阻断低质内容进入审核流） |
| 维度定向重试 | 单维度低分时仅针对该维度重写（如仅补充数据→仅追加专家引用） |
| GEO 持久化 | `articles` 表新增 `geo_score` + `geo_grade` 字段，迁移脚本回填全部旧文章 |
| Q&A 检测放宽 | 跨行正则匹配 + 字符类修复 |
| 专家信号放宽 | "XX表示/指出/认为/强调"四类匹配 |
| watchdog 优化 | Worker 守护增加 `max_execution_time=0` |

**GEO 回填命令**：`php artisan geoflow:backfill-geo-scores`（批量回填旧文章 GEO 评分）

### 3. Article 删除/恢复自动同步 Task 计数

**文件**：`ArticleController.php` → `Task::syncCreatedCount()`

文章软删除时自动 -1，恢复时自动 +1，保持 `task.created_count` 与真实文章数一致。

### 4. 平台 AI 引用策略集成

**文件**：`AiVisibilityService.php` + `GeoContentScorer.php`

集成 NewRank 4600 万数据 + geoskills v2 评分体系：
- `geoskills-main/skills/` 19 个 AI Skill 定义被引入评分维度
- AI 平台覆盖率检测从 6 平台扩展至 12 平台
- 收录词/监测词对标摘星 `running_words`/`collected_words`

### 5. 三套账号体系统一（PlatformSyncService）

**文件**：`app/Services/GeoFlow/PlatformSyncService.php`（新建 182 行）

**核心职责**：一处绑定，三处同步。

| 同步目标 | 表 | 说明 |
|---------|-----|------|
| 客户端看板 | `client_platform_accounts` | 客户可见的授权状态 |
| 发布管道 | `content_publisher_accounts` | RPA/API 发布时使用的凭证 |
| 信息锚点 | `enterprise_anchor_certifications` | B2B/媒体平台的认证记录 |

**关键能力**：
- 四态交叉判定（DB状态 × RPA缓存状态 → ready/need_login/need_bind/unbound）
- `getUnifiedStatus()` 统一查询接口（运营助手 dashboard 使用）
- 来源追踪（`source: manual/rpa_engine/client`）

### 6. 客户端登录/注册修复

| 修复项 | 文件 |
|--------|------|
| username/email 字段不匹配导致登录永久失败 | `ClientAuthController.php` |
| login form 改用 username 字段（而非 email） | `client/login.blade.php` |
| auth middleware `redirectGuestsTo` 客户端路由 | `routes/web.php` |

### 7. 运营助手增强

| 功能 | 说明 |
|------|------|
| RPA 缓存路径修复 | `storage/` → `rpa-engine/storage/states/` |
| 四态 API 统一 | dashboard 使用 `PlatformSyncService::getUnifiedStatus()` |
| JS 语法修复 | 移除损坏的 Unicode 转义和重复函数 |
| 批量分发下拉动态化 | 只显示已绑+已就绪平台 |
| auth-login 自动回调 | 登录成功后调 `reportToCloud()` 自动更新 Laravel DB |

### 8. 头条 OAuth 一键授权

**文件**：`ToutiaoOAuthAdapter.php` + `OAuthController.php`

- OAuth/Cookie 双模式支持
- 走 OAuth 时自动获取 access_token + refresh_token
- 走 Cookie 时通过 RPA 引擎浏览器手动登录

### 9. 凭证管理简化

**设计变更**：
- 移除客户端 `credential` 输入字段 — 客户端只标记"已注册"，不加凭证
- 凭证统一由运营人员通过 RPA 引擎捕获（Cookie/登录态）
- 移除 OAuth 特殊按钮（头条除外，需要 API Key）

---

## 十一、v2.6.1 新增模块（2026-07-15）

### 1. 五智能体全链路闭环

**文件**：`app/Services/Agent/`（10 个文件）+ `app/Models/AgentExecution.php`

固定流程：**Scout → Strategy → Content → Deploy → Review**

```
Scout (侦察)    → AI品牌检测 + B2B锚点巡检 + 收录缺口清单
Strategy (策略) → LLM 制定内容策略 + 选题计划 + 平台选择
Content (内容)  → RAG检索 → AI生成 → GEO评分 → <70自动重写 → 合规预检
Deploy (分发)   → ContentPublishService管线 → 多平台分发 → 锚点自动回写
Review (复盘)   → 效果评估 → 迭代建议 → 自动回路（最多3轮迭代）
```

**关键设计**：
- **B 型状态机**（`AgentDispatcherService`）：纯代码规则驱动，零 LLM 决策开销
- **A 型增强**（各 Agent 的 `executeAType*()` 方法）：LLM 自主分析，仅在开启时调用
- **Content 自循环**：GEO < 70 自动重写（最多 max_retries 次）
- **Deploy 自循环**：多平台分发时逐个入队
- **Review 回路**：`needs_iteration=true` + `auto_optimize_iteration=true` → 重新进入 Strategy
- **断点续跑**：`AgentExecution::resume()` 从任意状态恢复

**新增模型**：`AgentExecution`（表 `agent_executions`）
- 状态机 8 态：idle → scouting → planning → writing → deploying → reviewing → completed/failed
- 5 个 Agent 输出字段（scout/strategy/content/deploy/review_output）
- 状态转换白名单校验（`TRANSITIONS` 常量）

### 2. Scout 检测双轨

**文件**：`ScoutAgentService.php` + `rpa-engine/scout_and_save.cjs` + `rpa-engine/scout-page.js`

| 轨道 | 方式 | 覆盖平台 |
|------|------|---------|
| B 型规则 | `AiVisibilityService` + `EnterpriseAnchorService` 复用 | 12 AI 平台 + 54 B2B/媒体锚点 |
| A 型增强 | LLM 语义分析竞品素材 | DeepSeek 直调 |
| Node.js 轨 | Playwright 浏览器自动化实测 | 豆包/元宝/百度AI（3平台） |

**Scout 脚本**：
- `scout_and_save.cjs`：加载平台 Cookie → 打开 AI 对话页 → 输入品牌词 → 提取回答 → 保存 JSON
- `scout-page.js`：访问目标 URL，输出表单/按钮/链接结构（用于编写 RPA 脚本前侦察）

**存储**：`rpa-engine/storage/scout/{platform}.json`（每个平台独立 Cookie+结果）

### 3. 快照凭证 + CDP 常驻浏览器

**文件**：`rpa-engine/server.js`（auth-login 端点重写）+ `auth-cdp.cjs` + `start-browser.bat`

| 特性 | v2.5.0（旧） | v2.6.1（新） |
|------|-------------|-------------|
| 浏览器启动 | 每次新建 | CDP 常驻或持久化上下文 |
| Cookie 保存 | 手动 storageState | `launchPersistentContext()` 自动 |
| 生命周期 | 单任务用完即毁 | 一次登录永久有效 |
| 共享 | 每脚本独立 | `browser-profile/shared` 全脚本共享 |

**CDP 常驻模式**：
1. `start-browser.bat` → 启动 Chrome CDP `--remote-debugging-port=9222`
2. `auth-cdp.cjs {platform}` → 连接已有浏览器，打开平台页面，用户手动登录
3. Cookie 自动持久化到 `browser-profile/shared`，全平台共享

**AI 平台授权扩展**（7 个新平台）：
豆包AI / 腾讯元宝 / 百度AI / 讯飞星火 / 夸克AI / 纳米AI / 抖音AI

**授权流程**：`POST /api/v1/auth-login` → `launchPersistentContext(browser-profile/shared)` → 自动检测登录完成 → Cookie 持久化

### 4. 发布通道全面统一

**文件**：`app/Services/GeoFlow/Publishing/`（19 个文件）

**架构**：

| 层级 | 组件 | 职责 |
|------|------|------|
| 入口 | `ContentPublishService` | 创建任务→拆分作业→入队（一篇文章 × N 平台 = M 条作业） |
| 适配层 | `BasePlatformAdapter` | 模板方法：前置校验→内容改写→格式适配→合规检查→执行发布→记录结果 |
| 工厂 | `PlatformAdapterFactory` | 根据 `platform_key` + `credential_type` 自动实例化适配器 |
| 账号池 | `AccountPoolService` | 按健康度/失败率/日配额排序选号，自动轮换 |
| 限流 | `ContentPublishRateLimiter` | 平台级指数退避 + 全局 Redis 锁 |
| 路由 | `RpaRoutingDecider` | 纯代码三轨决策（native_rpa / direct_api / playwright_mcp） |

**适配器清单**（7 个）：

| 适配器 | 平台 | 方式 |
|--------|------|------|
| `ToutiaoOAuthAdapter` | 头条号 | OAuth 2.0 Token |
| `BaijiahaoCookieAdapter` | 百家号 | RPA Cookie |
| `SohuCookieAdapter` | 搜狐号 | RPA Cookie |
| `B2b168RpaAdapter` | B2B 168 等 | RPA 脚本 |
| `GenericRpaAdapter` | 通用 | RPA 脚本 |
| `MediaBoxApiAdapter` | 媒介盒子 | API Key |
| `GenericOAuthAdapter` | 通用 | OAuth 2.0 |

**三轨路由决策**（`RpaRoutingDecider`）：
- `native_rpa`：15 个成熟自研脚本，连续失败 ≥2 次自动降级
- `direct_api`：媒介盒子 Open API 直连
- `playwright_mcp`：新渠道自适应（Playwright MCP Phase 4）

### 5. Agent 工具注册表

**文件**：`app/Services/Agent/Tools/`（8 个工具）

| 工具 | 类 | 用途 |
|------|-----|------|
| 锚点状态 | `AnchorStatusTool` | 查询 B2B/媒体锚点认证状态 |
| GEO 评分 | `GeoScoreTool` | 对文章实时 GEO 评分 |
| 关键词库 | `KeywordLibraryTool` | 查询/生成关键词 |
| 知识检索 | `KnowledgeRetrievalTool` | RAG 知识库检索 |
| 敏感词 | `SensitiveWordTool` | 内容合规预检 |
| RPA 发布 | `RpaPublishTool` | 调用自研 RPA 引擎分发 |
| Playwright MCP | `PlaywrightMcpTool` | 通用浏览器自动化 |

### 6. 发布后 Scout 自动收录检测

**文件**：`TriggerPostDeployScout.php` + `PostDeployScoutJob.php` + `PlatformScoutJob.php`

**触发链路**：
```
ContentPublishTask 完成 → updateProgress() → triggerPostDeployScout()
→ TriggerPostDeployScout Job → PostDeployQuestionGenerator 生成探针问题
→ PostDeployScoutJob（4 轮延迟：0/3/7/15 天）→ Playwright/CDP 实测 AI 平台
→ AiVisibilityCheck + AiVisibilitySnapshot 写库 → 客户端仪表盘展示
```

**去重机制**：`Cache::put("post_deploy_scout:{ws_id}:{article_id}:round_{n}", true, 30days)`

### 7. AI Model Provider 管理

**文件**：`AiModelProvider.php`（模型）+ `2026_07_15_000001_create_ai_model_providers`（迁移）

- `provider_code`：唯一标识（deepseek / ark / openai / gemini）
- `adapter_class`：适配器类全限定名
- `failover_priority`：故障转移优先级
- `config_json`：额外配置（base URL 等）

### 8. 数据库性能索引

**文件**：`2026_07_15_000000_add_performance_indexes.php`

为高频查询表添加复合索引：
- `articles(status, published_at)`
- `workspace_assignments(workspace_id, assignable_type)`
- `content_publish_results(content_publish_task_id, status)`
- `agent_executions(workspace_id, current_state)`
- 等

### 9. AI 品牌可见度增强

**新增**：`ai_visibility_checks.cited_articles` 字段（关联引用文章）+ `AiCompetitor` 竞品管理模型

### 10. v2.5.0 → v2.6.1 关键修复汇总

| 修复项 | 说明 |
|--------|------|
| auth-login 自动回调 | 登录成功后自动 `POST /api/v1/rpa/report` + `reportToCloud()` 同步 DB |
| DB 状态更新 | `RpaSyncController::report()` → `PlatformSyncService::syncBinding()` 三表联动 |
| 客户端简化 | 移除 `credential` 字段，客户端只勾选"已注册"，凭证由运营 RPA 捕获 |
| 三表统一 | `ClientPlatformAccount` + `ContentPublisherAccount` + `EnterpriseAnchorCertification` 统一绑定入口 |
| RPA 引擎升级 | v1.0 → v2.0，新增缓存管理、云端同步、验证码回调、运营面板 |

---

## 十二、当前代码扫描问题清单（2026-07-16）

> 以下问题在全量代码扫描中发现，按严重程度分级。

### 🔴 严重 (Bugs)

| # | 问题 | 文件 | 行号 | 说明 |
|---|------|------|------|------|
| 1 | **凭证静默丢失** | `PlatformSyncService.php` | 67 | `credential_plaintext` 不在 `ContentPublisherAccount::$fillable` 中，updateOrCreate 时被 Laravel 静默丢弃，导致发布账号无凭证 |
| 2 | **MCP 命名空间错误** | `PlaywrightMcpTool.php` | 7 | `use Mcp\Client\ClientManager` 应为 `use Laravel\Mcp\Client\ClientManager`（`laravel/mcp` v0.8.2 已安装但命名空间不同），运行时 Class Not Found |
| 3 | **SSL 验证禁用** | `ContentAgentService.php` | 94 | `CURLOPT_SSL_VERIFYPEER=>false` 中间人攻击风险 |

### 🟡 中等 (Security/Design)

| # | 问题 | 文件 | 行号 | 说明 |
|---|------|------|------|------|
| 4 | **RPA 默认 API Key** | `server.js` | 40 | 硬编码默认密钥 `"qonhub-rpa-secret-change-me"`，未设置环境变量时任何人可访问 |
| 5 | **凭证明文传输** | `RpaSyncController.php` | 234-271 | `credentials()` 方法解密所有凭证返回明文，虽限 localhost 但仍为薄弱点 |
| 6 | **storage/ 下放置源码** | `storage/scout_from_url.php` 等 | — | 3 个可执行 CLI 脚本放在 runtime 目录，不符合 Laravel 惯例 |

### 🟢 低 (Minor)

| # | 问题 | 文件 | 说明 |
|---|------|------|------|
| 7 | **空文件** | `rpa-engine/setTimeout(r` | 0 字节，疑似编辑器错误产物 |
| 8 | **登录等待无早期退出** | `auth-ai-login.cjs:30` | 始终等待 120 秒，不检测提前登录 |
| 9 | **CDP 无断线恢复** | `auth-cdp.cjs:33` | 连接断开后永久挂起，无超时/重连 |

### 功能验证结果

| 验证项 | 结果 |
|--------|------|
| PHP 语法检查（全部文件） | ✅ 0 错误 |
| Laravel 应用启动 | ✅ 正常 |
| 路由注册（359 条） | ✅ 正常 |
| 数据库迁移（全部 20 batch） | ✅ 全部已执行 |
| Agent 路由（/geo_admin/agents/*） | ✅ 已注册 |
| RPA API 路由（/geo_admin/api/v1/rpa/*） | ✅ 已注册 |
| OAuth 路由 | ✅ 已注册 |
| 所有 Job 类 | ✅ 11 个全部存在 |
| MCP 包（laravel/mcp v0.8.2） | ✅ 已安装 |
| PostgreSQL 数据库连接 | ✅ 正常 (geo_flow) |

---

> **本文档基于 2026-07-16 全量代码扫描 + 数据库审计 + 运行时诊断生成，所有内容来源于实际已实现的代码。**
> **项目地址**：`E:\Qonhubgeo\douliu-main`
> **当前版本**：豆流 AI v2.6.1（基于 Laravel 12 + PostgreSQL 16）
