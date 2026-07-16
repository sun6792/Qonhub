/**
 * 八方资源网 (b2b168.com) 企业注册认证脚本。
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
 * v2.6.1: 重构为 BasePlatformScript 子类，复用双层指纹伪装 + 行为模拟。
 */

import { BasePlatformScript, PUBLISH_STATUS } from "../lib/BasePlatformScript.js";

export const platform = "b2b168";
export const description = "八方资源网 — 企业注册+认证+店铺开通全流程";

class B2b168Script extends BasePlatformScript {
    registerUrl = "https://www.b2b168.com/member/register.php";
    certifyUrl = "https://www.b2b168.com/member/";

    async registerFlow(page) {
        const { username, password } = this.genAccount();
        const e = this.enterprise;

        // ═══ Step 1: 导航到注册页 ═══
        this.log("Step 1: Opening registration page...");
        const tryUrls = [
            "https://www.b2b168.com/member/register.php",
            "https://www.b2b168.com/member/reg.php",
            "https://member.b2b168.com/register/",
        ];
        let registered = false;
        for (const tryUrl of tryUrls) {
            try {
                this.log(`  Trying: ${tryUrl}`);
                await page.goto(tryUrl, { waitUntil: "networkidle", timeout: 15000 });
                await this.wait(2000, 4000);
                const bodyText = await page.textContent("body");
                if (bodyText.includes("注册") || bodyText.includes("登录") || bodyText.includes("密码")) {
                    this.log(`  Found registration form at: ${tryUrl}`);
                    registered = true;
                    break;
                }
            } catch (e) {
                this.log(`  Failed to load ${tryUrl}: ${e.message}`);
            }
        }
        if (!registered) {
            await page.goto("https://www.b2b168.com/", { waitUntil: "networkidle", timeout: 15000 });
            await this.wait(2000, 4000);
            const regEntry = await this.findElement(page, [
                'a[href*="register"]', 'a[href*="reg"]', 'a:has-text("注册")', 'a:has-text("入驻")', 'a:has-text("免费")',
            ]);
            if (regEntry) {
                await regEntry.click();
                await this.wait(2000, 4000);
            }
        }
        await this.ss(page, "01_register_page");

        // 选择企业注册
        try { await page.click('text=企业注册, a[href*="company"], label:has-text("企业")', { timeout: 3000 }); } catch {}
        await this.wait();

        // 填写账号信息
        await this.smartFill(page, 'phone', e.phone);
        await this.smartFill(page, 'username', username);
        await this.smartFill(page, 'password', password);
        try { await this.smartFill(page, 'password', password); } catch {}
        await this.wait();

        // 勾选同意协议
        try { await page.check('input[type="checkbox"], input[name="agree"]'); } catch {}

        // 提交注册
        await this.clickSubmit(page, "注册");
        await this.wait(2000, 4000);
        await this.ss(page, "02_register_submitted");

        // 检查注册结果
        const body2 = await page.textContent("body");
        if (body2.includes("已被注册") || body2.includes("已存在")) {
            this.log("Account already exists, proceeding to login");
        } else if (body2.includes("注册成功") || body2.includes("欢迎")) {
            this.log("Registration successful!");
        } else if (body2.includes("验证码") || body2.includes("短信") || body2.includes("captcha")) {
            this.log("Verification needed — screenshot captured");
            await this.ss(page, "02b_verification_needed");
        }

        // ═══ Step 2: 登录 ═══
        this.log("Step 2: Logging in...");
        await this.wait(2000, 4000);
        try {
            await page.goto("https://www.b2b168.com/login/", { waitUntil: "domcontentloaded", timeout: 15000 });
        } catch {}
        await this.typeHuman(page, ['input[name="username"]', 'input[name="account"]', 'input[placeholder*="账号"]'], username);
        await this.typeHuman(page, ['input[name="password"]', 'input[type="password"]'], password);
        await this.clickSubmit(page, "登录");
        await this.wait(2000, 4000);
        await this.ss(page, "03_logged_in");

        return { success: true, account_id: username, status: "registered" };
    }

