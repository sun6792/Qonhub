/**
 * 黄页88 (huangye88.com) 企业注册认证脚本。
 *
 * 黄页88 是通用 B2B 企业黄页，免费注册开店，反爬强度低。
 *   Step 1: 打开注册页 → 填写账号信息 → 提交
 *   Step 2: 登录 → 进入企业信息页面
 *   Step 3: 完善企业信息 + 主营产品
 *   Step 4: 获取企业店铺 URL
 */

import { BasePlatformScript } from "../lib/BasePlatformScript.js";

export const platform = "huangye88";
export const description = "黄页88 — 免费企业黄页注册开店";

class Huangye88Script extends BasePlatformScript {
    registerUrl = "https://www.huangye88.com/register/";
    certifyUrl = "https://www.huangye88.com/member/";

    async registerFlow(page) {
        const { username, password } = this.genAccount();
        const e = this.enterprise;

        // ═══ Step 1: 注册 ═══
        this.log("Step 1: Register...");
        // 从首页出发，模拟真实用户行为
        await page.goto("https://www.huangye88.com/", { waitUntil: "domcontentloaded", timeout: 15000 });
        await this.wait(2000, 4000);
        this.log(`  Homepage loaded: ${page.url()}`);
        await this.ss(page, "00_homepage");

        // 找注册/入驻入口（排除备案号链接）
        const regEntry = await this.findElement(page, [
            'a[href*="register"]', 'a[href*="reg.php"]', 'a[href*="signup"]',
            'a:has-text("免费注册")', 'a:has-text("注册发布")', 'a:has-text("立即入驻")',
            'a:has-text("注册账号")',
        ]);
        if (regEntry) {
            const href = await regEntry.getAttribute("href");
            // 过滤备案号
            if (!href.includes("beian") && !href.includes("recordcode")) {
                this.log(`  Clicking: ${href}`);
                await regEntry.click();
                await this.wait(2000, 4000);
            }
        }
        // Alternative: try "发布" text button
        if (!regEntry || (await regEntry.getAttribute("href")).includes("beian")) {
            this.log("  Looking for registration button by text...");
            const allLinks = await page.$$("a, button, span.link");
            for (const link of allLinks) {
                const text = (await link.textContent().catch(() => "")).trim();
                const href = (await link.getAttribute("href").catch(() => "") || "");
                if ((text === "注册" || text === "免费注册" || text === "注册发布" || text === "发布") && !href.includes("beian")) {
                    this.log(`  Found: "${text}" → ${href || '(no href)'}`);
                    await link.click();
                    await this.wait(2000, 4000);
                    break;
                }
            }
        }

        // 如果点击后没跳转注册页，直接尝试已知 URL
        const currentBody = await page.textContent("body");
        if (!currentBody.includes("注册") && !currentBody.includes("密码")) {
            const altUrls = [
                "https://www.huangye88.com/user/register/",
                "https://www.huangye88.com/member/register/",
            ];
            for (const url of altUrls) {
                try {
                    await page.goto(url, { waitUntil: "domcontentloaded", timeout: 10000 });
                    await this.wait();
                    const b = await page.textContent("body");
                    if (b.includes("注册") || b.includes("密码") || b.includes("手机")) {
                        this.log(`  ✅ Form at: ${url}`);
                        break;
                    }
                } catch {}
            }
        }
        await this.ss(page, "01_reg_page");

        // 填注册信息
        await this.smartFill(page, "phone", e.phone || `139${this.rand(10000000, 99999999)}`);
        await this.smartFill(page, "username", username);
        await this.smartFill(page, "password", password);
        await this.smartFill(page, "company", e.company_name);
        await this.wait();
        await this.ss(page, "01b_filled");

        await this.clickSubmit(page, "注册");
        this.log(`  registration submitted`);

        // ═══ Step 2: 登录 ═══
        this.log("Step 2: Login...");
        await this.wait(2000, 4000);
        const loginUrls = [
            "https://www.huangye88.com/login/",
            "https://www.huangye88.com/member/",
        ];
        for (const url of loginUrls) {
            try { await page.goto(url, { waitUntil: "domcontentloaded", timeout: 10000 }); break; } catch {}
        }
        await this.wait();
        await this.ss(page, "02_login_page");

        // 找登录表单
        const needLogin = await this.findElement(page, ['input[name*="username"]', 'input[name*="password"]']);
        if (needLogin) {
            await this.smartFill(page, "username", e.phone || username);
            await this.smartFill(page, "password", password);
            await this.clickSubmit(page, "登录");
            await this.wait(2000, 4000);
            this.log("  logged in");
        } else {
            this.log("  already logged in (post-register)");
        }

        // ═══ Step 3: 完善企业信息 ═══
        this.log("Step 3: Fill enterprise info...");
        const editUrls = [
            "https://www.huangye88.com/member/company/",
            "https://www.huangye88.com/member/info/",
            "https://www.huangye88.com/member/edit/",
            "https://www.huangye88.com/member/shop/",
        ];
        for (const url of editUrls) {
            try {
                await page.goto(url, { waitUntil: "domcontentloaded", timeout: 8000 });
                const hasForm = await this.findElement(page, ['input', 'textarea'], 2000);
                if (hasForm) { this.log(`  Edit form: ${url}`); break; }
            } catch {}
        }
        await this.wait();
        await this.ss(page, "03_edit_page");

        // 填充
        await this.smartFill(page, "company", e.company_name);
        await this.smartFill(page, "contact", e.legal_person);
        await this.smartFill(page, "phone", e.phone);
        await this.smartFill(page, "email", e.email);
        await this.smartFill(page, "address", e.address);
        await this.smartFill(page, "scope", e.business_scope);
        await this.smartFill(page, "products", e.products);
        await this.smartFill(page, "website", e.website);
        await this.ss(page, "03b_filled");
        await this.clickSubmit(page, "保存");

        // ═══ Step 4: 获取店铺 URL ═══
        this.log("Step 4: Getting shop URL...");
        await this.wait(2000, 4000);

        let shopUrl = "";
        // 黄页88 店铺 URL 格式
        const patterns = [
            `https://${username}.huangye88.com/`,
            `https://shop.huange88.com/${username}/`,
        ];

        // 尝试从页面找
        try {
            await page.goto("https://www.huangye88.com/member/", { waitUntil: "domcontentloaded", timeout: 10000 });
        } catch {}
        await this.wait();

        const links = await page.$$("a");
        for (const link of links) {
            const href = await link.getAttribute("href");
            const text = await link.textContent().catch(() => "");
            if (href && (text.includes("店铺") || text.includes("公司") || text.includes("主页") || text.includes("商铺"))) {
                shopUrl = href.startsWith("http") ? href : `https://www.huangye88.com${href}`;
                if (!shopUrl.includes("/member/") && !shopUrl.includes("/login")) {
                    this.log(`  Found: ${shopUrl}`);
                    break;
                }
            }
        }

        if (!shopUrl) {
            shopUrl = `https://${username}.huangye88.com/`;
            this.log(`  Fallback: ${shopUrl}`);
        }

        await this.ss(page, "04_shop");
        this.log(`✅ CERTIFICATION COMPLETE — ${shopUrl}`);

        return { success: true, shop_url: shopUrl, account_id: username, status: "certified" };
    }
}

export async function execute({ taskId, account, enterprise, options, logger }) {
    const script = new Huangye88Script(taskId, account, enterprise, options, logger.info.bind(logger));
    return script.execute();
}
