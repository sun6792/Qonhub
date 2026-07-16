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
const API_KEY = (() => {
  if (process.env.RPA_API_KEY) return process.env.RPA_API_KEY;
  // 无环境变量时生成随机密钥并警告，拒绝使用硬编码默认值
  const fallback = 'rpa-' + Array.from({length: 32}, () => Math.floor(Math.random()*16).toString(16)).join('');
  console.warn('[SECURITY] RPA_API_KEY 未设置！已生成临时随机密钥。请在生产环境设置 RPA_API_KEY 环境变量。');
  console.warn(`临时密钥: ${fallback}`);
  return fallback;
})();
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
app.use(express.json({ limit: "10mb", type: "application/json" }));
app.use(express.urlencoded({ extended: true, limit: "10mb" }));

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

// ══════════════════════════════════════════════════════════
//  RPA 真实浏览器搜索 — Scout Agent 调用
// ══════════════════════════════════════════════════════════

const SCOUT_PLATFORMS = {
    doubao: {
        name: "豆包",
        url: "https://www.doubao.com/chat/new",
        inputSel: "textarea[placeholder*='提问'], textarea[placeholder*='发消息'], textarea",
        submitKey: "Enter",
        needNewChat: false,
    },
    yuanbao: {
        name: "腾讯元宝",
        url: "https://yuanbao.tencent.com/chat/",
        inputSel: "[class*=\"chat-input\"], [class*=\"InputTextArea\"], textarea",
        submitKey: "Enter",
        isRichEditor: true,
    },
    baidu: {
        name: "百度AI",
        url: "https://chat.baidu.com/search",
        inputSel: "textarea",
        submitKey: "Enter",
    },
    deepseek: {
        name: "DeepSeek",
        url: "https://chat.deepseek.com/",
        inputSel: "textarea",
        submitKey: "Enter",
    },
    qianwen: {
        name: "通义千问",
        url: "https://tongyi.aliyun.com/qianwen/",
        inputSel: "textarea",
        submitKey: "Enter",
    },
    xf_xinghuo: {
        name: "讯飞星火",
        url: "https://xinghuo.xfyun.cn/desk",
        inputSel: "textarea",
        submitKey: "Enter",
    },
    kimi: {
        name: "Kimi",
        url: "https://kimi.moonshot.cn/",
        inputSel: "textarea",
        submitKey: "Enter",
    },
};

// ── 反检测补丁（对齐 BasePlatformScript） ──
const UA_POOL = [
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36",
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36 Edg/125.0.0.0",
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36",
];
const rand = (min, max) => Math.floor(Math.random() * (max - min + 1)) + min;
const randomUA = () => UA_POOL[rand(0, UA_POOL.length - 1)];

const STEALTH_PATCH = `
Object.defineProperty(navigator, 'webdriver', { get: () => false });
delete Object.getPrototypeOf(navigator).webdriver;
window.chrome = { runtime: { platform: "Win32", engine: "blink" }, loadTimes: () => {}, csi: () => {}, app: {} };
`;

/**
 * POST /api/v1/scout — 真实浏览器搜索 AI 平台。
 * 双层反检测 + Cookie 持久化 + 搜索提取。
 * Body: { platform, query, workspace_id }
 */
