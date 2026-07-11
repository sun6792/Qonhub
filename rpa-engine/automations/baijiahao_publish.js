/**
 * 百家号自动发布脚本。
 *
 * 基于 Playwright + 双层 stealth，通过 Cookie 登录后自动发布图文。
 * 继承 BasePlatformScript 获得全套反爬 + 行为模拟 + Cookie 持久化。
 *
 * 发布流程：
 *   Step 1: 用持久化 Cookie 打开创作中心，检测登录态
 *   Step 2: 点击「发布文章」→ 填写标题+正文
 *   Step 3: 设置封面、分类、标签
 *   Step 4: 提交发布 → 抓取文章 URL
 *
 * 异常分支：
 *   - Cookie 过期 → 返回 LOGIN_EXPIRED
 *   - 验证码 → 返回 CAPTCHA_BLOCKED
 *   - 内容违规 → 返回 CONTENT_REJECTED
 *   - 发布限流 → 返回 RATE_LIMITED
 */

import { BasePlatformScript, PUBLISH_STATUS } from "../lib/BasePlatformScript.js";

export const platform = "baijiahao_publish";
export const description = "百家号自动发布 — Cookie登录 + 图文发布 + 状态回传";

class BaijiahaoPublishScript extends BasePlatformScript {
    async publishFlow(page, article) {
        const e = article; // { title, body, images }
        this.log(`Publishing: ${e.title}`);

        // ═══ Step 1: 检查登录态 ═══
        this.log("Step 1: Checking login state...");
        await page.goto("https://baijiahao.baidu.com/", { waitUntil: "domcontentloaded", timeout: 20000 });
        await this.wait(2000, 4000);

        // 判断是否登录
        const isLoggedIn = await this.findElement(page, [
            'a:has-text("发布"), a:has-text("创作"), a:has-text("内容管理")',
            '[class*="publish"], [class*="write"]',
        ]);
        if (!isLoggedIn) {
            // 可能被重定向到登录页
            const body = await page.textContent("body");
            if (body.includes("登录") || body.includes("密码")) {
                this.log("Cookie 已过期，需重新授权");
                return { status: PUBLISH_STATUS.LOGIN_EXPIRED, article_url: "" };
            }
        }
        await this.ss(page, "01_logged_in");
        this.log("  Login state: OK");

        // ═══ Step 2: 进入发布页 ═══
        this.log("Step 2: Navigating to editor...");
        const publishUrls = [
            "https://baijiahao.baidu.com/builder/rc/edit",
            "https://baijiahao.baidu.com/publish/article/edit",
        ];
        let editorOpened = false;
        for (const url of publishUrls) {
            try {
                await page.goto(url, { waitUntil: "domcontentloaded", timeout: 15000 });
                await this.wait(2000, 4000);
                const body = await page.textContent("body");
                if (body.includes("标题") || body.includes("正文") || body.includes("发布")) {
                    editorOpened = true;
                    this.log(`  Editor at: ${url}`);
                    break;
                }
            } catch {}
        }
        if (!editorOpened) {
            // 从创作中心点发布按钮
            await page.goto("https://baijiahao.baidu.com/", { waitUntil: "domcontentloaded", timeout: 15000 });
            await this.wait(2000, 4000);
            const publishBtn = await this.findElement(page, [
                'a:has-text("发布"), a:has-text("写文章"), button:has-text("发布")',
            ]);
            if (publishBtn) { await publishBtn.click(); await this.wait(2000, 4000); }
        }
        await this.ss(page, "02_editor");

        // ═══ Step 3: 填写标题 ═══
        this.log("Step 3: Filling content...");
        await this.simulateMouseHover(page);
        await this.typeHuman(page, [
            'input[placeholder*="标题"], textarea[placeholder*="标题"], [class*="title"] input',
            '.title-input input',
        ], e.title);
        await this.wait(500, 1500);

        // ═══ Step 4: 填写正文 ═══
        // 百家号编辑器可能使用 contenteditable 或 textarea
        await this.simulateHumanBrowsing(page);
        const bodyEl = await this.findElement(page, [
            '[contenteditable="true"]', '.editor-content', '.ql-editor',
            'textarea[placeholder*="正文"], [class*="content"] textarea',
        ]);
        if (bodyEl) {
            await bodyEl.click();
            await this.wait(300, 800);
            // contenteditable 用 type，textarea 用 fill
            await bodyEl.fill("");
            for (const ch of e.body || "") {
                await bodyEl.type(ch, { delay: this.rand(60, 120) });
            }
        }
        await this.wait(500, 1500);
        await this.ss(page, "03_content_filled");

        // ═══ Step 5: 发布 ═══
        this.log("Step 5: Submitting...");
        await this.simulateMouseHover(page);
        const submitBtn = await this.findElement(page, [
            'button:has-text("发布"), button:has-text("提交"), button:has-text("保存并发布")',
            '[class*="publish"] button',
        ]);
        if (submitBtn) {
            await submitBtn.click();
            await this.wait(3000, 6000);
        }
        await this.ss(page, "04_published");

        // ═══ Step 6: 检测结果 ═══
        this.log("Step 6: Detecting result...");
        const body = await page.textContent("body");

        // 检查错误
        if (body.includes("验证码") || body.includes("滑块")) {
            return { status: PUBLISH_STATUS.CAPTCHA_BLOCKED, article_url: "" };
        }
        if (body.includes("违规") || body.includes("敏感") || body.includes("驳回")) {
            return { status: PUBLISH_STATUS.CONTENT_REJECTED, article_url: "" };
        }
        if (body.includes("超限") || body.includes("上限") || body.includes("今天已")) {
            return { status: PUBLISH_STATUS.RATE_LIMITED, article_url: "" };
        }

        // 抓取文章 URL
        let articleUrl = "";
        const urlLinks = await page.$$('a[href*="baijiahao.baidu.com"], a[href*="/s?id="]');
        for (const link of urlLinks) {
            const href = await link.getAttribute("href");
            if (href && href.includes("baijiahao")) {
                articleUrl = href;
                this.log(`  Article URL: ${articleUrl}`);
                break;
            }
        }

        return {
            status: articleUrl ? PUBLISH_STATUS.SUCCESS : PUBLISH_STATUS.UNKNOWN_ERROR,
            article_url: articleUrl,
        };
    }
}

export async function execute({ taskId, account, enterprise, options, logger }) {
    // 兼容两种调用模式：article 包含内容用于发布
    const content = options?.article || {};
    const script = new BaijiahaoPublishScript(taskId, account, enterprise, options, logger.info.bind(logger));
    const result = await script.execute();
    if (content.title) {
        const launcher = await script.launchBrowser();
        const page = launcher.page;
        const pubResult = await script.publishFlow(page, content);
        result.status = pubResult.status;
        result.article_url = pubResult.article_url;
        await script._saveState();
        try { await launcher.browser.close(); } catch {}
    }
    return result;
}
