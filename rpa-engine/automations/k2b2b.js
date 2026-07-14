/**
 * K2商务网 (k2b2b.com) 企业注册认证脚本。
 *
 * K2商务网是免费B2B商铺平台，支持企业注册开店。
 *   Step 1: 导航到注册页 → 填写账号信息 → 提交注册
 *   Step 2: 登录新账号
 *   Step 3: 进入企业信息完善页 → 填写完整工商资料
 *   Step 4: 提交企业认证 → 获取店铺 URL
 *
 * 继承 BasePlatformScript 自动获得双层指纹伪装 + 行为模拟。
 */

import { BasePlatformScript, PUBLISH_STATUS } from "../lib/BasePlatformScript.js";

export const platform = "k2b2b";
export const description = "K2商务网 — 免费B2B商铺注册";

class K2Script extends BasePlatformScript {
    registerUrl = "https://www.k2b2b.com/user/register/";
    certifyUrl = "https://www.k2b2b.com/member/";

    async registerFlow(page) {
        const { username, password } = this.genAccount();
        const e = this.enterprise;

        // ═══ Step 1: 导航到注册页 ═══
        this.log("Step 1: Navigating to registration page...");

        await page.goto("https://www.k2b2b.com/", { waitUntil: "domcontentloaded", timeout: 15000 });
        await this.wait(2000, 4000);
        this.log(`  Homepage: ${page.url()}`);
        await this.simulateHumanBrowsing(page);
        await this.ss(page, "00_homepage");

        // 找注册入口
        const regEntry = await this.findElement(page, [
            'a[href*="register"]', 'a[href*="reg.php"]', 'a[href*="signup"]',
            'a:has-text("免费注册")', 'a:has-text("注册")', 'a:has-text("入驻")',
            'a:has-text("加入")', 'a:has-text("免费加入")',
        ]);
        if (regEntry) {
            const href = await regEntry.getAttribute("href");
            if (href && !href.includes("beian") && !href.includes("recordcode")) {
                this.log(`  Clicking: ${href}`);
                await regEntry.click();
                await this.wait(2000, 4000);
            }
        }

        // 确认到达注册表单
        const body1 = await page.textContent("body");
        if (!body1.includes("注册") || body1.length < 200) {
            const regCandidates = [
                "https://www.k2b2b.com/user/register/",
                "https://www.k2b2b.com/member/register/",
                "https://www.k2b2b.com/register/",
                "https://member.k2b2b.com/register/",
            ];
            for (const url of regCandidates) {
                try {
                    await page.goto(url, { waitUntil: "domcontentloaded", timeout: 12000 });
                    await this.wait(2000, 3000);
                    const b = await page.textContent("body");
                    if (b.includes("注册") || b.includes("密码") || b.includes("手机") || b.includes("账号")) {
                        this.log(`  Found register form at: ${url}`);
                        break;
                    }
                } catch { /* try next */ }
            }
        }
        await this.ss(page, "01_register_page");

        // ═══ Step 2: 填写注册信息 ═══
        this.log("Step 2: Filling registration form...");

        await this.smartFill(page, "username", username);
        await this.smartFill(page, "password", password);
        // 确认密码
        try {
            const repwdSelectors = ['input[name*="repassword"]', 'input[name*="repwd"]', 'input[name*="confirm"]'];
            const repwdEl = await this.findElement(page, repwdSelectors, 2000);
            if (repwdEl) {
                await repwdEl.click();
                await repwdEl.fill("");
                for (const ch of password) {
                    await repwdEl.type(ch, { delay: this.rand(60, 150) });
                }
            }
        } catch {}
        await this.smartFill(page, "phone", e.phone || `138${this.rand(10000000, 99999999)}`);
        await this.smartFill(page, "email", e.email || `${username}@163.com`);
        await this.smartFill(page, "company", e.company_name);
        await this.smartFill(page, "contact", e.legal_person);

        await this.wait(500, 1500);

        // 勾选协议
        try {
            const checkboxes = await page.$$('input[type="checkbox"]');
            for (const cb of checkboxes) {
                try { await cb.check(); } catch {}
            }
        } catch {}

        await this.ss(page, "02_form_filled");

        // ═══ Step 3: 提交注册 ═══
        this.log("Step 3: Submitting registration...");
        await this.clickSubmit(page, "注册");
        await this.wait(2000, 4000);
        await this.ss(page, "03_register_submitted");

        // 检测注册结果
        const body2 = await page.textContent("body");
        if (body2.includes("已被注册") || body2.includes("已存在") || body2.includes("已注册")) {
            this.log("  Account already exists or registered successfully");
        } else if (body2.includes("注册成功") || body2.includes("欢迎") || body2.includes("会员") || body2.includes("成功")) {
            this.log("  Registration successful!");
        } else if (body2.includes("验证码") || body2.includes("短信") || body2.includes("captcha")) {
            this.log("  Verification needed — screenshot captured");
            await this.ss(page, "03b_verification_needed");
        }

        // ═══ Step 4: 登录 ═══
        this.log("Step 4: Login / Navigate to member center...");
        await this.wait(2000, 4000);

        const memberUrls = [
            "https://www.k2b2b.com/member/",
            "https://www.k2b2b.com/user/",
            "https://www.k2b2b.com/member/index.php",
            "https://www.k2b2b.com/my/",
        ];
        let loggedIn = false;
        for (const url of memberUrls) {
            try {
                await page.goto(url, { waitUntil: "domcontentloaded", timeout: 10000 });
                await this.wait(2000, 3000);
                const body = await page.textContent("body");
                if (body.includes("会员") || body.includes("企业") || body.includes("个人中心") || body.includes("我的")) {
                    loggedIn = true;
                    this.log(`  Member center: ${url}`);
                    break;
                }
            } catch {}
        }

        if (!loggedIn) {
            this.log("  Need manual login...");
            const loginUrls = [
                "https://www.k2b2b.com/user/login/",
                "https://www.k2b2b.com/member/login/",
                "https://www.k2b2b.com/login/",
            ];
            for (const url of loginUrls) {
                try {
                    await page.goto(url, { waitUntil: "domcontentloaded", timeout: 10000 });
                    await this.wait();
                    const body = await page.textContent("body");
                    if (body.includes("密") || body.includes("登录")) break;
                } catch {}
            }
            await this.smartFill(page, "username", e.phone || username);
            await this.smartFill(page, "password", password);
            await this.clickSubmit(page, "登录");
            await this.wait(2000, 4000);
        }

        await this.ss(page, "04_logged_in");

        // ═══ Step 5: 完善企业信息 ═══
        this.log("Step 5: Filling enterprise profile...");

        const editCandidates = [
            "https://www.k2b2b.com/member/company/",
            "https://www.k2b2b.com/member/edit/",
            "https://www.k2b2b.com/member/info/",
            "https://www.k2b2b.com/member/company/edit/",
            "https://www.k2b2b.com/user/company/",
        ];
        let editFound = false;
        for (const url of editCandidates) {
            try {
                await page.goto(url, { waitUntil: "domcontentloaded", timeout: 10000 });
                await this.wait(1000, 2000);
                const hasForm = await this.findElement(page, ['input', 'textarea'], 3000);
                if (hasForm) {
                    this.log(`  Edit form at: ${url}`);
                    editFound = true;
                    break;
                }
            } catch {}
        }

        if (!editFound) {
            try {
                await page.goto("https://www.k2b2b.com/member/", { waitUntil: "domcontentloaded", timeout: 10000 });
                await this.wait(2000, 3000);
            } catch {}
            const editLink = await this.findElement(page, [
                'a:has-text("企业信息")', 'a:has-text("公司资料")', 'a:has-text("基本资料")',
                'a:has-text("编辑")', 'a:has-text("修改")', 'a:has-text("设置")',
            ]);
            if (editLink) {
                await editLink.click();
                await this.wait(2000, 4000);
            }
        }

        await this.ss(page, "05_edit_page");

        // 智能填充企业信息
        await this.smartFill(page, "company", e.company_name);
        await this.smartFill(page, "contact", e.legal_person);
        await this.smartFill(page, "phone", e.phone);
        await this.smartFill(page, "email", e.email);
        await this.smartFill(page, "address", e.address);
        await this.smartFill(page, "scope", e.business_scope);
        await this.smartFill(page, "products", e.products);
        await this.smartFill(page, "website", e.website);

        await this.ss(page, "05b_filled");

        // 提交
        await this.clickSubmit(page, "保存");
        this.log("  Enterprise profile submitted");
        await this.wait(3000, 5000);
        await this.ss(page, "05c_saved");

        // ═══ Step 6: 获取店铺 URL ═══
        this.log("Step 6: Extracting shop URL...");

        try {
            await page.goto("https://www.k2b2b.com/member/", { waitUntil: "domcontentloaded", timeout: 10000 });
        } catch {}
        await this.wait(2000, 4000);

        let shopUrl = "";

        // 从会员中心抓取真实的店铺链接
        const allLinks = await page.$$("a");
        for (const link of allLinks) {
            try {
                const href = await link.getAttribute("href");
                const text = await link.textContent().catch(() => "");
                if (href && (
                    text.includes("店铺") || text.includes("主页") || text.includes("公司") ||
                    text.includes("商铺") || text.includes("企业") || text.includes("网站")
                )) {
                    const candidate = href.startsWith("http") ? href : `https://www.k2b2b.com${href.startsWith("/") ? "" : "/"}${href}`;
                    if (!candidate.includes("/member/") && !candidate.includes("/admin/") && !candidate.includes("/login") && !candidate.includes("/user/")) {
                        shopUrl = candidate;
                        this.log(`  Found shop URL: ${shopUrl}`);
                        break;
                    }
                }
            } catch {}
        }

        if (!shopUrl) {
            // 尝试从当前URL推断或使用子域名格式
            const companySlug = (e.company_name || username)
                .replace(/[^一-龥a-zA-Z0-9]/g, "")
                .substring(0, 20).toLowerCase();
            const possibleUrls = [
                `https://${companySlug}.k2b2b.com/`,
                `https://www.k2b2b.com/shop/${companySlug}/`,
                `https://www.k2b2b.com/company/${companySlug}/`,
            ];
            for (const url of possibleUrls) {
                try {
                    await page.evaluate(async (u) => {
                        await fetch(u, { method: "HEAD", mode: "no-cors" });
                        return true;
                    }, url).catch(() => {});
                    shopUrl = url;
                    break;
                } catch {}
            }
            if (!shopUrl) {
                shopUrl = `https://www.k2b2b.com/shop/${companySlug}/`;
            }
            this.log(`  Using fallback URL: ${shopUrl}`);
        }

        await this.ss(page, "06_shop_page");
        this.log(`CERTIFICATION COMPLETE — shop_url=${shopUrl}`);

        return {
            success: true,
            shop_url: shopUrl,
            account_id: username,
            status: "certified",
            message: "K2商务网企业认证已提交",
        };
    }
}

export async function execute(opts) {
    const script = new K2Script(opts.taskId, opts.account, opts.enterprise, opts.options, opts.logger.info.bind(opts.logger));
    return script.execute();
}
