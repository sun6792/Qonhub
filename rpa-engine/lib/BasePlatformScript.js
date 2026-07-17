/**
 * 通用平台脚本基类 — 双层指纹伪装 + 行为模拟 + 标准化状态码。
 *
 * 所有平台脚本继承此类即可自动获得：
 *   1. playwright-extra + stealth 插件（20+ 指纹维度）
 *   2. 自定义国产平台补丁（navigator.webdriver 兜底、Chrome 插件模拟）
 *   3. 真人行为模拟（随机延时、逐字输入、鼠标轨迹、随机滚动）
 *   4. 代理 IP 注入 + storageState Cookie 持久化
 *   5. 统一状态码 + 错误分类 + 自动截图
 */

import { chromium } from "playwright-extra";
import stealth from "puppeteer-extra-plugin-stealth";
import fs from "fs";
import path from "path";
import { fileURLToPath } from "url";

// Edge浏览器(国内用户标配,兼容最好)
const BROWSER_CHANNEL = 'msedge';

// ES module __dirname 兼容
const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

// stealth 插件正常启用(Edge兼容Chromium内核)
chromium.use(stealth());

// ══════════════════════════════════════════════════════════
//  国内平台额外补丁（双层叠加：stealth 全局 + 自定义 JS）
// ══════════════════════════════════════════════════════════

const CN_STEALTH_PATCH = `
// 兜底：确保 navigator.webdriver 绝对为 false
Object.defineProperty(navigator, 'webdriver', { get: () => false });
delete Object.getPrototypeOf(navigator).webdriver;

// 模拟 Chrome 插件（国内平台特有检测）
window.chrome = {
    runtime: { platform: "Win32", engine: "blink" },
    loadTimes: () => {},
    csi: () => {},
    app: {},
};
`;

// ══════════════════════════════════════════════════════════
//  统一执行状态码（对接 PHP 端重试策略）
// ══════════════════════════════════════════════════════════

export const PUBLISH_STATUS = {
    SUCCESS: "success",               // 发布成功，回传 URL
    LOGIN_EXPIRED: "login_expired",   // Cookie 失效，通知运营重新授权
    CAPTCHA_BLOCKED: "captcha_blocked", // 遇到验证码，暂停等人工
    CONTENT_REJECTED: "content_rejected", // 内容违规，标记不可发布
    RATE_LIMITED: "rate_limited",     // 今日额度用完，明天重试
    UNKNOWN_ERROR: "unknown_error",   // 未知错误，截图留痕
};

// ══════════════════════════════════════════════════════════
//  User-Agent 池
// ══════════════════════════════════════════════════════════

const UA_POOL = [
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36",
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36 Edg/125.0.0.0",
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36",
];

// ══════════════════════════════════════════════════════════
//  BasePlatformScript
// ══════════════════════════════════════════════════════════

export class BasePlatformScript {
    /** @type {string} 平台 key（子类覆盖） */
    static platform = "unknown";

    /** @type {string} 平台中文名 */
    static description = "";

    /** @type {string} 注册入口 URL */
    registerUrl = "";

    /** @type {string} 认证入口 URL */
    certifyUrl = "";

    constructor(taskId, account, enterprise, options, logFn) {
        this.taskId = taskId;
        this.account = account;
        this.enterprise = enterprise;
        this.options = options;
        this.log = (msg) => logFn(`[${taskId}] ${msg}`);
        this.headless = options.headless !== false;
        this.proxy = options.bound_ip || null;
        this.timeout = options.timeout_seconds || 180;
        this.screenshotDir = options.screenshotDir || "./screenshots";
        // [新增] workspaceId 用于缓存隔离
        this.workspaceId = options.workspace_id || "default";
    }

    // ── 工具方法 ──────────────────────────────────────────

    rand(min, max) { return Math.floor(Math.random() * (max - min + 1)) + min; }
    wait(min = 300, max = 2500) { return new Promise(r => setTimeout(r, this.rand(min, max))); }
    randomUA() { return UA_POOL[this.rand(0, UA_POOL.length - 1)]; }

    async ss(page, label) {
        const f = path.join(this.screenshotDir, `${this.taskId}_${label}_${Date.now()}.png`);
        try { await page.screenshot({ path: f, fullPage: true }); } catch {}
        return f;
    }

