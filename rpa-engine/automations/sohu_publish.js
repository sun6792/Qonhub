/**
 * 搜狐号自动发布脚本。
 *
 * 基于 Playwright + 双层 stealth，通过 Cookie 登录后自动发布图文。
 * 继承 BasePlatformScript 获得全套反爬 + 行为模拟 + Cookie 持久化。
 *
 * 发布流程：
 *   Step 1: 打开搜狐号创作平台，检查登录态
 *   Step 2: 检测登录过期（login page detection）
 *   Step 3: 点击「写文章」/「发布」/「创作」进入编辑器
 *   Step 4: 填写标题（input / contenteditable with "标题" placeholder）
 *   Step 5: 填写正文（contenteditable / 富文本编辑器）
 *   Step 6: 检测验证码
 *   Step 7: 点击发布/提交按钮
 *   Step 8: 确认发布对话框
 *   Step 9: 抓取文章 URL，返回结果
 *
 * 异常分支：
 *   - Cookie 过期 → 返回 LOGIN_EXPIRED
 *   - 验证码 → 返回 CAPTCHA_BLOCKED
 *   - 内容违禁 → 返回 CONTENT_REJECTED
 *   - 发布限流 → 返回 RATE_LIMITED
 */

import { BasePlatformScript, PUBLISH_STATUS } from "../lib/BasePlatformScript.js";

export const platform = "sohu_publish";
export const description = "搜狐号 — 自媒体图文发布";

