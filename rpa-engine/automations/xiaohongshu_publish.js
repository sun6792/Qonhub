/**
 * 小红书自动发布脚本。
 * Cookie 登录 → 创作者中心 → 发布笔记 → 填写标题/正文 → 发布 → 抓取链接
 */
import { BasePlatformScript, PUBLISH_STATUS } from "../lib/BasePlatformScript.js";

export const platform = "xiaohongshu_publish";
export const description = "小红书自动发布 — Cookie登录 + 图文笔记发布";

class XiaohongshuPublishScript extends BasePlatformScript {
    async publishFlow(page, article) {
        this.log(`Publishing: ${article.title}`);

        // Step 1: 登录态检查
        this.log("Step 1: Login check...");
        await page.goto("https://creator.xiaohongshu.com/", { waitUntil: "domcontentloaded", timeout: 20000 });
        await this.wait(2000, 4000);
        const body = await page.textContent("body");
        if (body.includes("登录") && !body.includes("创作")) {
            return { status: PUBLISH_STATUS.LOGIN_EXPIRED, article_url: "" };
        }
        await this.ss(page, "01_login_ok");

        // Step 2: 发布页
        this.log("Step 2: Editor...");
        const publishBtn = await this.findElement(page, [
            'a:has-text("发布"), button:has-text("发布笔记"), span:has-text("发布笔记")',
        ]);
        if (publishBtn) { await publishBtn.click(); await this.wait(2000, 4000); }
        await this.simulateHumanBrowsing(page);
        await this.ss(page, "02_editor");

        // Step 3: 填标题
        await this.typeHuman(page, [
            'input[placeholder*="标题"], [class*="title"] input',
        ], article.title.substring(0, 20)); // 小红书标题限20字
        await this.wait();

        // Step 4: 填正文
        const bodyEl = await this.findElement(page, [
            '[contenteditable="true"], [class*="editor"], [class*="content"] [contenteditable]',
        ]);
        if (bodyEl) {
            await bodyEl.click();
            for (const ch of (article.body || "").substring(0, 1000)) {
                await bodyEl.type(ch, { delay: this.rand(50, 100) });
            }
            await this.wait();
        }
        await this.ss(page, "03_filled");

        // Step 5: 发布
        await this.simulateMouseHover(page);
        const submitBtn = await this.findElement(page, [
            'button:has-text("发布"), button:has-text("发布笔记")',
        ]);
        if (submitBtn) { await submitBtn.click(); await this.wait(3000, 5000); }
        await this.ss(page, "04_published");

        // Step 6: 检测结果
        const resultBody = await page.textContent("body");
        if (resultBody.includes("验证码") || resultBody.includes("人机验证")) {
            return { status: PUBLISH_STATUS.CAPTCHA_BLOCKED, article_url: "" };
        }
        if (resultBody.includes("违规") || resultBody.includes("不符合")) {
            return { status: PUBLISH_STATUS.CONTENT_REJECTED, article_url: "" };
        }
        if (resultBody.includes("上限") || resultBody.includes("次数")) {
            return { status: PUBLISH_STATUS.RATE_LIMITED, article_url: "" };
        }

        let articleUrl = "";
        try {
            await page.goto("https://creator.xiaohongshu.com/", { waitUntil: "domcontentloaded", timeout: 10000 });
            await this.wait(2000, 4000);
            const links = await page.$$('a[href*="xiaohongshu.com/explore/"]');
            if (links.length > 0) articleUrl = await links[0].getAttribute("href");
        } catch {}

        return {
            status: PUBLISH_STATUS.SUCCESS,
            article_url: articleUrl || "",
        };
    }
}

export async function execute(opts) {
    const script = new XiaohongshuPublishScript(opts.taskId, opts.account, opts.enterprise, opts.options, opts.logger.info.bind(opts.logger));
    const result = await script.execute();
    const article = opts.options?.article;
    if (article?.title) {
        const { page, browser } = await script.launchBrowser();
        const pubResult = await script.publishFlow(page, article);
        result.status = pubResult.status;
        result.article_url = pubResult.article_url;
        try { await script._saveState(); await browser.close(); } catch {}
    }
    return result;
}
