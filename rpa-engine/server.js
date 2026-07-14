/**
 * Qonhub AI RPA Engine — Playwright-based headless browser microservice.
 * v2.0 — 运营助手本地控制台版
 *
 * Endpoints:
 *   GET  /api/v1/health           — Health check
 *   POST /api/v1/register          — B2B enterprise registration + certification
 *   GET  /api/v1/tasks/:id         — Task status query
 *
 * [新增] 缓存管理:
 *   GET  /api/cache/list?workspace_id=  — 列出某客户所有平台缓存状态
 *   POST /api/cache/clear             — 清除指定客户/平台缓存
 *
 * [新增] 本地-云端同步:
 *   GET  /api/tasks/pull?workspace_id= — 从云端拉取待执行任务
 *   POST /api/tasks/report            — 上报任务执行结果
 *   POST /api/captcha/input           — 验证码输入回传
 *
 * [新增] 运营面板:
 *   GET  /                            — 本地 Web 控制台 (dashboard.html)
 *
 * Env vars:
 *   RPA_PORT=9901                     Server port
 *   RPA_API_KEY=...                   API auth key
 *   RPA_HEADLESS=true                 Run in headless mode
 *   GEOFLOW_API_URL=...               Laravel backend URL (for sync)
 */

import express from "express";
import { chromium } from "playwright";
import winston from "winston";
import { randomUUID } from "crypto";
import fs from "fs";
import path from "path";
import { fileURLToPath } from "url";

// ── Config ──────────────────────────────────────────────
const __dirname = path.dirname(fileURLToPath(import.meta.url));
const PORT = process.env.RPA_PORT || 9901;
const API_KEY = process.env.RPA_API_KEY || "qonhub-rpa-secret-change-me";
const HEADLESS = process.env.RPA_HEADLESS === "true"; // v2.4: 默认桌面模式(浏览器可见), RPA_HEADLESS=true 切回无头
const SCREENSHOT_DIR = process.env.RPA_SCREENSHOT_DIR || path.join(__dirname, "screenshots");
const GEOFLOW_API_URL = process.env.GEOFLOW_API_URL || "http://127.0.0.1:18080/geo_admin";
const STORAGE_DIR = path.join(__dirname, "storage", "states");

// Ensure dirs exist
[SCREENSHOT_DIR, STORAGE_DIR].forEach(d => {
    if (!fs.existsSync(d)) fs.mkdirSync(d, { recursive: true });
});

// ── Logger ──────────────────────────────────────────────
const logger = winston.createLogger({
    level: "info",
    format: winston.format.combine(
        winston.format.timestamp(),
        winston.format.printf(({ timestamp, level, message }) => `[${timestamp}] ${level.toUpperCase()}: ${message}`)
    ),
    transports: [
        new winston.transports.Console(),
        new winston.transports.File({ filename: path.join(__dirname, "logs", "rpa.log") }),
    ],
});

// ── Task Store ──────────────────────────────────────────
const tasks = new Map();
// [新增] 验证码等待队列: {taskId, resolve}
const captchaQueue = new Map();

// ── Automation Loader ───────────────────────────────────
const automations = {};
async function loadAutomations() {
    const dir = path.join(__dirname, "automations");
    if (!fs.existsSync(dir)) return;
    const files = fs.readdirSync(dir).filter(f => f.endsWith(".js"));
    for (const file of files) {
        const mod = await import(`./automations/${file}`);
        if (mod.platform && mod.execute) {
            automations[mod.platform] = mod;
            logger.info(`Loaded automation: ${mod.platform} — ${mod.description || ""}`);
        }
    }
}

// ── Express App ─────────────────────────────────────────
const app = express();
app.use(express.json({ limit: "10mb" }));

// [新增] Serve dashboard
const dashPath = path.join(__dirname, "dashboard.html");
if (!fs.existsSync(dashPath)) {
    logger.warn("dashboard.html not found — dashboard will 404");
}

// [新增] CORS for local dashboard
app.use((req, res, next) => {
    res.header("Access-Control-Allow-Origin", "*");
    res.header("Access-Control-Allow-Headers", "Content-Type, X-Api-Key");
    res.header("Access-Control-Allow-Methods", "GET,POST,OPTIONS");
    if (req.method === "OPTIONS") return res.sendStatus(200);
    next();
});

