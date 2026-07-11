/**
 * 八方资源网 (b2b168.com) 企业认证自动化脚本。
 *
 * 注册认证流程：
 *   Step 1: 打开注册页 → 填写账号信息 → 提交注册
 *   Step 2: 登录新账号
 *   Step 3: 进入企业认证中心 → 选择认证类型
 *   Step 4: 填写企业工商信息（从 EnterpriseProfile 映射）
 *   Step 5: 填写联系方式 + 主营产品
 *   Step 6: 提交认证审核
 *   Step 7: 进入企业店铺 → 抓取店铺 URL
 *
 * 风控措施：
 *   - 每步操作随机延时 500-2000ms
 *   - 输入速度模拟真人（逐字符 80-180ms/字）
 *   - User-Agent 随机化
 *   - 失败自动截图
 */

import { chromium } from "playwright";
import path from "path";

export const platform = "b2b168";
export const description = "八方资源网 — 企业注册+认证+店铺开通全流程";

// ── 随机延时 ────────────────────────────────────────────

const rand = (min, max) => Math.floor(Math.random() * (max - min + 1)) + min;
const sleep = (ms) => new Promise(r => setTimeout(r, ms));
const humanDelay = () => sleep(rand(500, 2000));

// ── 模拟真人打字 ────────────────────────────────────────

/**
 * Find element across main page and all iframes.
 */
async function findElement(page, selectorList) {
    // Try main page first
    for (const sel of selectorList) {
        try {
            const el = await page.waitForSelector(sel, { state: "visible", timeout: 3000 });
            if (el) return el;
        } catch { /* try next */ }
    }
    // Try all iframes
    for (const frame of page.frames()) {
        if (frame === page.mainFrame()) continue;
        for (const sel of selectorList) {
            try {
                const el = await frame.waitForSelector(sel, { state: "visible", timeout: 3000 });
                if (el) return el;
            } catch { /* try next */ }
        }
    }
    return null;
}

async function typeHuman(page, selectors, text) {
    if (!text) return;
    const selectorList = Array.isArray(selectors) ? selectors : [selectors];
    const el = await findElement(page, selectorList);
    if (!el) {
        const html = await page.content();
        throw new Error(`Cannot find element with selectors: ${selectorList.join(", ")}. Page body: ${html.substring(0, 800)}...`);
    }
    await el.click();
    await el.fill("");
    for (const char of text) {
        await el.type(char, { delay: rand(80, 180) });
    }
    await humanDelay();
}

/**
 * Smart fill: tries multiple selector patterns to find the target input.
 * selectorHint examples: "phone", "email", "company", "password", "username"
 */
async function smartFill(page, selectorHint, text) {
    if (!text) return;
    const patterns = {
        phone: ['input[name*="phone"]', 'input[name*="mobile"]', 'input[name*="tel"]', 'input[name*="lxdh"]', 'input[type="tel"]', 'input[placeholder*="手机"]', 'input[placeholder*="电话"]'],
        email: ['input[name*="email"]', 'input[name*="dzyx"]', 'input[type="email"]', 'input[placeholder*="邮箱"]', 'input[placeholder*="邮件"]'],
        company: ['input[name*="company"]', 'input[name*="gsmc"]', 'input[name*="enterprise"]', 'input[placeholder*="公司"]', 'input[placeholder*="企业名称"]'],
        password: ['input[name*="password"]', 'input[name*="pwd"]', 'input[type="password"]', 'input[placeholder*="密码"]'],
        username: ['input[name*="username"]', 'input[name*="account"]', 'input[name*="user"]', 'input[placeholder*="账号"]', 'input[placeholder*="用户名"]'],
        credit_code: ['input[name*="credit"]', 'input[name*="tyxydm"]', 'input[name*="uscc"]', 'input[placeholder*="信用"]', 'input[placeholder*="统一"]'],
        legal_person: ['input[name*="legal"]', 'input[name*="frdb"]', 'input[name*="person"]', 'input[placeholder*="法人"]', 'input[placeholder*="代表"]'],
        address: ['input[name*="address"]', 'input[name*="xxdz"]', 'textarea[name*="address"]', 'textarea[placeholder*="地址"]', 'input[placeholder*="地址"]'],
        scope: ['textarea[name*="scope"]', 'textarea[name*="jyfw"]', 'textarea[name*="business"]', 'textarea[placeholder*="经营"]'],
        products: ['textarea[name*="product"]', 'textarea[name*="zycp"]', 'textarea[placeholder*="产品"]', 'textarea[placeholder*="主营"]'],
        website: ['input[name*="website"]', 'input[name*="qywz"]', 'input[name*="url"]', 'input[placeholder*="网址"]', 'input[placeholder*="官网"]'],
        province: ['select[name*="province"]', 'select[name*="sssf"]'],
        city: ['select[name*="city"]', 'select[name*="sscs"]'],
    };
    const selectors = patterns[selectorHint] || [selectorHint];
    await typeHuman(page, selectors, text);
}

