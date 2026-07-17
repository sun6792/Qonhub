/**
 * 头条号自动发布脚本 v2.9。
 * 流程：Cookie 登录 → 导航到发布页 → 填标题 → 填正文 → 上传封面 → 发布 → 抓取 URL
 */
import { BasePlatformScript, PUBLISH_STATUS } from "../lib/BasePlatformScript.js";
import path from "path";
import fs from "fs";

export const platform = "toutiao_publish";
export const description = "头条号自动发布 — Cookie登录 + 图文发布 + 封面上传";

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

        // Step 2: 进入发布页 — 等网络完全空闲确保编辑器JS加载
        this.log("Step 2: Editor...");
        await page.goto("https://mp.toutiao.com/profile_v4/graphic/publish", { waitUntil: "networkidle", timeout: 30000 });
        await this.wait(3000, 5000);
        await this.ss(page, "02_editor");

        // Step 3: 填标题 — 原生setter绕过React状态管理
        this.log("Step 3: Title...");
        const safeTitle = (article.title || '空发科技').substring(0, 30);
        const titleDone = await page.evaluate((title) => {
            const ta = document.querySelector('textarea[placeholder*="标题"]');
            if (!ta) return false;
            ta.focus();
            // 使用原生setter绕过React的value劫持
            const nativeSetter = Object.getOwnPropertyDescriptor(
                window.HTMLTextAreaElement.prototype, 'value'
            )?.set;
            if (nativeSetter) {
                nativeSetter.call(ta, title);
            } else {
                ta.value = title;
            }
            ta.dispatchEvent(new Event('input', { bubbles: true }));
            ta.dispatchEvent(new Event('change', { bubbles: true }));
            ta.dispatchEvent(new KeyboardEvent('keyup', { bubbles: true }));
            return true;
        }, safeTitle);
        this.log(`Title: ${titleDone ? 'OK' : 'fallback'}`);
        if (!titleDone) {
            for (const ch of safeTitle) { await page.keyboard.type(ch); await page.waitForTimeout(30); }
        }
        await this.ss(page, "03_title_done");
        await this.wait(1000, 1500);

        // Step 4: 填正文 — 原生setter绕过React + ClipboardEvent paste
        this.log("Step 4: Body...");
        const bodyContent = (article.body || article.content || '')
            .replace(/<br\s*\/?>/gi, '\n')
            .replace(/<\/p>/gi, '\n')
            .replace(/<[^>]+>/g, '')
            .replace(/\n{3,}/g, '\n\n')
            .trim();
        const bodyHtml = bodyContent ? bodyContent.split('\n\n').map(p => `<p>${p}</p>`).join('') : '<p></p>';
        const bodyOk = await page.evaluate((html) => {
            const editor = document.querySelector('div[contenteditable="true"]');
            if (!editor) return false;
            editor.focus();
            // 先用原生innerHTML设置内容
            const nativeSetter = Object.getOwnPropertyDescriptor(
                window.HTMLElement.prototype, 'innerHTML'
            )?.set;
            if (nativeSetter) {
                nativeSetter.call(editor, html);
            } else {
                editor.innerHTML = html;
            }
            // 再触发paste事件（React富文本编辑器监听此事件同步状态）
            const dt = new DataTransfer();
            dt.setData('text/html', html);
            editor.dispatchEvent(new ClipboardEvent('paste', {
                bubbles: true, cancelable: true, clipboardData: dt,
            }));
            editor.dispatchEvent(new Event('input', { bubbles: true }));
            editor.dispatchEvent(new Event('change', { bubbles: true }));
            return true;
        }, bodyHtml);
        this.log(`Body: ${bodyOk ? 'OK' : 'fallback'} (${bodyContent.length} chars)`);
        if (!bodyOk) {
            const bodyEl = page.locator('[contenteditable="true"]').first();
            if (await bodyEl.isVisible({timeout: 3000}).catch(() => false)) {
                await bodyEl.click({ force: true });
                await page.keyboard.press('Control+v');
            }
        }
        await this.ss(page, "04_body_done");
        await this.wait(1000, 2000);

        // Step 5: 上传封面图片
        this.log("Step 5: Cover image...");
        const coverPath = article.cover_image || this.options?.cover_image || null;
        if (coverPath && fs.existsSync(coverPath)) {
            await this.uploadCover(page, coverPath);
            await this.ss(page, "05_cover_done");
        } else {
            this.log('⚠️ 无封面图片，尝试点击「无封面」或跳过');
            // 尝试点击"无封面"选项
            try {
                const noCoverBtn = await this.findElement(page, [
                    'span:has-text("无封面")', 'label:has-text("无封面")',
                    'div:has-text("无封面")'
                ], 3000);
                if (noCoverBtn) { await noCoverBtn.click(); await this.wait(500, 1000); }
            } catch {}
        }

        // Step 6: 关闭遮挡 + 点击"预览并发布"
        this.log("Step 6: Publish...");
        // 多次按Esc确保关闭所有弹窗/抽屉
        for (let i = 0; i < 3; i++) {
            await page.keyboard.press('Escape');
            await this.wait(300, 500);
        }
        await this.ss(page, "06_before_publish");

        // 用 MultiPost 同样的选择器找发布按钮
        const clicked = await page.evaluate(() => {
            const buttons = document.querySelectorAll('button.publish-btn');
            const btn = Array.from(buttons).find(b => b.textContent?.includes('预览并发布'));
            if (btn) { btn.click(); return true; }
            // fallback: 找任何包含"发布"的button
            const allBtns = document.querySelectorAll('button');
            const fallback = Array.from(allBtns).find(b => b.textContent?.includes('发布') && !b.textContent?.includes('定时'));
            if (fallback) { fallback.click(); return true; }
            return false;
        });
        this.log(`Publish button clicked: ${clicked}`);
        await this.wait(3000, 5000);

        // Step 6.5: 确认发布弹窗 — MultiPost 漏了这一步！
        this.log("Step 6.5: Confirm dialog...");
        const confirmed = await page.evaluate(() => {
            const buttons = document.querySelectorAll('button');
            const btn = Array.from(buttons).find(b =>
                b.textContent?.includes('确认') || b.textContent?.includes('确定发布') || b.textContent?.includes('确认并发布')
            );
            if (btn && btn.offsetParent !== null) { btn.click(); return true; }
            return false;
        });
        this.log(`Confirm clicked: ${confirmed}`);
        await this.wait(3000, 5000);

        // Step 6.6: 预览页最终发布按钮（页面可能跳转到预览URL）
        this.log("Step 6.6: Final publish on preview...");
        const finalClicked = await page.evaluate(() => {
            // 预览页可能跳转到了 weitoutiao/publish 或类似预览URL
            // 找任何可见的发布/确认按钮
            const allBtns = document.querySelectorAll('button');
            for (const btn of allBtns) {
                if (btn.offsetParent === null) continue; // 不可见
                const text = btn.textContent || '';
                if (text.includes('发布') && !text.includes('定时') && !text.includes('预览')) {
                    btn.click();
                    return text.trim();
                }
            }
            return false;
        });
        this.log(`Final publish: ${finalClicked || 'not found (may already be published)'}`);
        await this.wait(3000, 5000);
        await this.ss(page, "07_published");

        // Step 7: 检测结果
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
        if (resultBody.includes("封面") && (resultBody.includes("必填") || resultBody.includes("请上传"))) {
            return { status: PUBLISH_STATUS.ERROR, article_url: "", error: "缺少封面图片" };
        }

        // Step 8: 获取文章 URL
        let articleUrl = "";
        try {
            await page.goto("https://mp.toutiao.com/profile_v4/graphic/articles", { waitUntil: "domcontentloaded", timeout: 10000 });
            await this.wait(2000, 4000);
            const links = await page.$$('a[href*="toutiao.com/a/"], a[href*="toutiao.com/item/"]');
            if (links.length > 0) {
                articleUrl = await links[0].getAttribute("href");
            }
        } catch {}

        return {
            status: PUBLISH_STATUS.SUCCESS,
            article_url: articleUrl || "",
        };
    }

    /**
     * 上传封面图片。
     * 头条编辑器通常有一个隐藏的 input[type=file]，点击封面区域触发。
     */
    async uploadCover(page, imagePath) {
        const absPath = path.resolve(imagePath);
        this.log(`Uploading cover: ${absPath}`);

        // 方式1: 直接找隐藏的 file input 并设值
        let fileInput = await this.findElement(page, [
            'input[type="file"][accept*="image"]',
            'input[type="file"]',
        ], 3000);

        if (fileInput) {
            await fileInput.setInputFiles(absPath);
            this.log("Cover uploaded via file input");
            await this.wait(3000, 5000);
            return true;
        }

        // 方式2: 点击封面区域 → 触发 file chooser
        try {
            const coverArea = await this.findElement(page, [
                '.cover-upload-area', '.article-cover', '[class*="cover"]',
                'span:has-text("单图")', 'label:has-text("单图")',
                'div:has-text("单图")', '.cover-option'
            ], 3000);
            if (coverArea) {
                const [fileChooser] = await Promise.all([
                    page.waitForEvent('filechooser', { timeout: 5000 }),
                    coverArea.click(),
                ]);
                await fileChooser.setFiles(absPath);
                this.log("Cover uploaded via file chooser");
                await this.wait(3000, 5000);
                return true;
            }
        } catch (e) {
            this.log(`File chooser failed: ${e.message}`);
        }

        this.log("⚠️ Could not upload cover — attempting 'no cover' fallback");
        return false;
    }
}