// Auth middleware
function auth(req, res, next) {
    const key = req.headers["x-api-key"] || req.query.api_key || "";
    if (key !== API_KEY) return res.status(401).json({ error: "unauthorized" });
    next();
}

// ══════════════════════════════════════════════════════════
//  原始接口（保留不变）
// ══════════════════════════════════════════════════════════

app.get("/api/v1/health", (req, res) => {
    res.json({
        status: "healthy",
        uptime: process.uptime(),
        automations: Object.keys(automations),
        active_tasks: tasks.size,
        node_version: process.version,
    });
});

app.post("/api/v1/register", auth, async (req, res) => {
    const taskId = randomUUID();
    const { platform, account, enterprise, options } = req.body;
    const workspaceId = options?.workspace_id || enterprise?.workspace_id || "default";

    if (!platform || !account || !enterprise) {
        return res.status(400).json({ error: "missing required fields: platform, account, enterprise" });
    }

    tasks.set(taskId, {
        status: "running",
        workspace_id: workspaceId,
        platform,
        started_at: new Date().toISOString(),
    });
    logger.info(`Task ${taskId}: register on ${platform} for ws=${workspaceId}`);

    res.json({ task_id: taskId, status: "accepted" });

    try {
        const automation = automations[platform];
        if (!automation) throw new Error(`No automation registered for platform: ${platform}`);

        const result = await automation.execute({
            taskId,
            account,
            enterprise,
            options: {
                headless: HEADLESS,
                screenshotDir: SCREENSHOT_DIR,
                proxy: options?.bound_ip || null,
                timeout: options?.timeout_seconds || 180,
                workspace_id: workspaceId, // [新增] 缓存隔离
                ...options,
            },
            logger,
        });

        tasks.set(taskId, {
            status: "completed",
            workspace_id: workspaceId,
            platform,
            result,
            finished_at: new Date().toISOString(),
        });
        logger.info(`Task ${taskId}: completed — shop_url=${result.shop_url || "none"}`);

        // [新增] 自动回传结果到云端
        reportToCloud(taskId, { ...result, workspace_id: workspaceId, platform });
    } catch (err) {
        const errorMsg = err.message || String(err);
        tasks.set(taskId, {
            status: "failed",
            workspace_id: workspaceId,
            platform,
            error: errorMsg,
            finished_at: new Date().toISOString(),
        });
        logger.error(`Task ${taskId}: failed — ${errorMsg}`);
        reportToCloud(taskId, { success: false, error: errorMsg, workspace_id: workspaceId, platform });
    }
});

app.post("/api/v1/publish", auth, async (req, res) => {
    const taskId = randomUUID();
    const { platform, account, content, options } = req.body;

    if (!platform || !content) {
        return res.status(400).json({ error: "missing required fields: platform, content" });
    }

    tasks.set(taskId, { status: "running", started_at: new Date().toISOString() });
    logger.info(`Task ${taskId}: publish on ${platform}`);

    res.json({ task_id: taskId, status: "accepted" });

    try {
        const automation = automations[platform];
        if (!automation) {
            throw new Error(`No automation registered for platform: ${platform}`);
        }
        // 发布脚本：优先 publish，fallback execute（baijiahao/toutiao/xiaohongshu 用 execute 处理发布）
        const handler = automation.publish || automation.execute;
        const result = await handler({
            taskId, account,
            enterprise: { workspace_id: options?.workspace_id || "default" },
            options: {
                headless: HEADLESS,
                screenshotDir: SCREENSHOT_DIR,
                workspace_id: options?.workspace_id || "default",
                timeout: options?.timeout_seconds || 300,
                article: content, // 发布脚本从 options.article 读内容
                ...options,
            },
            logger,
        });
        tasks.set(taskId, { status: "completed", result, finished_at: new Date().toISOString() });
        reportToCloud(taskId, { success: true, platform, ...result });
    } catch (err) {
        const errorMsg = err.message || String(err);
        tasks.set(taskId, { status: "failed", error: errorMsg, finished_at: new Date().toISOString() });
        reportToCloud(taskId, { success: false, error: errorMsg, platform });
    }
});

app.get("/api/v1/tasks/:id", auth, (req, res) => {
    const task = tasks.get(req.params.id);
    if (!task) return res.status(404).json({ error: "task not found" });
    res.json(task);
});

app.use("/screenshots", auth, express.static(SCREENSHOT_DIR));