app.post("/api/v1/scout", auth, async (req, res) => {
    const { platform, query, workspace_id } = req.body;
    const cfg = SCOUT_PLATFORMS[platform];
    if (!cfg) return res.status(400).json({ error: "unsupported platform: " + platform });

    const wsId = workspace_id || "default";
    const taskId = `scout_${platform}_${Date.now()}`;
    const stateDir = path.join(STORAGE_DIR, String(wsId));
    if (!fs.existsSync(stateDir)) fs.mkdirSync(stateDir, { recursive: true });
    const stateFile = path.join(stateDir, `${platform}.json`);

    logger.info(`[${taskId}] Scout: ${cfg.name} query="${query}"`);

    let browser, context;
    try {
        // 启动选项（对齐 BasePlatformScript 反检测）
        const launchOpts = {
            channel: "msedge",
            headless: HEADLESS,
            args: [
                "--no-sandbox",
                "--disable-dev-shm-usage",
                "--disable-gpu",
                "--disable-blink-features=AutomationControlled",
                "--disable-features=IsolateOrigins,site-per-process",
            ],
            timeout: 30000,
        };

        // Context 选项（含已保存登录态 + 随机UA + 国内时区）
        const contextOpts = {
            userAgent: randomUA(),
            viewport: { width: 1366 + rand(-100, 100), height: 768 + rand(-50, 50) },
            locale: "zh-CN",
            timezoneId: "Asia/Shanghai",
        };
        if (fs.existsSync(stateFile)) {
            try {
                contextOpts.storageState = JSON.parse(fs.readFileSync(stateFile, "utf-8"));
                logger.info(`[${taskId}] Loaded login state (${contextOpts.storageState.cookies?.length || 0} cookies)`);
            } catch { /* corrupted state, ignore */ }
        } else {
            logger.warn(`[${taskId}] No saved state — search will be unauthenticated`);
        }

        browser = await chromium.launch(launchOpts);
        context = await browser.newContext(contextOpts);
        const page = await context.newPage();

        // 最小反检测：移除 webdriver 标记
        await page.addInitScript(() => {
            Object.defineProperty(navigator, 'webdriver', { get: () => false });
        });

        // 导航
        await page.goto(cfg.url, { waitUntil: "domcontentloaded", timeout: 20000 });
        await page.waitForTimeout(rand(4000, 8000));

        // 模拟人类：缓慢滚动
        await page.mouse.wheel(0, rand(300, 800));
        await page.waitForTimeout(rand(500, 1500));

        // 找到输入框（主页面 + iframe 遍历）
        let input = await page.locator(cfg.inputSel).first();
        if (await input.count() === 0) {
            for (const frame of page.frames()) {
                if (frame === page.mainFrame()) continue;
                input = await frame.locator(cfg.inputSel).first();
                if (await input.count() > 0) break;
            }
        }
        if (await input.count() === 0) {
            await browser.close();
            return res.json({ success: false, error: "input_not_found", text: "", cited_urls: [], platform });
        }

        // 人类式输入
        await input.click();
        await page.waitForTimeout(rand(300, 800));

        if (cfg.isRichEditor) {
            // 富文本编辑器：用键盘输入（不能用 fill）
            await page.keyboard.type(query, { delay: rand(60, 150) });
        } else {
            // 标准输入框：清空 + 逐字输入
            await input.fill("");
            for (const ch of query) {
                await input.type(ch, { delay: rand(60, 150) });
            }
        }
        await page.waitForTimeout(rand(300, 1000));

        // 记录提交前的文本长度
        const preLen = await page.evaluate(() => document.body?.innerText?.length || 0);

        // 提交
        await page.waitForTimeout(rand(500, 1000));
        if (cfg.submitKey === "Enter") await page.keyboard.press("Enter");

        // 等待 AI 回答（最长 60 秒，检测新内容增量 > 200 字符）
        for (let i = 0; i < 20; i++) {
            await page.waitForTimeout(3000);
            try {
                const curLen = await page.evaluate(() => document.body?.innerText?.length || 0);
                if ((curLen - preLen) > 200) break;
                // 也检测验证码
                const captcha = await page.evaluate(() => {
                    const body = document.body?.innerText || '';
                    return body.includes('验证') || body.includes('captcha') || body.includes('安全检测');
                });
                if (captcha) { logger.warn(`[${taskId}] CAPTCHA detected`); break; }
            } catch {}
        }

        // 模拟真人浏览
        await page.mouse.wheel(0, rand(200, 600));
        await page.waitForTimeout(rand(500, 1000));

        // 提取回答文本（排除导航/工具栏等干扰）
        const bodyText = await page.evaluate(() => {
            const main = document.querySelector('main, [role="main"], .chat-container, [class*="conversation"]');
            if (main?.innerText) return main.innerText;
            const content = document.querySelector('[class*="content"]:not(nav):not(header)');
            if (content?.innerText) return content.innerText;
            return document.body?.innerText || '';
        });
        const idx = bodyText.indexOf(query);
        const answer = idx > -1
            ? bodyText.substring(idx + query.length).trim().substring(0, 8000)
            : bodyText.substring(0, 8000);

        // 提取引用URL
        const urlRegex = /https?:\/\/[^\s\]\)>\"一-鿿]+/g;
        const rawUrls = answer.match(urlRegex) || [];
        const citedUrls = [...new Set(rawUrls)]
            .filter(u => !u.includes(cfg.url.split("/")[2]))
            .slice(0, 20);

        // 截图
        try { await page.screenshot({ path: path.join(SCREENSHOT_DIR, `${taskId}_${Date.now()}.png`), fullPage: false }); } catch {}

        // ★ 保存登录态（Cookie 持久化，下次自动恢复）
        try {
            const state = await context.storageState();
            fs.writeFileSync(stateFile, JSON.stringify(state));
            logger.info(`[${taskId}] Saved state: ${state.cookies?.length || 0} cookies to ${stateFile}`);
        } catch (e) {
            logger.warn(`[${taskId}] Failed to save state: ${e.message}`);
        }

        await browser.close();

        logger.info(`[${taskId}] Scout done: ${answer.length} chars, ${citedUrls.length} URLs`);
        res.json({
            success: true, platform, query, answer,
            cited_urls: citedUrls, answer_length: answer.length,
        });
    } catch (err) {
        logger.error(`[${taskId}] Scout failed: ${err.message}`);
        try {
            // 即使失败也尝试保存状态（可能有部分 Cookie）
            if (context) {
                const state = await context.storageState();
                if (state.cookies?.length > 0) fs.writeFileSync(stateFile, JSON.stringify(state));
            }
        } catch {}
        try { await browser?.close(); } catch {}
        res.json({ success: false, error: err.message, text: "", cited_urls: [], platform });
    }
});

