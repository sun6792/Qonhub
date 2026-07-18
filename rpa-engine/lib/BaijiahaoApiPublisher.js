/**
 * 百家号 API 直发模块 — 完全绕开 DOM 编辑器。
 *
 * 核心思路（来自 MultiPost 源码逆向）：
 *   百家号后台有一个内部 JSON API：POST /pcui/article/save
 *   接收 FormData（title + htmlContent + cover_images...）
 *   文章直接进草稿箱 → 零 isTrusted 风险。
 *
 * 本模块只做两件事：
 *   1. 从浏览器 session 提取 Cookie + edit-token
 *   2. 用这些凭据调 API 发文章
 *
 * 流程：
 *   launchBrowser(cookies) → goto baijiahao.baidu.com
 *   → extractAuth() → localStorage['edit-token'] + cookie string
 *   → postArticle(auth, article) → fetch() to pcui/article/save
 *   → return { article_id, article_url }
 *
 * 依赖：已通过 RPA auth-login 获取的 cookie state 文件。
 */

import { chromium } from "playwright-extra";
import stealth from "puppeteer-extra-plugin-stealth";
import fs from "fs";
import path from "path";
import { fileURLToPath } from "url";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
chromium.use(stealth());

const STORAGE_DIR = process.env.RPA_STORAGE_DIR || path.join(__dirname, "..", "storage", "states");

// ── 反检测补丁 ──
const UA_POOL = [
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36",
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36 Edg/125.0.0.0",
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36",
];

function rand(min, max) { return Math.floor(Math.random() * (max - min + 1)) + min; }
function randomUA() { return UA_POOL[rand(0, UA_POOL.length - 1)]; }

const API_BASE = "https://baijiahao.baidu.com";
const ARTICLE_SAVE_URL = `${API_BASE}/pcui/article/save`;
const IMAGE_UPLOAD_URL = `${API_BASE}/materialui/picture/uploadProxy`;

/**
 * 主入口：用 API 发布一篇文章到百家号草稿箱。
 *
 * @param {object} opts
 * @param {string} opts.workspaceId  - 用于加载 cookie state
 * @param {string} opts.title        - 文章标题(≤30字)
 * @param {string} opts.content      - HTML 正文
 * @param {string} [opts.digest]     - 摘要
 * @param {string} [opts.coverImage] - 封面图本地路径(可选)
 * @param {boolean} [opts.debug]     - 开启后会保留浏览器不关
 * @returns {Promise<{success:boolean, article_id?:string, article_url?:string, error?:string}>}
 */
