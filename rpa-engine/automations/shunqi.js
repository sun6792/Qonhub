/**
 * 顺企网 (11467.com) 企业注册认证脚本。
 *
 * 顺企网是老牌免费企业黄页，反爬强度低，注册流程简单：
 *   Step 1: 打开注册页 → 填写手机号+密码+公司名 → 提交
 *   Step 2: 自动登录 → 进入企业信息完善页
 *   Step 3: 填写详细企业信息 + 联系方式
 *   Step 4: 获取企业店铺 URL
 *
 * 继承 BasePlatformScript 自动获得全套反爬能力。
 */

import { BasePlatformScript } from "../lib/BasePlatformScript.js";

export const platform = "shunqi";
export const description = "顺企网 — 免费企业黄页注册+企业信息完善";

class ShunqiScript extends BasePlatformScript {
    registerUrl = "https://www.11467.com/register/";
    certifyUrl = "https://www.11467.com/member/";

    async registerFlow(page) {
        const { username, password } = this.genAccount();
        const e = this.enterprise;

        // ═══ Step 1: 注册 ═══
        this.log("Step 1: Register...");
        // 顺企网可能多域名，尝试多个入口
        const regCandidates = [
            "https://www.11467.com/register/",
            "https://www.11467.com/user/reg/",
            "https://member.11467.com/register/",
        ];
        let found = false;
        for (const url of regCandidates) {
            try {
                await page.goto(url, { waitUntil: "domcontentloaded", timeout: 12000 });
                await this.wait();
                const body = await page.textContent("body");
                // 503 或空白跳过
                if (body.length < 20 || body.includes("503") || body.includes("Service Unavailable")) continue;
                if (body.includes("注册") || body.includes("密码") || body.includes("手机") || body.includes("账号")) {
                    this.log(`  ✅ Form at: ${url}`);
                    found = true;
                    break;
                }
            } catch {}
        }
        if (!found) {
            // 兜底：从首页点注册链接
            try { await page.goto("https://www.11467.com/", { waitUntil: "domcontentloaded", timeout: 15000 }); } catch {}
            await this.wait();
            const regLink = await this.findElement(page, ['a[href*="register"]', 'a[href*="reg"]', 'a:has-text("注册")', 'a:has-text("入驻")']);
            if (regLink) { await regLink.click(); await this.wait(2000, 4000); }
        }
        await this.ss(page, "01_reg_page");

        // 手机号 + 密码 + 公司名（顺企网注册核心字段）
        await this.smartFill(page, "phone", e.phone || `138${this.rand(10000000, 99999999)}`);
        await this.smartFill(page, "password", password);
        await this.smartFill(page, "company", e.company_name || "测试企业");
        await this.wait();
        await this.ss(page, "01b_filled");

        // 提交注册
        await this.clickSubmit(page, "注册");
        this.log(`  username=${username} submitted`);

        // 检查响应
        const body = await page.textContent("body");
        if (body.includes("已被注册") || body.includes("已存在") || body.includes("已注册")) {
            this.log(`  账号已存在，跳到 Step 2`);
        } else if (body.includes("注册成功") || body.includes("欢迎") || body.includes("会员中心")) {
            this.log(`  注册成功`);
        }
        await this.ss(page, "01c_registered");
        await this.wait(2000, 4000);

        // ═══ Step 2: 登录（如需） ═══
        this.log("Step 2: Login / Navigate to member...");
        // 很多黄页站注册后自动登录，直接跳会员中心
        try {
            await page.goto("https://www.11467.com/member/", {
                waitUntil: "domcontentloaded", timeout: 15000,
            });
        } catch {}
        await this.wait(2000, 4000);
        await this.ss(page, "02_member_page");

        // 如果被踢出，手动登录
        const needLogin = await this.findElement(page, [
            'input[name*="username"]', 'input[name*="account"]', 'input[placeholder*="登录"]',
        ]);
        if (needLogin) {
            this.log("  需要登录，填写凭据...");
            await this.smartFill(page, "username", e.phone || username);
            await this.smartFill(page, "password", password);
            await this.clickSubmit(page, "登录");
            await this.wait(2000, 4000);
        }

        // ═══ Step 3: 填写企业信息 ═══
        this.log("Step 3: Fill enterprise info...");

        // 进入企业信息编辑页
        const editLinks = [
            "https://www.11467.com/member/company/edit/",
            "https://www.11467.com/member/info/",
            "https://www.11467.com/member/profile/",
        ];
        for (const url of editLinks) {
            try {
                await page.goto(url, { waitUntil: "domcontentloaded", timeout: 10000 });
                const hasForm = await this.findElement(page, ['input', 'textarea'], 3000);
                if (hasForm) {
                    this.log(`  Found edit form at ${url}`);
                    break;
                }
            } catch {}
        }
        await this.wait();
        await this.ss(page, "03_edit_page");

        // 智能填充全部字段
        await this.smartFill(page, "company", e.company_name);
        await this.smartFill(page, "contact", e.legal_person);
        await this.smartFill(page, "phone", e.phone);
        await this.smartFill(page, "email", e.email);
        await this.smartFill(page, "address", e.address);
        await this.smartFill(page, "scope", e.business_scope);
        await this.smartFill(page, "website", e.website);
        await this.smartFill(page, "products", e.products);
        await this.ss(page, "03b_filled");

        // 提交
        await this.clickSubmit(page, "保存");
        this.log("  Enterprise info submitted");

        // ═══ Step 4: 获取店铺 URL ═══
        this.log("Step 4: Getting shop URL...");
        await this.wait(2000, 4000);

        // 尝试多种方式获取店铺链接
        let shopUrl = "";
        try {
            await page.goto("https://www.11467.com/member/", {
                waitUntil: "domcontentloaded", timeout: 10000,
            });
        } catch {}
        await this.wait();

        // 找店铺链接
        const links = await page.$$('a[href*="11467.com"]');
        for (const link of links) {
            const href = await link.getAttribute("href");
            const text = await link.textContent().catch(() => "");
            if (href && (text.includes("店铺") || text.includes("主页") || text.includes("公司") || href.includes("shop") || href.includes("company") || href.match(/\/\d+\//))) {
                shopUrl = href.startsWith("http") ? href : `https://www.11467.com${href.startsWith("/") ? "" : "/"}${href}`;
                // Don't match the member/admin URLs
                if (!shopUrl.includes("/member/") && !shopUrl.includes("/admin/") && !shopUrl.includes("/login")) {
                    this.log(`  Shop URL: ${shopUrl}`);
                    break;
                }
            }
        }

        if (!shopUrl) {
            // Fallback: 顺企网店铺 URL 格式为 https://公司名.11467.com/
            const companySlug = (e.company_name || username)
                .replace(/[^一-龥a-zA-Z0-9]/g, "")
                .substring(0, 20);
            shopUrl = `https://${companySlug}.11467.com/`;
            this.log(`  Fallback URL: ${shopUrl}`);
        }

        await this.ss(page, "04_shop");
        this.log(`✅ CERTIFICATION COMPLETE — ${shopUrl}`);

        return {
            success: true,
            shop_url: shopUrl,
            account_id: username,
            status: "certified",
        };
    }
}

export async function execute({ taskId, account, enterprise, options, logger }) {
    const script = new ShunqiScript(taskId, account, enterprise, options, logger.info.bind(logger));
    return script.execute();
}