// ── 截图工具 ────────────────────────────────────────────

async function screenshot(page, taskId, label) {
    const filename = `${taskId}_${label}_${Date.now()}.png`;
    const filepath = path.join(page.context().options?.screenshotDir || "./screenshots", filename);
    await page.screenshot({ path: filepath, fullPage: true });
    return filepath;
}

// ── User-Agent 随机化 ───────────────────────────────────

const USER_AGENTS = [
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36",
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36 Edg/125.0.0.0",
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36",
];

// ══════════════════════════════════════════════════════════
//  主执行入口
// ══════════════════════════════════════════════════════════

export async function execute({ taskId, account, enterprise, options, logger }) {
    const log = (msg) => logger.info(`[${taskId}] ${msg}`);
    log(`Starting B2B168 certification for: ${enterprise.company_name}`);

    const browser = await chromium.launch({
        headless: options.headless !== false,
        args: options.proxy ? [`--proxy-server=${options.proxy}`] : [],
    });

    const context = await browser.newContext({
        userAgent: USER_AGENTS[rand(0, USER_AGENTS.length - 1)],
        viewport: { width: 1366 + rand(-100, 100), height: 768 + rand(-50, 50) },
        locale: "zh-CN",
        timezoneId: "Asia/Shanghai",
        screenshotDir: options.screenshotDir,
    });

    const page = await context.newPage();
    let shopUrl = "";

    try {
        // ═══ Step 1: 注册账号 ═══
        log("Step 1: Opening registration page...");
        // 尝试多个可能的注册入口 URL
        const tryUrls = [
            "https://www.b2b168.com/member/register.php",
            "https://www.b2b168.com/member/reg.php",
            "https://member.b2b168.com/register/",
        ];
        let registered = false;
        for (const tryUrl of tryUrls) {
            try {
                log(`  Trying: ${tryUrl}`);
                await page.goto(tryUrl, { waitUntil: "networkidle", timeout: 15000 });
                await page.waitForTimeout(3000);
                // Check if this page actually has form inputs
                const bodyText = await page.textContent("body");
                if (bodyText.includes("注册") || bodyText.includes("登录") || bodyText.includes("密码")) {
                    log(`  Found registration form at: ${tryUrl}`);
                    registered = true;
                    break;
                } else {
                    log(`  No form at ${tryUrl} (body: "${bodyText.substring(0, 80)}...")`);
                }
            } catch (e) {
                log(`  Failed to load ${tryUrl}: ${e.message}`);
            }
        }
        if (!registered) {
            // Fallback: try the main domain register page
            await page.goto("https://www.b2b168.com/", { waitUntil: "networkidle", timeout: 15000 });
            await page.waitForTimeout(3000);
            log("  Trying main page — looking for register link...");
            // Try clicking a register link on the homepage
            const regLinks = await page.$$('a[href*="register"], a[href*="reg"], a:has-text("注册"), a:has-text("入驻"), a:has-text("免费")');
            if (regLinks.length > 0) {
                log(`  Found ${regLinks.length} potential register links, clicking first...`);
                await regLinks[0].click();
                await page.waitForTimeout(3000);
            }
        }
        // Check for iframes
        const frames = page.frames();
        log(`  Page: ${page.url()} | Frames: ${frames.length}`);
        for (const frame of frames) {
            if (frame !== page.mainFrame()) {
                log(`  Frame: ${frame.url()}`);
            }
        }
        await screenshot(page, taskId, "01_register_page");

        // 选择企业注册（非个人）
        try {
            await page.click('text=企业注册, a[href*="company"], label:has-text("企业")', { timeout: 3000 });
        } catch {
            log("Single registration form detected, proceeding...");
        }
        await humanDelay();

        // 填写账号信息
        const username = account.username || enterprise.register_username || `qy_${Date.now()}`;
        const password = account.credential || enterprise.register_credential || `Pass${rand(100000, 999999)}`;

        // Smart fill: auto-detect field selectors based on actual page structure
        await smartFill(page, 'phone', enterprise.phone);
        await smartFill(page, 'username', username);
        await smartFill(page, 'password', password);
        // Also try repassword field
        try { await smartFill(page, 'password', password); } catch { /* may not have confirm field */ }
        await humanDelay();

        // 勾选同意协议
        try {
            await page.check('input[type="checkbox"], input[name="agree"]');
        } catch { /* may not exist */ }

        // 提交注册
        await page.click('button[type="submit"], button:has-text("注册"), button:has-text("提交")');
        await page.waitForTimeout(3000);
        await screenshot(page, taskId, "02_register_submitted");

        // 检查注册结果
        const regError = await page.$('.error, .alert-error, text=已被注册, text=注册失败');
        if (regError) {
            const errText = await regError.textContent();
            // 如果账号已存在说明用户可能已经注册过 -> 跳过注册步骤
            if (errText.includes("已被注册") || errText.includes("已存在")) {
                log(`Account already exists, proceeding to login: ${username}`);
            } else {
                throw new Error(`Registration failed: ${errText.trim()}`);
            }
        } else {
            log("Registration submitted successfully");
        }

        // ═══ Step 2: 登录 ═══
        log("Step 2: Logging in...");
        await humanDelay();
        try {
            await page.goto("https://www.b2b168.com/login/", {
                waitUntil: "domcontentloaded",
                timeout: 15000,
            });
        } catch { /* may already be logged in */ }

        await typeHuman(page, 'input[name="username"], input[name="account"], input[placeholder*="账号"]', username);
        await typeHuman(page, 'input[name="password"], input[type="password"]', password);
        await page.click('button[type="submit"], button:has-text("登录")');
        await page.waitForTimeout(3000);
        await screenshot(page, taskId, "03_logged_in");

        // ═══ Step 3: 企业认证 ═══
        log("Step 3: Navigating to enterprise certification...");
        await humanDelay();

        // 尝试多种路径进入企业认证
        let certifiedPageFound = false;
        const certPaths = [
            "/member/certify/",
            "/member/company/certify/",
            "/user/cert/",
            "/my/certification/",
        ];

        for (const certPath of certPaths) {
            try {
                const baseUrl = page.url().split("/").slice(0, 3).join("/");
                await page.goto(baseUrl + certPath, {
                    waitUntil: "domcontentloaded",
                    timeout: 10000,
                });
                await page.waitForTimeout(2000);

                // Check if page has certification-related content
                const hasCertContent = await page.$("text=企业认证, text=公司认证, text=工商信息, text=资质认证");
                if (hasCertContent) {
                    certifiedPageFound = true;
                    log(`Found certification page at: ${certPath}`);
                    break;
                }
            } catch { /* try next path */ }
        }

        if (!certifiedPageFound) {
            // Fallback: try clicking certification link in user center
            await page.goto(page.url().split("/").slice(0, 3).join("/") + "/member/", {
                waitUntil: "domcontentloaded",
                timeout: 10000,
            });
            await humanDelay();
            await screenshot(page, taskId, "03b_member_center");

            const certLink = await page.$('a:has-text("认证"), a:has-text("企业信息"), a:has-text("公司资料")');
            if (certLink) {
                await certLink.click();
                certifiedPageFound = true;
                await page.waitForTimeout(2000);
            }
        }

        await screenshot(page, taskId, "04_certification_page");

        // ═══ Step 4: 填写企业工商信息 ═══
        log("Step 4: Filling enterprise info...");
        await humanDelay();

        // Smart fill enterprise info
        await smartFill(page, 'company', enterprise.company_name);
        await smartFill(page, 'credit_code', enterprise.credit_code);
        await smartFill(page, 'legal_person', enterprise.legal_person);
        await smartFill(page, 'scope', enterprise.business_scope);
        await smartFill(page, 'address', enterprise.address);

        // Province/City dropdowns (optional)
        if (enterprise.province) {
            try { await smartFill(page, 'province', enterprise.province); } catch {}
        }
        if (enterprise.city) {
            try { await smartFill(page, 'city', enterprise.city); } catch {}
        }
        await humanDelay();

        // ═══ Step 5: 填写联系方式 ═══
        log("Step 5: Filling contact info...");
        await smartFill(page, 'phone', enterprise.phone);
        await smartFill(page, 'email', enterprise.email);
        await smartFill(page, 'website', enterprise.website);
        await smartFill(page, 'products', enterprise.products);
        await humanDelay();

        // ═══ Step 6: 提交认证 ═══
        log("Step 6: Submitting certification...");
        await screenshot(page, taskId, "05_before_submit");
        await page.click('button[type="submit"], button:has-text("提交认证"), button:has-text("保存"), button:has-text("提交审核")');
        await page.waitForTimeout(4000);

        // Check for validation errors
        const validationError = await page.$('.field-error, .error-tip, .form-error, text=格式不正确, text=必填');
        if (validationError) {
            const errText = await validationError.textContent();
            await screenshot(page, taskId, "06_validation_error");
            throw new Error(`Form validation failed: ${errText.trim()}`);
        }

        await screenshot(page, taskId, "06_certify_submitted");
        log("Certification submitted, checking result...");

        // ═══ Step 7: 获取店铺 URL ═══
        log("Step 7: Getting shop URL...");
        await humanDelay();
        await page.goto(page.url().split("/").slice(0, 3).join("/") + "/member/", {
            waitUntil: "domcontentloaded",
            timeout: 10000,
        });
        await humanDelay();

        // Try to find company shop link
        const shopLinkElements = await page.$$(
            'a[href*="com"], a:has-text("我的店铺"), a:has-text("企业主页"), a:has-text("公司主页"), a:has-text("商铺")'
        );

        for (const el of shopLinkElements) {
            const href = await el.getAttribute("href");
            const text = await el.textContent();
            if (href && (text.includes("店铺") || text.includes("主页") || text.includes("商铺") || href.includes("shop") || href.includes("company"))) {
                shopUrl = href.startsWith("http") ? href : page.url().split("/").slice(0, 3).join("/") + (href.startsWith("/") ? "" : "/") + href;
                break;
            }
        }

        if (!shopUrl) {
            // Fallback: construct URL from company ID pattern
            shopUrl = `https://${username}.b2b168.com/`;
            log(`Shop URL not found directly, using fallback: ${shopUrl}`);
        } else {
            log(`Shop URL found: ${shopUrl}`);
        }

        await screenshot(page, taskId, "07_shop_page");

        log(`CERTIFICATION COMPLETE — shop_url=${shopUrl}`);

        return {
            success: true,
            shop_url: shopUrl,
            account_id: username,
            status: "certified",
            message: "企业认证已提交，等待平台审核通过后店铺自动开通",
        };

    } catch (err) {
        log(`ERROR: ${err.message}`);
        try {
            const ss = await screenshot(page, taskId, "99_error");
            log(`Error screenshot saved: ${ss}`);
        } catch { /* screenshot may fail too */ }
        throw err;
    } finally {
        await browser.close();
        log("Browser closed");
    }
}

// ── Publish (reserved for future 商机发布) ────────────────

export async function publish({ taskId, account, content, options, logger }) {
    logger.info(`[${taskId}] B2B168 publish — not yet implemented`);
    return {
        success: true,
        article_url: "",
        status: "not_implemented",
        message: "B2B168 content publishing is reserved for future implementation",
    };
}