// ══════════════════════════════════════════════════════════
//  [新增模块] 缓存管理 API
// ══════════════════════════════════════════════════════════

/**
 * GET /api/cache/list?workspace_id=7
 * 列出某客户所有平台缓存状态 [新增]
 */
app.get("/api/cache/list", (req, res) => {
    const wsId = req.query.workspace_id || "default";
    const wsDir = path.join(STORAGE_DIR, String(wsId));
    const caches = [];

    if (!fs.existsSync(wsDir)) {
        return res.json({ workspace_id: wsId, caches: [] });
    }

    const files = fs.readdirSync(wsDir).filter(f => f.endsWith(".json"));
    for (const file of files) {
        const platformKey = file.replace(".json", "");
        const filePath = path.join(wsDir, file);
        try {
            const stat = fs.statSync(filePath);
            const ageDays = (Date.now() - stat.mtimeMs) / (1000 * 60 * 60 * 24);
            const platformName = automations[platformKey]?.description || platformKey;
            caches.push({
                platform_key: platformKey,
                platform_name: platformName,
                created_at: stat.mtime.toISOString(),
                age_days: Math.round(ageDays * 10) / 10,
                valid: ageDays < 30,
                size_kb: Math.round(stat.size / 1024),
            });
        } catch { /* skip corrupt */ }
    }

    // 补充：所有已加载的自动化平台（即使没缓存也列出）
    for (const [key, mod] of Object.entries(automations)) {
        if (!caches.find(c => c.platform_key === key)) {
            caches.push({
                platform_key: key,
                platform_name: mod.description || key,
                created_at: null,
                age_days: null,
                valid: false,
                size_kb: 0,
            });
        }
    }

    res.json({ workspace_id: wsId, caches });
});

/**
 * POST /api/cache/clear
 * Body: { workspace_id, platform_key }
 * 清除指定客户+平台的会话缓存 [新增]
 */
app.post("/api/cache/clear", (req, res) => {
    const { workspace_id, platform_key } = req.body;
    if (!workspace_id || !platform_key) {
        return res.status(400).json({ error: "missing workspace_id or platform_key" });
    }

    const stateFile = path.join(STORAGE_DIR, String(workspace_id), `${platform_key}.json`);
    if (fs.existsSync(stateFile)) {
        fs.unlinkSync(stateFile);
        logger.info(`Cache cleared: ws=${workspace_id} platform=${platform_key}`);
        return res.json({ success: true, message: "缓存已清除" });
    }
    res.json({ success: false, message: "缓存文件不存在" });
});

// ══════════════════════════════════════════════════════════
//  [新增模块] 云端-本地双向同步
// ══════════════════════════════════════════════════════════

/**
 * GET /api/tasks/pull?workspace_id=7
 * 从云端拉取待执行任务 [新增]
 */
app.get("/api/tasks/pull", async (req, res) => {
    const wsId = req.query.workspace_id || "default";
    try {
        // 尝试调用 Laravel 后端接口拉取任务
        const resp = await fetch(`${GEOFLOW_API_URL}/api/v1/rpa/pending-tasks?workspace_id=${wsId}`, {
            headers: { "X-Api-Key": API_KEY, "Accept": "application/json" },
        });
        if (resp.ok) {
            const data = await resp.json();
            return res.json(data);
        }
    } catch (err) {
        // 云端不可达时返回本地内存中的任务
        logger.warn(`Cloud pull failed: ${err.message}`);
    }

    // 降级：返回本地内存中该 workspace 的任务
    const localTasks = [];
    for (const [id, t] of tasks.entries()) {
        if (t.workspace_id === String(wsId) && t.status === "running") {
            localTasks.push({ task_id: id, ...t });
        }
    }
    res.json({ tasks: localTasks, source: "local" });
});

/**
 * POST /api/tasks/report
 * Body: { task_id, success, shop_url, account_id, error, workspace_id, platform }
 * 上报任务执行结果到云端 [新增]
 */