    // ── Cookie 健康检查 ────────────────────────────────────

    /**
     * v2.9: 检查已保存的 Cookie 是否有效。
     * 在发布前调用，避免脚本跑到一半发现未登录。
     * @returns {boolean} true = Cookie 有效，false = 需要重新登录
     */
    async checkCookieHealth(checkUrl, loginIndicator) {
        const stateFile = path.join(__dirname, "..", "storage", "states", String(this.workspaceId), `${this.constructor.platform}.json`);
        if (!fs.existsSync(stateFile)) {
            this.log(`Cookie check: no saved state → need login`);
            return false;
        }
        try {
            const state = JSON.parse(fs.readFileSync(stateFile, "utf-8"));
            const cookies = state.cookies || [];
            if (cookies.length < 3) {
                this.log(`Cookie check: only ${cookies.length} cookies → need login`);
                return false;
            }
            // 检查过期：超过80%的cookie已过期 → 需要重新登录
            const now = Date.now() / 1000;
            const expired = cookies.filter(c => c.expires && c.expires > 0 && c.expires < now).length;
            if (expired > cookies.length * 0.8) {
                this.log(`Cookie check: ${expired}/${cookies.length} expired → need refresh`);
                return false;
            }
            this.log(`Cookie check: ${cookies.length} cookies, ${expired} expired → OK`);
            return true;
        } catch {
            this.log(`Cookie check: corrupted state file`);
            return false;
        }
    }

    // ── 崩溃恢复 ────────────────────────────────────────────

    /**
     * v2.9: 带崩溃恢复的浏览器启动。
     * 浏览器崩溃或页面无响应时自动重试（最多3次，指数退避）。
     */
    async launchBrowserWithRetry(maxRetries = 3) {
        let lastError;
        for (let i = 0; i < maxRetries; i++) {
            try {
                const result = await this.launchBrowser();
                // 快速健康检查：页面能否正常响应
                try {
                    await result.page.evaluate(() => document.title);
                } catch (pageErr) {
                    throw new Error(`Page unresponsive after launch: ${pageErr.message}`);
                }
                return result;
            } catch (err) {
                lastError = err;
                this.log(`Browser launch attempt ${i+1}/${maxRetries} failed: ${err.message}`);
                if (i < maxRetries - 1) {
                    const delay = Math.min(2000 * Math.pow(2, i), 15000);
                    this.log(`Retrying in ${delay}ms...`);
                    await new Promise(r => setTimeout(r, delay));
                }
            }
        }
        throw new Error(`Browser launch failed after ${maxRetries} attempts: ${lastError.message}`);
    }

    // ── 浏览器启动 ────────────────────────────────────────