// ── 原有 tasks 端点 ────────────────────────────────────

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
                platform_key: key, platform_name: mod.description || key,
                created_at: null, age_days: null, valid: false, size_kb: 0,
            });
        }
    }

    // 补充：AI 搜索平台（即使没缓存也列出，供运营助手展示）
    const aiNames = {
        doubao: "豆包", yuanbao: "腾讯元宝", baidu: "百度AI",
        deepseek: "DeepSeek", qianwen: "通义千问", xf_xinghuo: "讯飞星火", kimi: "Kimi",
    };
    for (const [key, name] of Object.entries(aiNames)) {
        if (!caches.find(c => c.platform_key === key)) {
            caches.push({
                platform_key: key, platform_name: name,
                created_at: null, age_days: null, valid: false, size_kb: 0,
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
    res.setHeader("Cache-Control", "no-store, no-cache, must-revalidate");
    res.setHeader("Pragma", "no-cache");
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
    // 发布平台
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
    // AI 搜索平台（Scout Agent 数据源）
    doubao:      { url: "https://www.doubao.com/chat/",      name: "豆包" },
    yuanbao:     { url: "https://yuanbao.tencent.com/chat/",  name: "腾讯元宝" },
    baidu:       { url: "https://chat.baidu.com/search",      name: "百度AI" },
    deepseek:    { url: "https://chat.deepseek.com/",         name: "DeepSeek" },
    qianwen:     { url: "https://tongyi.aliyun.com/qianwen/", name: "通义千问" },
    xf_xinghuo:  { url: "https://xinghuo.xfyun.cn/desk",       name: "讯飞星火" },
    kimi:        { url: "https://kimi.moonshot.cn/",          name: "Kimi" },
  };
  const info = platformMap[platform];
  if (!info) return res.status(400).json({ error: "unknown platform" });

  logger.info(`Auth-login: ${info.name} for ws=${workspace_id}`);

  try {
    // 加载已有登录态（如果存在）
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

    // 启动浏览器（反崩溃参数）
    const browser = await chromium.launch({
      channel: "msedge",
      headless: headless !== undefined ? headless : false,
      args: [
        "--no-sandbox",
        "--disable-dev-shm-usage",
        "--disable-gpu",
        "--disable-blink-features=AutomationControlled",
      ],
      timeout: 30000,
    });
    const context = await browser.newContext(contextOpts);
    const page = await context.newPage();
    await page.goto(info.url, { waitUntil: "domcontentloaded", timeout: 20000 });

    // 所有平台统一流程：打开浏览器 → 等用户关闭窗口 → 保存 Cookie → 验证数量
    logger.info(`[${info.name}] Browser opened — login and close window when done`);
    await new Promise((resolve) => {
      let checks = 0;
      const timer = setInterval(() => {
        checks++;
        try {
          if (page.isClosed() || !browser.isConnected()) { clearInterval(timer); resolve(); return; }
          if (checks >= 150) { clearInterval(timer); resolve(); } // 5 分钟超时
        } catch (e) { clearInterval(timer); resolve(); }
      }, 2000);
    });

    let cookieCount = 0;
    try {
      const state = await context.storageState();
      cookieCount = state.cookies?.length || 0;
      fs.writeFileSync(stateFile, JSON.stringify(state));
      logger.info(`[${info.name}] Saved ${cookieCount} cookies to ${stateFile}`);
    } catch (e) { logger.warn(`[${info.name}] Save failed: ${e.message}`); }

    await browser.close();

    if (cookieCount > 2) {
      // 同步到 Laravel 后端（更新 ClientPlatformAccount + ContentPublisherAccount + Anchor）
      reportToCloud(`auth-${platform}-${workspace_id}`, {
        success: true, platform, workspace_id,
        message: `${info.name} Cookie 已保存 (${cookieCount}个)`,
      });
      res.json({ success: true, message: `${info.name} Cookie 已保存 (${cookieCount}个)` });
    } else {
      res.json({ success: false, message: `Cookie 数量过少 (${cookieCount}个)，可能未完成登录，请重试` });
    }
  } catch (err) {
    logger.error(`Auth-login error: ${err.message}`);
    try { await browser?.close(); } catch {}
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