export async function publishViaApi(opts = {}) {
    const { workspaceId, title, content, digest, coverImage, debug } = opts;
    const stateFile = path.join(STORAGE_DIR, String(workspaceId), "baijiahao.json");

    if (!fs.existsSync(stateFile)) {
        return { success: false, error: `No cookie state for workspace ${workspaceId}. Run auth-login first.` };
    }

    let browser, context;
    try {
        // ── 1. 启动浏览器加载 cookie ──
        const state = JSON.parse(fs.readFileSync(stateFile, "utf-8"));
        const cookieCount = state.cookies?.length || 0;
        console.log(`[BaijiahaoAPI] Loaded ${cookieCount} cookies from state`);

        browser = await chromium.launch({
            channel: "msedge",
            headless: process.env.RPA_HEADLESS === "true",
            args: [
                "--no-sandbox", "--disable-dev-shm-usage", "--disable-gpu",
                "--disable-blink-features=AutomationControlled",
            ],
            timeout: 30000,
        });

        context = await browser.newContext({
            userAgent: randomUA(),
            viewport: { width: 1366, height: 768 },
            locale: "zh-CN",
            timezoneId: "Asia/Shanghai",
            storageState: state,
        });

        const page = await context.newPage();
        await page.addInitScript(() => {
            Object.defineProperty(navigator, 'webdriver', { get: () => false });
        });

        // ── 2. 导航到百家号首页，提取 auth ──
        console.log("[BaijiahaoAPI] Navigating to baijiahao.baidu.com...");
        await page.goto("https://baijiahao.baidu.com/", {
            waitUntil: "domcontentloaded",
            timeout: 20000,
        });
        await page.waitForTimeout(rand(2000, 4000));

        // 检测登录态
        const bodyText = await page.textContent("body");
        if (bodyText.includes("登录") && !bodyText.includes("创作")) {
            await browser.close();
            return { success: false, error: "Cookie expired — 请在运营助手重新授权百家号" };
        }

        // ── 3. 提取 edit-token ──
        const editToken = await page.evaluate(() => {
            const token = localStorage.getItem("edit-token");
            return token ? token.replace(/"/g, "") : "";
        });

        if (!editToken) {
            // edit-token 可能不在首页，尝试访问编辑页触发
            console.log("[BaijiahaoAPI] edit-token not on homepage, trying editor page...");
            await page.goto("https://baijiahao.baidu.com/builder/rc/edit", {
                waitUntil: "domcontentloaded",
                timeout: 15000,
            });
            await page.waitForTimeout(rand(2000, 4000));
            const editToken2 = await page.evaluate(() => {
                const token = localStorage.getItem("edit-token");
                return token ? token.replace(/"/g, "") : "";
            });
            if (!editToken2) {
                await browser.close();
                return { success: false, error: "无法提取 edit-token，请确认百家号已登录" };
            }
            console.log(`[BaijiahaoAPI] Got edit-token from editor page: ${editToken2.substring(0, 10)}...`);
            // ── 4. 在浏览器上下文中通过 API 发文章 ──
            const result = await postArticleInBrowser(page, editToken2, opts);
            if (!debug) await browser.close();
            return result;
        }

        console.log(`[BaijiahaoAPI] Got edit-token: ${editToken.substring(0, 10)}...`);
        const result = await postArticleInBrowser(page, editToken, opts);
        if (!debug) await browser.close();
        return result;

    } catch (err) {
        console.error(`[BaijiahaoAPI] Error: ${err.message}`);
        try { await browser?.close(); } catch {}
        return { success: false, error: err.message };
    }
}

/**
 * 在浏览器上下文中调百家号 API 发文章。
 * 必须在 page context 中执行，因为 cookies 是浏览器自动带的。
 */
async function postArticleInBrowser(page, editToken, opts) {
    const { title, content, digest, coverImage } = opts;

    return await page.evaluate(async (params) => {
        const { editToken, title, content, digest, coverImage } = params;

        // 截断标题到 30 字
        const safeTitle = (title || "").substring(0, 30);

        // 计算正文长度
        const div = document.createElement("div");
        div.innerHTML = content || "";
        const textLen = (div.textContent || "").length;

        // 构造 FormData
        const fd = new FormData();
        fd.append("type", "news");
        fd.append("title", safeTitle);
        fd.append("content", content || "");
        fd.append("abstract", digest || "");
        fd.append("len", String(textLen));
        fd.append("vertical_cover", "");
        fd.append("source", "upload");
        fd.append("cover_source", "upload");
        fd.append("cover_layout", "one");
        fd.append("is_auto_optimize_cover", "1");
        fd.append("abstract_from", "1");
        fd.append("source_reprinted_allow", "0");

        // 活动标识（百家号默认参数）
        fd.append("activity_list[0][id]", "ttv");
        fd.append("activity_list[0][is_checked]", "1");
        fd.append("activity_list[1][id]", "reward");
        fd.append("activity_list[1][is_checked]", "1");
        fd.append("activity_list[2][id]", "aigc_bjh_status");
        fd.append("activity_list[2][is_checked]", "0");

        const resp = await fetch(
            "https://baijiahao.baidu.com/pcui/article/save?callback=bjhdraft",
            {
                method: "POST",
                body: fd,
                credentials: "include",
                headers: { "Token": editToken },
            }
        );

        const text = await resp.text();
        // 提取 JSON（callback 包裹: bjhdraft({...})）
        let json;
        try {
            const m = text.match(/bjhdraft\((.+)\)/s);
            json = JSON.parse(m ? m[1] : text);
        } catch {
            return { success: false, error: "API response parse failed: " + text.substring(0, 200) };
        }

        if (json.errno === 0) {
            const articleId = json.ret?.id;
            const articleUrl = articleId
                ? `https://baijiahao.baidu.com/builder/rc/edit?type=news&article_id=${articleId}`
                : "";
            return {
                success: true,
                article_id: String(articleId || ""),
                article_url: articleUrl,
                is_draft: true,
            };
        }

        return {
            success: false,
            error: json.errmsg || json.message || "Unknown API error",
            errno: json.errno,
        };
    }, { editToken, title, content, digest, coverImage });
}

/**
 * 快速验证：检查百家号 cookie 是否有效，能否提取 edit-token。
 * 不发表文章，只验证认证状态。
 *
 * @param {string} workspaceId
 * @returns {Promise<{valid:boolean, cookieCount:number, hasEditToken:boolean, error?:string}>}
 */
export async function verifyAuth(workspaceId) {
    const stateFile = path.join(STORAGE_DIR, String(workspaceId), "baijiahao.json");
    if (!fs.existsSync(stateFile)) {
        return { valid: false, cookieCount: 0, hasEditToken: false, error: "No cookie state file" };
    }

    let browser;
    try {
        const state = JSON.parse(fs.readFileSync(stateFile, "utf-8"));
        const cookieCount = state.cookies?.length || 0;
        if (cookieCount < 5) {
            return { valid: false, cookieCount, hasEditToken: false, error: "Too few cookies" };
        }

        browser = await chromium.launch({
            channel: "msedge",
            headless: true,
            args: ["--no-sandbox", "--disable-dev-shm-usage", "--disable-gpu"],
            timeout: 20000,
        });

        const context = await browser.newContext({
            userAgent: randomUA(),
            viewport: { width: 1366, height: 768 },
            locale: "zh-CN",
            storageState: state,
        });

        const page = await context.newPage();
        await page.goto("https://baijiahao.baidu.com/", {
            waitUntil: "domcontentloaded",
            timeout: 15000,
        });
        await page.waitForTimeout(2000);

        const bodyText = await page.textContent("body");
        const isLoggedIn = !bodyText.includes("登录") || bodyText.includes("创作");

        let hasEditToken = false;
        if (isLoggedIn) {
            const token = await page.evaluate(() => {
                const t = localStorage.getItem("edit-token");
                return t ? t.replace(/"/g, "") : "";
            });
            hasEditToken = !!token;
        }

        await browser.close();
        return { valid: isLoggedIn && hasEditToken, cookieCount, hasEditToken };
    } catch (err) {
        try { await browser?.close(); } catch {}
        return { valid: false, cookieCount: 0, hasEditToken: false, error: err.message };
    }
}

// ── CLI 测试支持 ──
if (process.argv[1] && import.meta.url.endsWith(process.argv[1].replace(/\\/g, "/"))) {
    const wsId = process.argv[2] || "7";
    const testTitle = process.argv[3] || `Qonhub-API-Test-${Date.now()}`;
    const testContent = process.argv[4] || "<p>这是通过百家号API直发模块自动发布的测试文章。验证时间：" + new Date().toISOString() + "</p>";

    console.log(`\n=== 百家号 API 直发测试 ===`);
    console.log(`Workspace: ${wsId}`);
    console.log(`Title: ${testTitle}`);
    console.log(`Content: ${testContent.substring(0, 50)}...\n`);

    // 先验证认证
    const auth = await verifyAuth(wsId);
    console.log("Auth check:", JSON.stringify(auth, null, 2));

    if (!auth.valid) {
        console.log("\n❌ Auth invalid. 请先在运营助手中授权百家号登录。");
        process.exit(1);
    }

    // 发布
    console.log("\nPublishing...");
    const result = await publishViaApi({
        workspaceId: wsId,
        title: testTitle,
        content: testContent,
        digest: "API直发测试摘要",
        debug: true,  // 保留浏览器，方便检查
    });

    console.log("\nResult:", JSON.stringify(result, null, 2));
    if (result.success) {
        console.log(`\n✅ 发布成功！查看文章: ${result.article_url}`);
    } else {
        console.log(`\n❌ 发布失败: ${result.error}`);
    }
}