    /**
     * 启动浏览器实例。
     *
     * 支持两种后端（由 RPA_BACKEND 环境变量控制）：
     *   - 'local'（默认）：本地 Edge/CDP 浏览器，保持现有行为不变
     *   - 'browserless'：对接 Docker 化 browserless 集群（ws://browserless:3000）
     */
    async launchBrowser() {
        const backend = process.env.RPA_BACKEND || 'local';

        // [改造] storageState 持久化：按 workspaceId + platform 分层隔离
        const stateDir = path.join(__dirname, "..", "storage", "states", String(this.workspaceId));
        if (!fs.existsSync(stateDir)) fs.mkdirSync(stateDir, { recursive: true });
        const stateFile = path.join(stateDir, `${this.constructor.platform}.json`);

        let browser, context, page;

        if (backend === 'browserless') {
            // ── Browserless 模式：连接 Docker Chrome 集群 ──
            const wsUrl = process.env.BROWSERLESS_WS_URL || 'ws://browserless:3000';
            const token = process.env.BROWSERLESS_TOKEN || '';
            const wsEndpoint = token ? `${wsUrl}?token=${token}` : wsUrl;

            this.log(`Connecting to browserless: ${wsUrl}`);
            browser = await chromium.connectOverCDP(wsEndpoint);

            // browserless 默认有一个 context，复用已有 context 保持 profiles
            context = browser.contexts()[0];
            // 加载已保存的 storageState 到已有 context
            if (fs.existsSync(stateFile)) {
                try {
                    const state = JSON.parse(fs.readFileSync(stateFile, "utf-8"));
                    if (state.cookies && state.cookies.length > 0) {
                        await context.addCookies(state.cookies);
                        this.log(`Loaded ${state.cookies.length} cookies from ${stateFile}`);
                    }
                } catch { /* corrupted, ignore */ }
            }
            page = await context.newPage();
        } else {
            // ── 本地模式：启动本地 Edge 浏览器 ──
            const launchOpts = {
                headless: this.headless,
                args: [
                    "--no-sandbox",
                    "--disable-blink-features=AutomationControlled",
                    "--disable-features=IsolateOrigins,site-per-process",
                ],
            };

            if (this.proxy) {
                launchOpts.proxy = { server: this.proxy };
            }

            launchOpts.channel = BROWSER_CHANNEL;
            browser = await chromium.launch(launchOpts);

            const contextOpts = {
                userAgent: this.randomUA(),
                viewport: { width: 1366 + this.rand(-100, 100), height: 768 + this.rand(-50, 50) },
                locale: "zh-CN",
                timezoneId: "Asia/Shanghai",
                permissions: [],
                geolocation: undefined,
            };
            if (fs.existsSync(stateFile)) {
                try {
                    contextOpts.storageState = JSON.parse(fs.readFileSync(stateFile, "utf-8"));
                    this.log(`Loaded saved login state from ${stateFile}`);
                } catch { /* corrupted, ignore */ }
            }

            context = await browser.newContext(contextOpts);
            page = await context.newPage();
        }

        // 双层指纹伪装：stealth 插件（全局）+ 国产平台补丁（页面层）
        await page.addInitScript(CN_STEALTH_PATCH);

        // 返回 stateFile 路径供 execute() 保存
        this._stateFile = stateFile;
        this._context = context;

        return { browser, context, page };
    }

    // ── 行为模拟 ──────────────────────────────────────────

    /** 模拟真人浏览：随机滚动页面 */
    async simulateHumanBrowsing(page) {
        await this.wait(500, 1500);
        const scrolls = this.rand(1, 4);
        for (let i = 0; i < scrolls; i++) {
            await page.mouse.wheel(0, this.rand(200, 600));
            await this.wait(300, 800);
        }
    }

    /** 模拟鼠标随机悬停 */
    async simulateMouseHover(page) {
        try {
            const links = await page.$$("a, button, input");
            if (links.length > 2) {
                const el = links[this.rand(0, links.length - 1)];
                await el.hover();
                await this.wait(200, 600);
            }
        } catch { /* hover not critical */ }
    }

    // ── 智能输入 ──────────────────────────────────────────

    /**
     * 查找元素：先在主页面找，再在 iframe 里找。
     */
    async findElement(page, selectors, timeout = 8000) {
        const list = Array.isArray(selectors) ? selectors : [selectors];
        for (const sel of list) {
            try {
                const el = await page.waitForSelector(sel, { state: "visible", timeout });
                if (el) return el;
            } catch {}
        }
        for (const frame of page.frames()) {
            if (frame === page.mainFrame()) continue;
            for (const sel of list) {
                try {
                    const el = await frame.waitForSelector(sel, { state: "visible", timeout: 3000 });
                    if (el) return el;
                } catch {}
            }
        }
        return null;
    }

    /**
     * 人类式输入：逐字敲入，随机间隔。
     */
    async typeHuman(page, selectors, text, opts = {}) {
        if (!text) return;
        await this.wait();
        const el = await this.findElement(page, selectors);
        if (!el) {
            const body = await page.textContent("body").catch(() => "");
            throw new Error(`找不到输入框: ${JSON.stringify(selectors)}. body: ${body.substring(0, 300)}`);
        }
        await el.scrollIntoViewIfNeeded();
        await this.wait(200, 600);
        await el.click({ force: opts.force || false });
        await this.wait(100, 300);
        // 用 fill() 直接设值，避免逐字 type 导致中文乱码
        try { await el.fill(text); }
        catch (fillErr) {
            // fill 失败时回退到逐字输入
            await el.fill("");
            for (const ch of text) {
                await el.type(ch, { delay: this.rand(60, 150) });
            }
        }
        await this.wait(300, 1000);
    }

