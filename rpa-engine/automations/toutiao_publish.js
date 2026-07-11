/**
 * 头条号自动发布脚本。
 * 参考 toutiao-publish 开源项目，翻译为 Node.js + Playwright。
 *
 * 流程：Cookie 登录 → 导航到发布页 → 填写标题/正文 → 设置封面 → 预览发布 → 抓取 URL
 */
import { BasePlatformScript, PUBLISH_STATUS } from "../lib/BasePlatformScript.js";

export const platform = "toutiao_publish";
export const description = "头条号自动发布 — Cookie登录 + 图文发布";

class ToutiaoPublishScript extends BasePlatformScript {
    async publishFlow(page, article) {
        this.log(`Publishing: ${article.title}`);

        // Step 1: 检查登录态
        this.log("Step 1: Login check...");
        await page.goto("https://mp.toutiao.com/", { waitUntil: "domcontentloaded", timeout: 20000 });
        await this.wait(2000, 4000);
        const body = await page.textContent("body");
        if (body.includes("登录") && !body.includes("创作")) {
            return { status: PUBLISH_STATUS.LOGIN_EXPIRED, article_url: "" };
        }
        await this.ss(page, "01_login_ok");

        // Step 2: 进入发布页
        this.log("Step 2: Editor...");
        await page.goto("https://mp.toutiao.com/profile_v4/graphic/publish", { waitUntil: "domcontentloaded", timeout: 15000 });
        await this.wait(2000, 4000);
        await this.simulateHumanBrowsing(page);
        await this.ss(page, "02_editor");

        // Step 3: 填标题
        await this.typeHuman(page, [
            'input[placeholder*="标题"], textarea[placeholder*="标题"]',
        ], article.title);
        await this.wait();

        // Step 4: 填正文
        const bodyEl = await this.findElement(page, [
            '[contenteditable="true"]', '.ProseMirror',
        ]);
        if (bodyEl) {
            await bodyEl.click();
            for (const ch of article.body || "") {
                await bodyEl.type(ch, { delay: this.rand(50, 100) });
            }
            await this.wait();
        }

        // Step 5: 发布
        await this.simulateMouseHover(page);
        await this.ss(page, "03_filled");
        const publishBtn = await this.findElement(page, ['button:has-text("发布"), button:has-text("预览并发布")']);
        if (publishBtn) { await publishBtn.click(); await this.wait(2000, 4000); }

        // 确认发布弹出框
        const confirmBtn = await this.findElement(page, ['button:has-text("确认"), button:has-text("确定发布")']);
        if (confirmBtn) { await confirmBtn.click(); await this.wait(3000, 5000); }
        await this.ss(page, "04_published");

        // Step 6: 检测结果
        const resultBody = await page.textContent("body");
        if (resultBody.includes("验证码") || resultBody.includes("滑块")) {
            return { status: PUBLISH_STATUS.CAPTCHA_BLOCKED, article_url: "" };
        }
        if (resultBody.includes("违规") || resultBody.includes("驳回")) {
            return { status: PUBLISH_STATUS.CONTENT_REJECTED, article_url: "" };
        }
        if (resultBody.includes("上限") || resultBody.includes("超限")) {
            return { status: PUBLISH_STATUS.RATE_LIMITED, article_url: "" };
        }

        // 从内容管理页获取文章 URL
        let articleUrl = "";
        try {
            await page.goto("https://mp.toutiao.com/profile_v4/graphic/articles", { waitUntil: "domcontentloaded", timeout: 10000 });
            await this.wait(2000, 4000);
            const links = await page.$$('a[href*="toutiao.com/a/"]');
            if (links.length > 0) {
                articleUrl = await links[0].getAttribute("href");
            }
        } catch {}

        return {
            status: PUBLISH_STATUS.SUCCESS,
            article_url: articleUrl || "",
        };
    }
}

export async function execute(opts) {
    const script = new ToutiaoPublishScript(opts.taskId, opts.account, opts.enterprise, opts.options, opts.logger.info.bind(opts.logger));
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
