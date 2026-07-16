# 豆流 AI (Qonhub) — 全栈工程师面试题库

> 基于 v2.7.0 实际架构设计 · 62K 行 PHP · 74 张表 · 五智能体管道 · Hybrid RAG

---

## 一、系统架构设计 (考察全局视野)

### Q1: 这套系统有 74 张表、121 个 Service、57 个 Controller。如果你是架构师，如何保证一个运营人员只能看到自己被分配的工作空间数据？

**考察点**: 多租户隔离、中间件设计、SQL 注入防护

**期望答案要点**:
- 三层防御: Controller 基类的 `scopeByOperatorWorkspaces()` 做列表过滤 + `authorizeOperatorAccess()` 做单条校验 + `authorizeWorkspaceAccess()` 做工作空间操作校验
- 超管返回 null (不过滤)，运营返回 `operator_workspaces` 绑定的 workspace_id 数组
- 所有查询通过 `workspace_assignments` 多态表做 IN 子查询
- RPA API 额外加 localhost 白名单 + operator 身份绑定

### Q2: 五智能体管道 (Scout→Strategy→Content→Deploy→Review) 是同步串行的。如果 Content 阶段调 DeepSeek 超时 60 秒，整个管道会怎样？

**考察点**: 超时处理、状态机设计、容错机制

**期望答案要点**:
- `set_time_limit(600)` 硬限制防止 PHP-FPM 被耗尽
- Content 失败 `generationError` 不为 null → 跳过质量重试，不浪费配额
- Deploy 阶段轮询 ContentPublishTask 最多 120 秒，超时标记 `timed_out=true`
- Review 读到 `timed_out` 会生成"检查 Worker"建议
- 异常在 Dispatcher 的 `handleAgentFailure()` 统一处理，重试最多 `max_retries` 次

### Q3: 这套系统同时存在旧 `distribution` 队列和新 `content-publish` 队列。为什么做这个分离？如果合并会有什么问题？

**考察点**: 队列隔离、向后兼容、重构策略

**期望答案要点**:
- 旧系统 `ProcessArticleDistributionJob` 用 `distribution` 队列，新系统 `ProcessContentPublishJob` 用 `content-publish`
- 分离原因: 两种 Job 的 payload 格式不同 (`distribution_id` vs `result_id`)，混在一起会导致 Worker 消费到不认识的 payload 崩溃
- Horizon 配置里两个队列独立 `waits` 阈值 (distribution=60s, content-publish=300s)
- 渐进式迁移策略: 旧系统继续服务存量任务，新系统服务增量，直到旧系统自然淘汰

---

## 二、AI 与 LLM 调度 (考察 AI 工程化能力)

### Q4: 你发现 DeepSeek API 一直返回 401，但 Key 在后台验证是正常的。排查链路是什么？

**考察点**: 调试能力、三层叠加 Bug 的排查思路

**实际 Bug**: API Key 没解密 → Token 在 `sendRequest()` 时被丢弃 → `ApiKeyCrypto` 没被 Laravel 容器注入

**期望答案要点**:
1. 先用 `curl` / `Http::withToken()` 直连验证 Key 本身有效
2. 对比直连和 Service 调用的差异 → 发现 `sendRequest()` 新建了不带 Token 的客户端
3. 再追查 Key 为什么是密文 → 发现 `ApiKeyCrypto` 在构造函数里是 `null`
4. 根因: Laravel 容器不注入带默认值 `= null` 的 nullable 参数
5. 修复: 用 `app(ApiKeyCrypto::class)` 直解析绕过 DI

### Q5: 现在你接了 DeepSeek + 豆包 + 通义千问 + Kimi + 文心一言五家 API。它们的接口细节各不相同 (有的用 Bearer Token、有的用 OAuth 2.0、有的 API 路径不一样)。你怎么设计适配器层？

**考察点**: 设计模式、策略模式/适配器模式、接口抽象

**期望答案要点**:
- `BaseLlmAdapter` 抽象类定义统一接口: `chat(modelId, messages, options)` → `array`
- `OpenAiCompatibleAdapter` 覆盖 DeepSeek/豆包/千问/Kimi/智谱/硅基 (6家)
- `ErnieQianfanAdapter` 特殊处理文心一言的 OAuth 2.0
- `LlmAdapterFactory::createByCode()` 根据 `provider_code` + `adapter_class` 动态实例化
- `LlmOrchestratorService::smartFailover()` 在主模型失败时按优先级自动降级
- 厂商差异通过 `ai_model_providers.config_json` 的 `extra_headers` 处理，不改代码

### Q6: 系统里的 Hybrid RAG 是 0.6 向量 + 0.4 BM25 加权。为什么不用纯向量检索？BM25 在 B2B 场景下解决了什么问题？