    /**
     * 智能填充：根据 hint 自动找对应的输入框并填写。
     */
    async smartFill(page, hint, text) {
        if (!text) return;
        const patterns = {
            phone: ['input[name*="phone"]', 'input[name*="mobile"]', 'input[name*="tel"]', 'input[name*="lxdh"]', 'input[type="tel"]', 'input[placeholder*="手机"]', 'input[placeholder*="电话"]', 'input[placeholder*="联系"]'],
            email: ['input[name*="email"]', 'input[name*="dzyx"]', 'input[type="email"]', 'input[placeholder*="邮箱"]', 'input[placeholder*="邮件"]'],
            company: ['input[name*="company"]', 'input[name*="gsmc"]', 'input[name*="enterprise"]', 'input[placeholder*="公司"]', 'input[placeholder*="企业"]', 'input[placeholder*="单位"]'],
            password: ['input[name*="password"]', 'input[name*="pwd"]', 'input[type="password"]', 'input[placeholder*="密码"]'],
            username: ['input[name*="username"]', 'input[name*="account"]', 'input[name*="user"]', 'input[placeholder*="账号"]', 'input[placeholder*="用户名"]'],
            contact: ['input[name*="contact"]', 'input[name*="person"]', 'input[name*="lxr"]', 'input[placeholder*="联系人"]', 'input[placeholder*="姓名"]'],
            credit_code: ['input[name*="credit"]', 'input[name*="tyxydm"]', 'input[name*="uscc"]', 'input[placeholder*="信用"]', 'input[placeholder*="统一"]'],
            legal_person: ['input[name*="legal"]', 'input[name*="frdb"]', 'input[name*="person"]', 'input[placeholder*="法人"]', 'input[placeholder*="代表"]'],
            address: ['input[name*="address"]', 'input[name*="xxdz"]', 'textarea[name*="address"]', 'input[placeholder*="地址"]'],
            scope: ['textarea[name*="scope"]', 'textarea[name*="jyfw"]', 'textarea[placeholder*="经营"]', 'textarea[name*="intro"]'],
            products: ['textarea[name*="product"]', 'textarea[name*="zycp"]', 'textarea[placeholder*="产品"]', 'textarea[placeholder*="主营"]'],
            website: ['input[name*="website"]', 'input[name*="qywz"]', 'input[name*="url"]', 'input[placeholder*="网址"]', 'input[placeholder*="官网"]'],
            province: ['select[name*="province"]', 'select[name*="sssf"]'],
            city: ['select[name*="city"]', 'select[name*="sscs"]'],
        };
        await this.typeHuman(page, patterns[hint] || [hint], text);
    }

    // ── 页面导航（带重试 + WAF 检测 + 自动等待） ────────

    /**
     * 检测并等待常见 WAF/JS 挑战完成。
     */
    async waitForWafChallenge(page, maxWaitSec = 30) {
        const wafPatterns = [
            "安全检测", "安全检查", "正在检测", "请耐心等待",
            "火山引擎", "Cloudflare", "cf-browser-verify",
            "Just a moment", "DDoS", "verify",
            "captcha", "验证码",
        ];
        for (let i = 0; i < maxWaitSec; i++) {
            await new Promise(r => setTimeout(r, 1000));
            try {
                const body = await page.textContent("body");
                let blocked = false;
                for (const pattern of wafPatterns) {
                    if (body.includes(pattern)) { blocked = true; break; }
                }
                if (!blocked) {
                    if (i > 0) this.log(`   WAF cleared after ${i}s`);
                    return true; // WAF 通过了
                }
            } catch {}
        }
        this.log(`   ⚠️ WAF still active after ${maxWaitSec}s`);
        return false;
    }

    async safeGoto(page, url, opts = {}) {
        for (let attempt = 0; attempt < 3; attempt++) {
            try {
                await page.goto(url, { waitUntil: opts.waitUntil || "domcontentloaded", timeout: opts.timeout || 20000 });
                // 等待 WAF 挑战完成
                const wafCleared = await this.waitForWafChallenge(page, 15);
                if (!wafCleared) {
                    this.log(`   WAF blocked (attempt ${attempt + 1}), retrying...`);
                    continue;
                }
                await this.wait(2000, 4000);

                // 403/Access Denied 检测
                const body = await page.textContent("body").catch(() => "");
                if (body.includes("403 Forbidden") || body.includes("Access Denied")) {
                    if (attempt < 2) { this.log(`   ⚠️ 403, retrying...`); continue; }
                }
                return page;
            } catch (e) {
                this.log(`Nav failed (attempt ${attempt + 1}): ${e.message}`);
                if (attempt >= 2) throw e;
            }
        }
        return page;
    }