class SohuPublishScript extends BasePlatformScript {
    async publishFlow(page, content) {
        this.log(`Publishing: ${content.title}`);

        // ═══ Step 1: 导航到搜狐号创作平台 ═══
        this.log("Step 1: Navigating to Sohu MP...");
        await this.safeGoto(page, "https://mp.sohu.com/", { waitUntil: "domcontentloaded", timeout: 25000 });
        await this.wait(2000, 4000);

        // ═══ Step 2: 检测登录态 ═══
        this.log("Step 2: Checking login state...");
        const pageBody = await page.textContent("body");

        // 搜狐号登录页特征：出现"登录"或"注册"但缺少"创作"/"发布"/"内容"等管理功能
        const isLoginPage = (pageBody.includes("登录") || pageBody.includes("密码登录") || pageBody.includes("手机登录"))
            && !pageBody.includes("写文章")
            && !pageBody.includes("内容管理")
            && !pageBody.includes("创作中心");

        if (isLoginPage) {
            // 额外检测：URL 是否被重定向到登录页
            const currentUrl = page.url();
            if (currentUrl.includes("login") || currentUrl.includes("passport") || currentUrl.includes("signin")) {
                this.log("Cookie 已过期，需重新授权 (URL redirect detected)");
                return { status: PUBLISH_STATUS.LOGIN_EXPIRED, article_url: "" };
            }
            // body 文本检测也命中
            this.log("Cookie 已过期，需重新授权 (login page body detected)");
            return { status: PUBLISH_STATUS.LOGIN_EXPIRED, article_url: "" };
        }

        await this.ss(page, "01_login_ok");
        this.log("  Login state: OK");

        // ═══ Step 3: 进入编辑器 ═══
        this.log("Step 3: Opening editor...");

        // 先尝试搜寻找创作入口按钮
        let editorReached = false;

        // 策略 A: 点击主页上的创作/发布按钮
        const writeBtn = await this.findElement(page, [
            'a:has-text("写文章")',
            'button:has-text("写文章")',
            'a:has-text("发布")',
            'button:has-text("发布")',
            'a:has-text("创作")',
            'button:has-text("创作")',
            'span:has-text("写文章")',
            // 搜狐号可能的类名
            '.write-btn',
            '.publish-btn',
            '.create-btn',
            '[class*="write"]',
            '[class*="publish"]',
            '[class*="create"]',
        ], 5000);

        if (writeBtn) {
            await writeBtn.click();
            await this.wait(2000, 4000);
            editorReached = true;
            this.log("  Clicked write/publish button on homepage");
        }

        // 策略 B: 如果主页按钮不存在，直接尝试导航到已知发布 URL
        if (!editorReached) {
            const directUrls = [
                "https://mp.sohu.com/mpfe/v3/article/create",
                "https://mp.sohu.com/mpfe/v3/article/write",
                "https://mp.sohu.com/mpfe/v3/publish",
                "https://mp.sohu.com/article/create",
                "https://mp.sohu.com/article/write",
            ];
            for (const url of directUrls) {
                try {
                    await page.goto(url, { waitUntil: "domcontentloaded", timeout: 15000 });
                    await this.wait(2000, 4000);
                    const afterNavBody = await page.textContent("body");
                    if (afterNavBody.includes("标题") || afterNavBody.includes("正文") || afterNavBody.includes("发布")) {
                        editorReached = true;
                        this.log(`  Editor reached via direct URL: ${url}`);
                        break;
                    }
                } catch { /* continue to next URL */ }
            }
        }

        // 策略 C: 用 safeGoto 导航到创作中心后再找按钮
        if (!editorReached) {
            this.log("  Trying content management portal...");
            try {
                await this.safeGoto(page, "https://mp.sohu.com/mpfe/v3/main", { waitUntil: "domcontentloaded", timeout: 15000 });
            } catch { /* ignore */ }
            await this.wait(2000, 4000);
            const secondaryBtn = await this.findElement(page, [
                'a:has-text("写文章")',
                'button:has-text("写文章")',
                'a:has-text("新建")',
                'button:has-text("新建")',
                '[class*="write"]',
                '[class*="createArt"]',
            ], 3000);
            if (secondaryBtn) {
                await secondaryBtn.click();
                await this.wait(2000, 4000);
                editorReached = true;
                this.log("  Editor reached via secondary button search");
            }
        }

        if (!editorReached) {
            // 兜底：直接用 safeGoto 到发布页
            await this.safeGoto(page, "https://mp.sohu.com/mpfe/v3/article/create", { waitUntil: "domcontentloaded", timeout: 15000 });
            await this.wait(3000, 5000);
        }

        await this.simulateHumanBrowsing(page);
        await this.ss(page, "02_editor");

        // ═══ Step 4: 填写标题 ═══
        this.log("Step 4: Filling title...");
        await this.typeHuman(page, [
            'input[placeholder*="标题"]',
            'input[placeholder*="title"]',
            'textarea[placeholder*="标题"]',
            'input[name*="title"]',
            '[class*="title"] input',
            '.title-input input',
            '[contenteditable="true"][placeholder*="标题"]',
            '[contenteditable="true"][aria-label*="标题"]',
            // 搜狐号特定选择器
            '#articleTitle',
            '.article-title input',
            '.mp-article-title input',
        ], content.title);
        await this.wait(500, 1500);

        // ═══ Step 5: 填写正文 ═══
        this.log("Step 5: Filling body...");
        await this.simulateMouseHover(page);

        // 搜狐号可能使用 iframe 嵌套富文本编辑器（如 UEditor）
        let bodyEl = await this.findElement(page, [
            '[contenteditable="true"]',
            '.ql-editor',
            '.ProseMirror',
            '.editor-content',
            'textarea[placeholder*="正文"]',
            'textarea[name*="content"]',
            '[class*="editor"] [contenteditable]',
            '[class*="body"] [contenteditable]',
            // 搜狐号特定
            '#articleContent',
            '.editor-container [contenteditable]',
            '.ueditor-container [contenteditable]',
        ]);

        if (bodyEl) {
            await bodyEl.scrollIntoViewIfNeeded();
            await bodyEl.click();
            await this.wait(300, 800);

            // 清空现有内容
            await bodyEl.fill("");

            // 逐字输入正文内容
            const bodyText = content.body || "";
            for (const ch of bodyText) {
                await bodyEl.type(ch, { delay: this.rand(50, 100) });
            }
            await this.wait(500, 1500);
            this.log("  Body filled");
        } else {
            this.log("  WARNING: Body editor not found. Continuing without body.");
        }

        await this.ss(page, "03_filled");

        // ═══ Step 6: 验证码检测 ═══
        this.log("Step 6: Checking for captcha...");
        const preSubmitBody = await page.textContent("body");
        if (
            preSubmitBody.includes("验证码") ||
            preSubmitBody.includes("滑块") ||
            preSubmitBody.includes("人机验证") ||
            preSubmitBody.includes("点击完成验证") ||
            preSubmitBody.includes("拖动滑块")
        ) {
            this.log("  Captcha detected before submit");
            await this.ss(page, "captcha_detected");
            return { status: PUBLISH_STATUS.CAPTCHA_BLOCKED, article_url: "" };
        }
        this.log("  No captcha detected");

        // ═══ Step 7: 点击发布 ═══
        this.log("Step 7: Clicking publish...");
        await this.simulateMouseHover(page);

        let submitClicked = false;
        const primarySubmitBtn = await this.findElement(page, [
            'button:has-text("发布")',
            'button:has-text("发表")',
            'button:has-text("提交")',
            'button:has-text("保存并发布")',
            'button:has-text("确认发布")',
            'a:has-text("发布")',
            'a:has-text("发表")',
            '[class*="publish"] button',
            '[class*="submit"] button',
            '.btn-publish',
            '.publish-btn',
        ], 5000);

        if (primarySubmitBtn) {
            await primarySubmitBtn.click();
            submitClicked = true;
            await this.wait(3000, 5000);
            this.log("  Publish button clicked");
        }

        if (!submitClicked) {
            this.log("  Submit button not found, trying force click on visible buttons");
            // 最后的兜底：获取所有 button 元素，查找文本匹配
            const allButtons = await page.$$("button");
            for (const btn of allButtons) {
                try {
                    const btnText = await btn.textContent();
                    if (btnText && /发布|发表|提交/.test(btnText.trim())) {
                        await btn.click({ force: true });
                        await this.wait(3000, 5000);
                        submitClicked = true;
                        this.log("  Force clicked publish button");
                        break;
                    }
                } catch { /* skip */ }
            }
        }

        // ═══ Step 8: 确认发布对话框 ═══
        this.log("Step 8: Confirming publish dialog...");
        const confirmBtn = await this.findElement(page, [
            'button:has-text("确认")',
            'button:has-text("确定")',
            'button:has-text("确认发布")',
            'button:has-text("确定发布")',
            '.confirm-btn',
            '.dialog button',
            '[class*="confirm"] button',
            '[class*="modal"] button:has-text("确认")',
        ], 3000);

        if (confirmBtn) {
            await confirmBtn.click();
            await this.wait(3000, 5000);
            this.log("  Publish confirmed");
        } else {
            this.log("  No confirmation dialog detected (maybe already submitted)");
        }

        // 等待发布完成
        await this.wait(3000, 5000);
        await this.ss(page, "04_published");

        // ═══ Step 9: 检测结果 ═══
        this.log("Step 9: Detecting publish result...");
        const resultBody = await page.textContent("body");

        // 验证码检测（可能发布过程中触发）
        if (resultBody.includes("验证码") || resultBody.includes("滑块") || resultBody.includes("人机验证")) {
            return { status: PUBLISH_STATUS.CAPTCHA_BLOCKED, article_url: "" };
        }

        // 内容违禁检测
        if (
            resultBody.includes("违规") ||
            resultBody.includes("敏感") ||
            resultBody.includes("驳回") ||
            resultBody.includes("不通过") ||
            resultBody.includes("不符合规范") ||
            resultBody.includes("含有违禁") ||
            resultBody.includes("禁止发布") ||
            resultBody.includes("审核不通过")
        ) {
            return { status: PUBLISH_STATUS.CONTENT_REJECTED, article_url: "" };
        }

        // 限流检测
        if (
            resultBody.includes("超限") ||
            resultBody.includes("上限") ||
            resultBody.includes("今天已") ||
            resultBody.includes("发布过多") ||
            resultBody.includes("频率") ||
            resultBody.includes("已达上限")
        ) {
            return { status: PUBLISH_STATUS.RATE_LIMITED, article_url: "" };
        }

        // ═══ Step 10: 抓取文章 URL ═══
        this.log("Step 10: Extracting article URL...");
        let articleUrl = "";

        // 方法 1: 从当前页面 URL 中提取（成功发布后可能跳转到文章页）
        const currentUrl = page.url();
        if (currentUrl.includes("sohu.com/a/") || currentUrl.includes("sohu.com/article/")) {
            articleUrl = currentUrl;
            this.log(`  Extracted from current URL: ${articleUrl}`);
        }

        // 方法 2: 查找页面上的成功提示和文章链接
        if (!articleUrl) {
            // 检查是否有"发布成功"或"审核中"等提示
            if (resultBody.includes("发布成功") || resultBody.includes("提交成功") || resultBody.includes("审核中") || resultBody.includes("已发布")) {
                this.log("  Publish success confirmed via body text");
                // 尝试从成功页面提取链接
                const links = await page.$$('a[href*="sohu.com"]');
                for (const link of links) {
                    try {
                        const href = await link.getAttribute("href");
                        if (href && (href.includes("sohu.com/a/") || href.includes("sohu.com/article/"))) {
                            articleUrl = href;
                            this.log(`  Article URL found on page: ${articleUrl}`);
                            break;
                        }
                    } catch { /* skip */ }
                }
            }
        }

        // 方法 3: 导航到内容管理列表获取最新文章 URL
        if (!articleUrl) {
            try {
                await this.safeGoto(page, "https://mp.sohu.com/mpfe/v3/content", { waitUntil: "domcontentloaded", timeout: 15000 });
                await this.wait(2000, 4000);

                // 查找最新文章链接
                const articleLinks = await page.$$('a[href*="sohu.com/a/"], a[href*="sohu.com/article/"]');
                if (articleLinks.length > 0) {
                    const href = await articleLinks[0].getAttribute("href");
                    articleUrl = href || "";
                    this.log(`  Article URL from content list: ${articleUrl}`);
                }
            } catch (err) {
                this.log(`  Content list navigation failed: ${err.message}`);
            }
        }

        // 方法 4: 回退到管理首页寻找链接
        if (!articleUrl) {
            try {
                await this.safeGoto(page, "https://mp.sohu.com/", { waitUntil: "domcontentloaded", timeout: 10000 });
                await this.wait(2000, 4000);
                const homeLinks = await page.$$('a[href*="sohu.com/a/"]');
                if (homeLinks.length > 0) {
                    articleUrl = await homeLinks[0].getAttribute("href") || "";
                    this.log(`  Article URL from homepage: ${articleUrl}`);
                }
            } catch { /* final fallback failed */ }
        }

        return {
            status: PUBLISH_STATUS.SUCCESS,
            article_url: articleUrl || "",
        };
    }
}