**考察点**: 信息检索理论、向量 vs 全文检索的互补性

**期望答案要点**:
- 向量检索对语义相近但文字不同的内容有效 (如 "流体控制设备" ≈ "泵阀")
- 但 B2B 有大量精确查询: 产品型号 "ZLH-50-32-160"、标准号 "GB/T 5657"、技术参数
- 纯向量对精确字符串匹配很差——型号在向量空间里可能是孤立点
- BM25 擅长精确匹配，`tsvector + ts_rank` 对型号/编号/术语 100% 命中
- 加权融合 (0.6/0.4) 兼顾语义理解和精确匹配

---

## 三、数据库与性能 (考察后端硬实力)

### Q7: 系统里有一个 `workspace_assignments` 多态表，所有需要隔离的资源 (Article/Task/KnowledgeBase/ImageLibrary) 都通过它关联。这种设计有什么优缺点？

**考察点**: 多态关联、查询性能、索引设计

**期望答案要点**:
- 优点: 统一隔离机制，`scopeByOperatorWorkspaces()` 一行代码通吃所有资源类型
- 缺点: 无法使用数据库外键，多态表数据量大时 `WHERE assignable_type = ? AND workspace_id = ?` 需要复合索引
- 优化: `(workspace_id, assignable_type, assignable_id)` 复合索引
- 替代方案讨论: 每张表冗余 workspace_id (更简单但不统一)、Policy-based 授权 (更灵活但更复杂)

### Q8: PostgreSQL 的 `pgvector` 扩展和 `tsvector` 全文检索在同一个查询里会冲突吗？查询计划大概是什么样的？

**考察点**: PostgreSQL 内部机制、索引使用、查询优化

**期望答案要点**:
- 不会冲突，各走各的索引: 向量 `<=>` 走 HNSW 索引，`ts_rank` 走 GIN 索引
- 融合发生在应用层 (PHP)，不是 SQL 层
- 两次独立 `SELECT`，结果在 PHP 里按 chunk_index 合并分数
- 如果未来数据量大，可以 `UNION ALL` 然后 `ORDER BY score DESC LIMIT N` 在 SQL 层融合

### Q9: Horizon 配置里 timeout 链是 "Job 600s < Supervisor 650s < retry_after 900s"。为什么必须是这个顺序？反过来会怎样？

**考察点**: 队列底层机制、任务幂等性、超时理解

**期望答案要点**:
- `retry_after` 是 Redis 保留 Job 的时间——过了这个时间，Redis 认为 Worker 挂了，重新把 Job 放回队列
- 如果 `retry_after < Job timeout`，Worker 还在执行但 Job 已经被重新投递 → 同一个 Job 被两个 Worker 同时执行 → 重复消费
- 所以必须: Worker 处理完 (600s) < Supervisor 放弃等待 (650s) < Redis 重新投递 (900s)
- 这是一种兜底机制——正常情况 Supervisor 会正确清理 Job

---

## 四、前端与全栈 (考察全栈能力)

### Q10: 客户端面板有 12 个 AI 平台的配色，每个平台颜色不同。你怎么在 Blade 模板里管理这个，避免满屏的内联 `style="color: #xxxxxx"`？

**考察点**: CSS 架构、动态主题、Tailwind v4

**期望答案要点**:
- Tailwind v4 的 CSS-first 配置: `@theme` 定义设计 token
- 在 `layout.blade.php` 定义 `--ai-accent`、`--ai-border` 等 CSS 自定义属性
- 平台颜色来自 `AiVisibilityService::AI_PLATFORMS` 常量——动态颜色通过 Blade `style="color:{{ $color }}"` 是合法且必要的
- v2.7.0 提取了 12 个 CSS 变量覆盖 80% 硬编码色值
- 未来可升级为 CSS `@layer` + Tailwind v4 的 `theme()` 函数做编译时主题

### Q11: 客户端用了 WebGL2 (Three.js) 做 Grainient 流体渐变背景，BentoGlow 做卡片鼠标辉光。如果客户用低端手机打开，怎么处理性能问题？

**考察点**: 渐进增强、性能优化、响应式设计

**期望答案要点**:
- `prefers-reduced-motion` 媒体查询: 关闭 Canvas/WebGL 动画
- `matchMedia('(max-width: 768px)')` 降级为静态渐变背景
- Three.js 用 CDN 加载，`<script defer>` 不阻塞首屏
- WebGL context 用 `powerPreference: 'low-power'` 降低 GPU 功耗

### Q12: 客户端 `ai-visibility` 页面同时展示了对话快照、平台矩阵、收录来源、系统建议四块数据。这些数据来自不同的表，怎么设计 API 或者数据聚合层？

