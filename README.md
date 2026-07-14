# 豆流 AI — 企业级 GEO 智能营销系统

**豆流 AI** 是自主研发的企业级生成式引擎优化（GEO）智能营销系统，为营销服务商和终端企业提供从关键词挖掘、AI 内容生成、全渠道分发到 AI 品牌可见度监测的一站式解决方案。

---

## 核心能力

### 多工作空间分级管理
一套系统支持无限客户独立工作空间，配套超级管理员运营总看板、员工权限分级、终端客户自助门户，适配服务商批量交付场景。

### 六维透明 GEO 评分引擎
独家 Q&A 结构 / 数据密度 / 虚词清洗 / 结构清晰度 / 专家信号 / 自包含性六维量化评分体系，低于 70 分自动增强改写，让服务质量有据可依。

### 自研 RPA 通用自动化引擎
无需依赖平台官方 API 合作，已深度适配核心 B2B 平台与自媒体渠道，支持 Cookie 持久化与桌面运营模式，新渠道 1-3 天快速定制上线。

### 多维度 AI 品牌可见度评估
基于大模型 API 与 RPA 采集的品牌 AI 提及检测，覆盖主流 AI 平台，配套趋势分析与月度 GEO 报告。

### 全渠道内容分发
内置 11 套平台差异化内容模板（知乎/头条/百家号/小红书/B2B/新闻媒体/技术博客等），支持 GeoFlow Agent / WordPress REST / Generic HTTP 三种标准化分发通道。

### 五阶 GEO 全链路运营体系
拓词 → 素材提纯 → GEO 标准写作 → 全渠道分发 → 数据监测，全流程工具化提效。

---

## 技术栈

| 层级 | 技术 |
|------|------|
| 后端框架 | Laravel 12 + PHP 8.2+ |
| 数据库 | PostgreSQL 16（pgvector 向量检索） |
| 缓存/队列 | Redis 7 + Laravel Horizon |
| 自动化引擎 | 自研 Node.js RPA（Playwright + 双层指纹伪装） |
| 前端 | Tailwind CSS v4 + Chart.js + Vite |
| AI 集成 | 多模型兼容（OpenAI / DeepSeek / 火山方舟 / 千帆 / DashScope 等） |
| 部署 | Docker Compose（开发/生产/预构建三套配置） |

---

## 快速开始

### 环境要求
- PHP 8.2+
- PostgreSQL 16+（需 pgvector 扩展）
- Redis 7+
- Node.js 18+（RPA 引擎）

### 本地开发

```bash
git clone git@github.com:sun6792/Qonhub.git
cd Qonhub
composer install
cp .env.example .env
# 编辑 .env 填入数据库、Redis、AI 模型 API Key 等配置
php artisan migrate
php artisan geoflow:install
php artisan serve --host=127.0.0.1 --port=18080
php artisan queue:work redis --queue=geoflow,distribution
```

### RPA 引擎

```bash
cd rpa-engine
npm install
node server.js
```

### Docker 部署

```bash
cp .env.docker.example .env.docker
docker-compose --env-file .env.docker up -d --build
```

---

## 更新日志

详见 `docs/` 目录下的版本更新说明。

---

**当前版本：v2.5.0 — P0 安全加固与核心能力夯实**