export async function execute(opts) {
    const script = new SohuPublishScript(
        opts.taskId,
        opts.account,
        opts.enterprise,
        opts.options,
        opts.logger.info.bind(opts.logger)
    );
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

export async function publish({ taskId, account, enterprise, content, options, logger }) {
    const logFn = (msg) => logger?.info ? logger.info(msg) : console.log(msg);
    const script = new SohuPublishScript(taskId, account, enterprise, options, logFn);
    const { browser, page } = await script.launchBrowserWithRetry();
    try {
        const article = { title: content?.title || "", body: content?.content || "" };
        const pubResult = await script.publishFlow(page, article);
        try { await script._saveState(); } catch {}
        if (process.env.USE_PERSISTENT_PROFILE !== 'true') {
            try { await browser.close(); } catch {}
        } else {
            try { await page.close(); } catch {}
        }
        return { success: pubResult.status === PUBLISH_STATUS.SUCCESS || pubResult.status === "success", article_url: pubResult.article_url || "", error: "", status: pubResult.status };
    } catch (err) {
        if (process.env.USE_PERSISTENT_PROFILE !== 'true') {
            try { await browser.close(); } catch {}
        } else {
            try { await page.close(); } catch {}
        }
        return { success: false, article_url: "", error: err.message, status: "error" };
    }
}