app.post("/api/tasks/report", async (req, res) => {
    const payload = req.body;
    try {
        const resp = await fetch(`${GEOFLOW_API_URL}/api/v1/rpa/report`, {
            method: "POST",
            headers: { "X-Api-Key": API_KEY, "Content-Type": "application/json" },
            body: JSON.stringify(payload),
        });
        if (resp.ok) {
            return res.json({ success: true, message: "已上报云端" });
        }
    } catch (err) {
        logger.warn(`Report to cloud failed: ${err.message}`);
    }
    // 云端不可达时本地存储，后续重试
    const reportDir = path.join(__dirname, "storage", "reports");
    if (!fs.existsSync(reportDir)) fs.mkdirSync(reportDir, { recursive: true });
    fs.writeFileSync(
        path.join(reportDir, `${payload.task_id || Date.now()}.json`),
        JSON.stringify(payload, null, 2)
    );
    res.json({ success: true, message: "已本地缓存（云端不可达）" });
});

// [新增] 验证码回调（用于运行中的 RPA 流程）
const captchaStore = new Map(); // taskId -> { resolve, reject }

app.post("/api/captcha/submit", (req, res) => {
    const { task_id, code } = req.body;
    if (!task_id || !code) {
        return res.status(400).json({ error: "missing task_id or code" });
    }
    const pending = captchaStore.get(task_id);
    if (pending) {
        pending.resolve(code);
        captchaStore.delete(task_id);
        logger.info(`Captcha submitted for task ${task_id}`);
        res.json({ success: true });
    } else {
        res.status(404).json({ error: "no pending captcha for this task" });
    }
});

// [新增] 等待验证码（RPA 脚本调用此函数挂起）
app.post("/api/captcha/await", (req, res) => {
    const { task_id } = req.body;
    const timeout = req.body.timeout || 120000;
    const codePromise = new Promise((resolve, reject) => {
        captchaStore.set(task_id, { resolve, reject });
        setTimeout(() => {
            captchaStore.delete(task_id);
            reject(new Error("captcha timeout"));
        }, timeout);
    });
    codePromise
        .then(code => res.json({ success: true, code }))
        .catch(err => res.status(408).json({ success: false, error: err.message }));
});

// ══════════════════════════════════════════════════════════
//  [新增模块] 运营助手 Web 控制台
// ══════════════════════════════════════════════════════════

app.get("/", (req, res) => {
    if (fs.existsSync(dashPath)) {
        return res.sendFile(dashPath);
    }
    res.status(404).send("<h1>dashboard.html not found</h1><p>Place dashboard.html in the rpa-engine directory.</p>");
});

// ══════════════════════════════════════════════════════════
//  工具函数 [新增]
// ══════════════════════════════════════════════════════════

async function reportToCloud(taskId, payload) {
    try {
        const resp = await fetch(`${GEOFLOW_API_URL}/api/v1/rpa/report`, {
            method: "POST",
            headers: { "X-Api-Key": API_KEY, "Content-Type": "application/json" },
            body: JSON.stringify({ task_id: taskId, ...payload }),
        });
        if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
        logger.info(`Reported to cloud: taskId=${taskId}`);
    } catch (err) {
        logger.warn(`Cloud report failed (will retry): ${err.message}`);
        const reportDir = path.join(__dirname, "storage", "reports");
        if (!fs.existsSync(reportDir)) fs.mkdirSync(reportDir, { recursive: true });
        fs.writeFileSync(
            path.join(reportDir, `${taskId}.json`),
            JSON.stringify({ task_id: taskId, ...payload, reported_at: new Date().toISOString() }, null, 2)
        );
    }
}

// ══════════════════════════════════════════════════════════
//  [新增] 手动登录授权 — 摘星式一键授权
// ══════════════════════════════════════════════════════════

/**
 * POST /api/v1/auth-login
 * 打开浏览器让用户手动登录平台，成功后自动保存 Cookie。
 * Body: { platform, workspace_id, headless? }
 */