// ══════════════════════════════════════════════════════
//  server.js 调用的入口函数
// ══════════════════════════════════════════════════════

export async function publish({ taskId, account, enterprise, content, options, logger }) {
    const logFn = (msg) => logger?.info ? logger.info(msg) : console.log(msg);
    const script = new ToutiaoPublishScript(taskId, account, enterprise, options, logFn);
    const { browser, page } = await script.launchBrowserWithRetry();

    try {
        // Cookie 健康预检
        const cookieOk = await script.checkCookieHealth(
            "https://mp.toutiao.com/",
            "创作"
        );
        if (!cookieOk) {
            await browser.close();
            return { success: false, article_url: "", error: "Cookie expired — 请在运营助手重新授权今日头条", status: "login_expired" };
        }

        const article = {
            title: content?.title || content?.article?.title || "",
            body: content?.content || content?.article?.body || content?.article?.content || "",
            cover_image: options?.cover_image || content?.cover_image || null,
        };

        const pubResult = await script.publishFlow(page, article);
        const result = {
            success: pubResult.status === PUBLISH_STATUS.SUCCESS || pubResult.status === "success",
            article_url: pubResult.article_url || "",
            error: pubResult.error || "",
            status: pubResult.status,
        };

        try { await script._saveState(); } catch {}
        if (process.env.USE_PERSISTENT_PROFILE !== 'true') {
            try { await browser.close(); } catch {}
        } else {
            try { await page.close(); } catch {} // 只关页面，不关浏览器
        }
        return result;
    } catch (err) {
        if (process.env.USE_PERSISTENT_PROFILE !== 'true') {
            try { await browser.close(); } catch {}
        }
        return { success: false, article_url: "", error: err.message, status: "error" };
    }
}

// server.js 注册要求：必须有 execute 导出
export async function execute(opts) {
    return publish(opts);
}