    // ── 提交表单 ──────────────────────────────────────────

    async clickSubmit(page, text = "提交") {
        await this.wait(500, 2000);
        const btnSelectors = [
            `button:has-text("${text}")`,
            `input[type="submit"][value*="${text}"]`,
            `a:has-text("${text}")`,
            'button[type="submit"]',
        ];
        const btn = await this.findElement(page, btnSelectors);
        if (btn) {
            await this.wait(300, 1000);
            await btn.click({ force: true });
        } else {
            this.log(`⚠️ 提交按钮未找到，尝试按 Enter`);
            await page.keyboard.press("Enter");
        }
        await this.wait(3000, 6000);
    }

    // ── 生成账号密码 ──────────────────────────────────────

    genAccount() {
        const ts = Date.now().toString(36);
        return {
            username: this.account.username || `qy${ts}`,
            password: this.account.credential || `A${ts}${this.rand(100, 999)}`,
        };
    }

    // ── 标准执行入口 ──────────────────────────────────────

    async execute() {
        // v2.9: 带崩溃恢复的浏览器启动
        const { browser, page } = await this.launchBrowserWithRetry();
        let result = { success: false, shop_url: "", account_id: "", error: "", status: "error" };

        try {
            // v2.9: Cookie 健康预检（B2B 注册跳过，发布类脚本覆盖此检查）
            if (typeof this.checkCookieHealth === 'function') {
                const valid = await this.checkCookieHealth();
                if (!valid) {
                    return { success: false, shop_url: "", account_id: "", error: "Cookie expired — need re-login via operator dashboard", status: "login_expired" };
                }
            }

            // Step 1: 注册 + 登录
            result = await this.registerFlow(page);
            result.account_id = result.account_id || this.genAccount().username;
            this.log(`Register flow completed: ${result.success ? 'success' : 'failed'}`);

            // Step 2: 企业认证（如果子类实现了 certifyFlow）
            if (result.success && typeof this.certifyFlow === 'function') {
                try {
                    const certResult = await this.certifyFlow(page);
                    result = { ...result, ...certResult };
                    this.log(`Certify flow completed: ${certResult.shop_url || 'no shop URL'}`);
                } catch (certErr) {
                    this.log(`⚠️ Certify flow failed (register succeeded): ${certErr.message}`);
                    // 注册成功了但认证失败，不覆盖整个 result 为失败
                    result.certify_error = certErr.message;
                }
            }
        } catch (err) {
            this.log(`❌ ${err.message}`);
            result.error = err.message;
            try { await this.ss(page, "error"); } catch {}
        } finally {
            // 持久化保存 Cookie/登录态，下次自动恢复
            if (this._stateFile && this._context) {
                try {
                    const state = await this._context.storageState();
                    fs.writeFileSync(this._stateFile, JSON.stringify(state));
                    this.log(`Saved login state to ${this._stateFile}`);
                } catch {}
            }
            try { await browser.close(); } catch {}
            this.log("Browser closed");
        }

        return result;
    }

    // ── Cookie/登录态持久化 ──────────────────────────────

    /**
     * 手动保存 storageState（供 publish 脚本等直接 launchBrowser 后调用）。
     * execute() 已在 finally 块自动保存，但单独 launchBrowser() 需手动调用本方法。
     */
    async _saveState() {
        if (this._stateFile && this._context) {
            try {
                const state = await this._context.storageState();
                fs.writeFileSync(this._stateFile, JSON.stringify(state));
                this.log(`Saved login state to ${this._stateFile}`);
            } catch (e) {
                this.log(`Failed to save state: ${e.message}`);
            }
        }
    }

    // ── 子类必须覆盖的方法 ───────────────────────────────

    /**
     * 注册认证流程（子类实现）。
     * @returns {Promise<{success:boolean, shop_url:string, account_id:string, status:string}>}
     */
    async registerFlow(page) {
        throw new Error(`${this.constructor.platform}: registerFlow() 未实现`);
    }
}