    async certifyFlow(page) {
        const e = this.enterprise;

        // ═══ Step 3: 进入企业认证 ═══
        this.log("Step 3: Navigating to enterprise certification...");
        await this.wait();
        let certFound = false;
        const certPaths = ["/member/certify/", "/member/company/certify/", "/user/cert/", "/my/certification/"];
        for (const certPath of certPaths) {
            try {
                const baseUrl = page.url().split("/").slice(0, 3).join("/");
                await page.goto(baseUrl + certPath, { waitUntil: "domcontentloaded", timeout: 10000 });
                await this.wait(1500, 3000);
                const hasCert = await page.$("text=企业认证, text=公司认证, text=工商信息, text=资质认证");
                if (hasCert) { certFound = true; this.log(`Found at: ${certPath}`); break; }
            } catch {}
        }
        if (!certFound) {
            await page.goto(page.url().split("/").slice(0, 3).join("/") + "/member/", { waitUntil: "domcontentloaded", timeout: 10000 });
            await this.wait();
            await this.ss(page, "03b_member_center");
            const certLink = await page.$('a:has-text("认证"), a:has-text("企业信息"), a:has-text("公司资料")');
            if (certLink) { await certLink.click(); await this.wait(1500, 3000); certFound = true; }
        }
        await this.ss(page, "04_certification_page");

        // ═══ Step 4: 填写企业工商信息 ═══
        this.log("Step 4: Filling enterprise info...");
        await this.wait();
        await this.smartFill(page, 'company', e.company_name);
        await this.smartFill(page, 'credit_code', e.credit_code);
        await this.smartFill(page, 'legal_person', e.legal_person);
        await this.smartFill(page, 'scope', e.business_scope);
        await this.smartFill(page, 'address', e.address);
        if (e.province) { try { await this.smartFill(page, 'province', e.province); } catch {} }
        if (e.city) { try { await this.smartFill(page, 'city', e.city); } catch {} }
        await this.wait();

        // ═══ Step 5: 联系方式 ═══
        this.log("Step 5: Filling contact info...");
        await this.smartFill(page, 'phone', e.phone);
        await this.smartFill(page, 'email', e.email);
        await this.smartFill(page, 'website', e.website);
        await this.smartFill(page, 'products', e.products);
        await this.wait();

        // ═══ Step 6: 提交认证 ═══
        this.log("Step 6: Submitting certification...");
        await this.ss(page, "05_before_submit");
        await this.clickSubmit(page, "提交认证");
        await this.wait(3000, 5000);

        // 校验错误检查
        const validationError = await page.$('.field-error, .error-tip, .form-error, text=格式不正确, text=必填');
        if (validationError) {
            const errText = await validationError.textContent();
            await this.ss(page, "06_validation_error");
            throw new Error(`Form validation failed: ${errText.trim()}`);
        }
        await this.ss(page, "06_certify_submitted");
        this.log("Certification submitted!");

        // ═══ Step 7: 获取店铺 URL ═══
        this.log("Step 7: Getting shop URL...");
        await this.wait();
        await page.goto(page.url().split("/").slice(0, 3).join("/") + "/member/", { waitUntil: "domcontentloaded", timeout: 10000 });
        await this.wait();

        let shopUrl = "";
        const shopLinkElements = await page.$$('a[href*="com"], a:has-text("我的店铺"), a:has-text("企业主页"), a:has-text("公司主页"), a:has-text("商铺")');
        for (const el of shopLinkElements) {
            const href = await el.getAttribute("href");
            const text = await el.textContent();
            if (href && (text.includes("店铺") || text.includes("主页") || text.includes("商铺") || href.includes("shop") || href.includes("company"))) {
                shopUrl = href.startsWith("http") ? href : page.url().split("/").slice(0, 3).join("/") + (href.startsWith("/") ? "" : "/") + href;
                break;
            }
        }
        if (!shopUrl) {
            const { username } = this.genAccount();
            shopUrl = `https://${username}.b2b168.com/`;
            this.log(`Shop URL fallback: ${shopUrl}`);
        } else {
            this.log(`Shop URL: ${shopUrl}`);
        }
        await this.ss(page, "07_shop_page");

        return { success: true, shop_url: shopUrl, status: "certified" };
    }
}

export async function execute(ctx) {
    const script = new B2b168Script(ctx.taskId, ctx.account, ctx.enterprise, ctx.options, ctx.logger.info.bind(ctx.logger));
    return script.execute();
}

export async function publish(ctx) {
    ctx.logger.info(`[${ctx.taskId}] B2B168 publish — reserved for future`);
    return { success: true, article_url: "", status: "not_implemented" };
}