app.post("/api/v1/auth-login", auth, async (req, res) => {
  const { platform, workspace_id, headless } = req.body;
  if (!platform || !workspace_id) {
    return res.status(400).json({ error: "missing platform or workspace_id" });
  }

  const platformMap = {
    toutiao:     { url: "https://mp.toutiao.com/",          name: "今日头条" },
    baijiahao:   { url: "https://baijiahao.baidu.com/",      name: "百家号" },
    wechat_mp:   { url: "https://mp.weixin.qq.com/",         name: "微信公众号" },
    sohu:        { url: "https://mp.sohu.com/",              name: "搜狐号" },
    xiaohongshu: { url: "https://creator.xiaohongshu.com/",  name: "小红书" },
    wangyihao:   { url: "https://mp.163.com/",               name: "网易号" },
    bilibili:    { url: "https://member.bilibili.com/",      name: "B站" },
    qiehao:      { url: "https://om.qq.com/",                name: "企鹅号" },
    smzdm:       { url: "https://www.smzdm.com/",            name: "值得买" },
    douyin:      { url: "https://creator.douyin.com/",       name: "抖音" },
    kuaishou:    { url: "https://cp.kuaishou.com/",          name: "快手" },
  };
  const info = platformMap[platform];
  if (!info) return res.status(400).json({ error: "unknown platform" });

  logger.info(`Auth-login: ${info.name} for ws=${workspace_id}`);

  try {
    const browser = await chromium.launch({
      headless: headless !== undefined ? headless : false,
      args: ["--no-sandbox", "--disable-blink-features=AutomationControlled"],
    });

    // Load saved state if exists
    const stateDir = path.join(STORAGE_DIR, String(workspace_id));
    if (!fs.existsSync(stateDir)) fs.mkdirSync(stateDir, { recursive: true });
    const stateFile = path.join(stateDir, `${platform}.json`);
    const contextOpts = {
      viewport: { width: 1366, height: 768 },
      locale: "zh-CN",
      timezoneId: "Asia/Shanghai",
    };
    if (fs.existsSync(stateFile)) {
      try { contextOpts.storageState = JSON.parse(fs.readFileSync(stateFile, "utf-8")); } catch {}
    }

    const context = await browser.newContext(contextOpts);
    const page = await context.newPage();
    await page.goto(info.url, { waitUntil: "domcontentloaded", timeout: 20000 });

    // Wait for user to complete login (max 5 min)
    logger.info(`Waiting for user to login on ${info.name}...`);
    let loggedIn = false;
    const startTime = Date.now();
    const maxWait = 5 * 60 * 1000; // 5 min

    while (!loggedIn && (Date.now() - startTime) < maxWait) {
      await new Promise(r => setTimeout(r, 3000));
      try {
        const url = page.url();
        const body = await page.textContent("body").catch(() => "");
        // Detect successful login: URL changed from login page, or page has content creation elements
        if (platform === "toutiao" && (url.includes("/profile") || body.includes("创作") || body.includes("发布"))) loggedIn = true;
        else if (platform === "baijiahao" && (url.includes("baijiahao.baidu.com/builder") || body.includes("内容发布"))) loggedIn = true;
        else if (platform === "xiaohongshu" && (url.includes("creator.xiaohongshu.com") && !url.includes("/login"))) loggedIn = true;
        else if (platform === "sohu" && (url.includes("mp.sohu.com") && !url.includes("/login"))) loggedIn = true;
      } catch {}
    }

    if (loggedIn) {
      const state = await context.storageState();
      fs.writeFileSync(stateFile, JSON.stringify(state));
      logger.info(`Auth-login SUCCESS: ${info.name} state saved`);

      // 通知 Laravel 后端：Cookie 已就绪，更新 DB 状态
      reportToCloud(`auth-${platform}-${workspace_id}`, {
        success: true, platform, workspace_id,
        message: `${info.name} Cookie 已保存`,
      });

      await browser.close();
      res.json({ success: true, message: `${info.name} 登录成功，Cookie已保存，云端已同步` });
    } else {
      await browser.close();
      res.json({ success: false, message: `登录超时（5分钟），请在浏览器中手动完成登录后重试` });
    }
  } catch (err) {
    logger.error(`Auth-login error: ${err.message}`);
    res.status(500).json({ success: false, error: err.message });
  }
});

// ══════════════════════════════════════════════════════════
//  Start
// ══════════════════════════════════════════════════════════

await loadAutomations();
app.listen(PORT, "0.0.0.0", () => {
    logger.info(`Qonhub RPA Engine v2.0 started on port ${PORT} (headless=${HEADLESS})`);
    logger.info(`Dashboard: http://127.0.0.1:${PORT}`);
    logger.info(`Cache dir: ${STORAGE_DIR}`);
    logger.info(`Loaded automations: ${Object.keys(automations).join(", ") || "none"}`);
    if (GEOFLOW_API_URL) logger.info(`Cloud sync target: ${GEOFLOW_API_URL}`);
});

process.on("SIGTERM", () => { logger.info("SIGTERM — shutting down"); process.exit(0); });
process.on("SIGINT", () => { logger.info("SIGINT — shutting down"); process.exit(0); });
