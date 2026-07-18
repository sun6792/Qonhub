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

import fs from "fs";
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

        // ═══ Step 3: 填写标题 (nativeSetter绕过React) ═══
        this.log("Step 3: Title...");
        const safeTitle = (e.title || '').substring(0, 30);
        await page.evaluate((title) => {
            const ta = document.querySelector('input[placeholder*="标题"], textarea[placeholder*="标题"], [class*="title"] input, .title-input input');
            if (!ta) return;
            const nativeSetter = Object.getOwnPropertyDescriptor(window.HTMLInputElement?.prototype || window.HTMLTextAreaElement.prototype, 'value')?.set;
            if (nativeSetter) { nativeSetter.call(ta, title); } else { ta.value = title; }
            ta.dispatchEvent(new Event('input', { bubbles: true }));
            ta.dispatchEvent(new Event('change', { bubbles: true }));
        }, safeTitle);
        this.log(`Title: OK (${safeTitle})`);
        await this.wait(500, 1000);

        // ═══ Step 4: 填正文 (insertHTML — 绕开剪贴板, 零乱码) ═══
        this.log("Step 4: Body (insertHTML)...");
        const bodyHtml = (e.body || e.content || '').trim();
        await page.evaluate((html) => {
            const ed = document.querySelector('[contenteditable="true"], .editor-content, .ql-editor');
            if (!ed) return;
            ed.focus();
            document.execCommand('selectAll', false, '');
            document.execCommand('insertHTML', false, html);
        }, bodyHtml);
        await this.wait(1000, 1500);
        const charCount = await page.evaluate(() => {
            const ed = document.querySelector('[contenteditable="true"]');
            return ed ? (ed.textContent || '').length : 0;
        });
        this.log(`Body: ${charCount > 10 ? 'OK' : 'FAIL'} (${charCount} chars)`);
        await this.ss(page, "03_content_filled");

        // ═══ Step 5: 上传封面 ═══
        const coverPath = e.cover_image || this.options?.cover_image || null;
        if (coverPath && fs.existsSync(coverPath)) {
            this.log("Step 5: Cover...");
            // 点封面区域
            const coverBtn = await this.findElement(page, [
                '[class*="cover-add"], [class*="cover-upload"], span:text("上传封面"), div:text("添加封面")',
                '.cover-trigger, [class*="cover"] button',
            ], 3000);
            if (coverBtn) { await coverBtn.click(); await this.wait(1000, 2000); }
            // 找 file input
            const fileInput = await this.findElement(page, ['input[type="file"]'], 3000);
            if (fileInput) {
                await fileInput.setInputFiles(coverPath);
                this.log('Cover: uploaded ✅');
                await this.wait(3000, 5000);
                // 确认按钮
                const confirmBtn = await this.findElement(page, [
                    'button:text("确认"), button:text("确定"), button:text("完成")'
                ], 2000);
                if (confirmBtn) { await confirmBtn.click(); await this.wait(1000, 1500); }
            } else {
                this.log('Cover: file input not found');
            }
            await this.ss(page, "04_cover");
        } else {
            this.log('Step 5: No cover image');
        }

        // ═══ Step 7: 发布 ═══
        this.log("Step 7: Publishing...");
        // 找发布按钮
        const published = await page.evaluate(() => {
            const btns = document.querySelectorAll('button');
            for (const btn of btns) {
                const t = (btn.textContent || '').trim();
                if ((t === '发布' || (t.includes('发布') && !t.includes('草稿') && !t.includes('预览') && !t.includes('定时'))) && btn.offsetParent !== null) {
                    btn.click(); return 'clicked';
                }
            }
            return 'no_button';
        });
        this.log(`Publish btn: ${published}`);
        await this.wait(2000, 3000);

        // 确认弹窗
        const confirmed = await page.evaluate(() => {
            const btns = document.querySelectorAll('button');
            for (const btn of btns) {
                const t = (btn.textContent || '').trim();
                if ((t === '确认' || t === '确定' || t === '发布' || t === '确认发布') && btn.offsetParent !== null) {
                    btn.click(); return 'confirmed';
                }
            }
            return 'no_confirm';
        });
        this.log(`Confirm: ${confirmed}`);
        await this.wait(3000, 5000);
        await this.ss(page, "05_published");

        // ═══ Step 8: 检测结果 ═══
        const resultBody = await page.textContent("body");
        if (resultBody.includes("验证码") || resultBody.includes("滑块")) {
            return { status: PUBLISH_STATUS.CAPTCHA_BLOCKED, article_url: "" };
        }
        if (resultBody.includes("违规") || resultBody.includes("敏感") || resultBody.includes("驳回")) {
            return { status: PUBLISH_STATUS.CONTENT_REJECTED, article_url: "" };
        }
        if (resultBody.includes("太快") || resultBody.includes("频繁") || resultBody.includes("超限")) {
            return { status: PUBLISH_STATUS.RATE_LIMITED, article_url: "" };
        }

        // 抓取文章 URL
        let articleUrl = "";
        try {
            // 检查跳转到文章列表
            const curUrl = page.url();
            if (curUrl.includes("articles") || curUrl.includes("content")) {
                articleUrl = curUrl;
            } else {
                const links = await page.$$('a[href*="/s?id="], a[href*="baijiahao.baidu.com/s/"]');
                if (links.length > 0) articleUrl = await links[0].getAttribute("href");
            }
            this.log(`Article URL: ${articleUrl || 'not found'}`);
        } catch {}

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

/**
 * publish() — server.js /api/v1/publish 端点调用的入口。
 * v2.9: 百家号发布入口，复用 MultiPost 模式填表。
 */
export async function publish({ taskId, account, enterprise, content, options, logger }) {
    const logFn = (msg) => logger?.info ? logger.info(msg) : console.log(msg);
    const script = new BaijiahaoPublishScript(taskId, account, enterprise, options, logFn);
    const { browser, page } = await script.launchBrowserWithRetry();
    try {
        const article = { title: content?.title || "", body: content?.content || "", cover_image: options?.cover_image || null };
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
            try { await page.context().close(); } catch {}
        }
        return { success: false, article_url: "", error: err.message, status: "error" };
    }
}
