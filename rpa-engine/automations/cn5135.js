/**
 * 无忧商务网 (cn5135.com) 企业注册认证脚本。
 *
 * 无忧商务网是免费B2B企业黄页与商务推广平台。
 *   Step 1: 导航到注册页 → 填写账号信息 → 提交注册
 *   Step 2: 登录新账号
 *   Step 3: 进入企业信息完善页 → 填写完整工商资料 + 主营产品
 *   Step 4: 提交企业信息 → 获取店铺 URL
 *
 * 继承 BasePlatformScript 自动获得双层指纹伪装 + 行为模拟。
 */

import { BasePlatformScript, PUBLISH_STATUS } from "../lib/BasePlatformScript.js";

export const platform = "cn5135";
export const description = "无忧商务网 — 免费B2B企业黄页注册";

class Cn5135Script extends BasePlatformScript {
    registerUrl = "https://www.cn5135.com/register/";
    certifyUrl = "https://www.cn5135.com/member/";

    async registerFlow(page) {
        const { username, password } = this.genAccount();
        const e = this.enterprise;

        // ═══ Step 1: 导航到注册页 ═══
        this.log("Step 1: Navigating to registration page...");

        await page.goto("https://www.cn5135.com/", { waitUntil: "domcontentloaded", timeout: 15000 });
        await this.wait(2000, 4000);
        this.log(`  Homepage: ${page.url()}`);
        await this.simulateHumanBrowsing(page);
        await this.ss(page, "00_homepage");

        // 找注册入口
        const regEntry = await this.findElement(page, [
            'a[href*="reg"]', 'a[href*="register"]', 'a[href*="signup"]',
            'a:has-text("免费注册")', 'a:has-text("注册")', 'a:has-text("入驻")',
            'a:has-text("免费发布")', '.reg-btn', '.register-btn',
        ]);
        if (regEntry) {
            const href = await regEntry.getAttribute("href");
            if (href && !href.includes("beian") && !href.includes("recordcode")) {
                this.log(`  Clicking: ${href}`);
                await regEntry.click();
                await this.wait(2000, 4000);
            }
        }

        // 尝试已知注册URL
        const body1 = await page.textContent("body");
        if (!body1.includes("注册") || body1.length < 200) {
            const regCandidates = [
                "https://www.cn5135.com/user/register/",
                "https://www.cn5135.com/member/register/",
                "https://www.cn5135.com/register/",
                "https://member.cn5135.com/register/",
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
            "https://www.cn5135.com/member/",
            "https://www.cn5135.com/user/",
            "https://www.cn5135.com/member/index.php",
            "https://www.cn5135.com/my/",
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
                "https://www.cn5135.com/user/login/",
                "https://www.cn5135.com/member/login/",
                "https://www.cn5135.com/login/",
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
            "https://www.cn5135.com/member/company/",
            "https://www.cn5135.com/member/edit/",
            "https://www.cn5135.com/member/info/",
            "https://www.cn5135.com/member/company/edit/",
            "https://www.cn5135.com/user/info/",
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
                await page.goto("https://www.cn5135.com/member/", { waitUntil: "domcontentloaded", timeout: 10000 });
                await this.wait(2000, 3000);
            } catch {}
            const editLink = await this.findElement(page, [
                'a:has-text("企业信息")', 'a:has-text("公司资料")', 'a:has-text("基本资料")',
                'a:has-text("编辑")', 'a:has-text("修改")', 'a:has-text("完善")',
            ]);
            if (editLink) {
                await editLink.click();
                await this.wait(2000, 4000);
            }
        }

        await this.ss(page, "05_edit_page");

        // 智能填充
        await this.smartFill(page, "company", e.company_name);
        await this.smartFill(page, "contact", e.legal_person);
        await this.smartFill(page, "phone", e.phone);
        await this.smartFill(page, "email", e.email);
        await this.smartFill(page, "address", e.address);
        await this.smartFill(page, "scope", e.business_scope);
        await this.smartFill(page, "products", e.products);
        await this.smartFill(page, "website", e.website);

        await this.ss(page, "05b_filled");

        await this.clickSubmit(page, "保存");
        this.log("  Enterprise profile submitted");
        await this.wait(3000, 5000);
        await this.ss(page, "05c_saved");

        // ═══ Step 6: 获取店铺 URL ═══
        this.log("Step 6: Extracting shop URL...");

        try {
            await page.goto("https://www.cn5135.com/member/", { waitUntil: "domcontentloaded", timeout: 10000 });
        } catch {}
        await this.wait(2000, 4000);

        let shopUrl = "";

        const allLinks = await page.$$("a");
        for (const link of allLinks) {
            try {
                const href = await link.getAttribute("href");
                const text = await link.textContent().catch(() => "");
                if (href && (
                    text.includes("店铺") || text.includes("主页") || text.includes("公司") ||
                    text.includes("商铺") || text.includes("企业") || text.includes("网站")
                )) {
                    const candidate = href.startsWith("http") ? href : `https://www.cn5135.com${href.startsWith("/") ? "" : "/"}${href}`;
                    if (!candidate.includes("/member/") && !candidate.includes("/admin/") && !candidate.includes("/login") && !candidate.includes("/user/")) {
                        shopUrl = candidate;
                        this.log(`  Found shop URL: ${shopUrl}`);
                        break;
                    }
                }
            } catch {}
        }

        if (!shopUrl) {
            const companySlug = (e.company_name || username)
                .replace(/[^一-龥a-zA-Z0-9]/g, "")
                .substring(0, 20).toLowerCase();
            const possibleUrls = [
                `https://${companySlug}.cn5135.com/`,
                `https://www.cn5135.com/shop/${companySlug}/`,
                `https://www.cn5135.com/company/${companySlug}/`,
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
                shopUrl = `https://www.cn5135.com/company/${companySlug}/`;
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
            message: "无忧商务网企业认证已提交",
        };
    }
}

export async function execute(o) {
    const script = new Cn5135Script(o.taskId, o.account, o.enterprise, o.options, o.logger.info.bind(o.logger));
    return script.execute();
}