**考察点**: 数据聚合、Backend-for-Frontend (BFF)、N+1 优化

**期望答案要点**:
- `AiVisibilityService::clientSnapshots()` 做聚合: 一次返回 `snapshots + cited_sources + review_recommendations`
- 用 Laravel 的 `Cache::remember()` 缓存 5 分钟，减少重复查询
- `ClientPortalController` 作为 BFF 层，只返回 Blade 需要的数据结构
- 当前未优化: 不做 N+1 因为客户日活 < 100，过度优化是浪费时间

---

## 五、RPA 与浏览器自动化 (考察全栈跨界能力)

### Q13: 你有 15 个 B2B 平台的注册脚本，每个 ~300 行 JS。如果八方资源网 (b2b168.com) 突然改了注册页面的 CSS class，你怎么快速定位并修复？

**考察点**: 可维护性设计、抽象能力、实战经验

**期望答案要点**:
- 14/15 个脚本继承 `BasePlatformScript`，选择器逻辑在基类的 `smartFill()`、`findElement()`、`clickSubmit()` 里
- `smartFill()` 用多选择器兜底: `input[name*="phone"]` OR `input[placeholder*="手机"]` OR `input[type="tel"]`
- 修改一处基类方法，所有子类受益
- b2b168.js 是唯一一个待重构的 (413 行裸写)，优先重构它
- 每个脚本有 WAF 检测 + 自动截图留痕，方便排查

### Q14: RPA 引擎需要在用户的电脑上运行浏览器。如果要部署到 Linux 服务器 (没有 GUI)，你需要做什么？

**考察点**: 无头浏览器、Docker、生产化部署

**期望答案要点**:
- Playwright 支持 headless 模式 (`headless: true`)
- `RPA_BACKEND=browserless` 环境变量切到 Docker 化 Chrome 集群
- `chromium.connectOverCDP('ws://browserless:3000')` 替代 `chromium.launch()`
- browserless 管理 Chrome 进程池、自动回收、健康检查
- 需要 `--no-sandbox` 和 `--disable-dev-shm-usage` 在容器里运行

---

## 六、项目实战场景题 (综合能力)

### Q15: 客户问"我的文章发出去 3 天了，为什么在豆包里还是搜不到？"你怎么排查并回答？

**考察点**: GEO 领域知识、问题定位、客户沟通

**期望答案要点**:
1. 先查 `AgentConversationSnapshot` 确认最近一次 Scout 是否检测了豆包 → 看 `brand_mentioned` 字段
2. 如果未检测 → 触发一次 Agent 管道
3. 如果检测了但 `mentioned=false` → 看 AI 回答原文，判断是"没收录"还是"不知道这个品牌"
4. 如果 AI 说"不知道" → 说明 B2B 锚点没建 (查 `EnterpriseAnchorCertification`)
5. 如果 AI 提到了竞品 → 说明内容不够差异化，需要调整关键词 + 增强 GEO 评分
6. 给客户的回复: 豆包收录需要 7-14 天的信源权重积累期，同时我们建议补充 B2B 平台企业认证

### Q16: 系统当前 `deepseek-chat` 模型名将在 2026-07-24 被 DeepSeek 弃用。你怎么做全项目的迁移，确保零停机？

**考察点**: 生产变更、灰度策略、风险控制

**期望答案要点**:
1. 代码层: 全局搜索替换 `deepseek-chat` → `deepseek-v4-flash` (已完成)
2. 数据库: `ai_models` 表 `model_id` 字段更新 (已完成)
3. 灰度策略: 先在 staging 环境跑 Agent 管道验证 200
4. 关注 `reasoning_content` vs `content` 字段差异 (V4 Flash 默认思考模式)
5. 设置截止日前告警日历，确保不遗漏

---

## 七、设计决策讨论 (开放题)

### Q17: 为什么选择 Laravel 而不是 Python FastAPI 或 Go？如果让你从零重写，你会换技术栈吗？

**期望讨论方向**: PHP 生态成熟度 vs Python AI 生态、团队技能、性能需求、迭代速度

### Q18: 五智能体管道现在是串行的。如果改成并行 (Scout + Strategy 同时跑，Content + Deploy 流水线)，会遇到什么工程挑战？

**期望讨论方向**: 数据依赖 (Strategy 需要 Scout 结果)、并发控制、状态一致性、死锁

### Q19: 当前 GEO 评分是纯规则 (正则 + 词频)。如果要引入 LLM 做评分 (让 DeepSeek 打分)，你会怎么设计才不会让成本爆炸？

**期望讨论方向**: 缓存策略、hybrid 评分 (规则快速筛选 + LLM 精细打分)、小模型蒸馏
