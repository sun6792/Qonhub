import type { ArticleData, FileData, SyncData } from "~sync/common";

export async function ArticleToutiao(data: SyncData) {
  const articleData = data.origin as ArticleData;
  const processedData = data.data as ArticleData;
  console.log("message", data);
  function waitForElement(selector: string, timeout = 10000): Promise<Element> {
    return new Promise((resolve, reject) => {
      const element = document.querySelector(selector);
      if (element) {
        resolve(element);
        return;
      }

      const observer = new MutationObserver(() => {
        const element = document.querySelector(selector);
        if (element) {
          resolve(element);
          observer.disconnect();
        }
      });

      observer.observe(document.body, {
        childList: true,
        subtree: true,
      });

      setTimeout(() => {
        observer.disconnect();
        reject(new Error(`Element with selector "${selector}" not found within ${timeout}ms`));
      }, timeout);
    });
  }

  async function processContent(content: string): Promise<void> {
    await waitForElement('div[contenteditable="true"]');
    await new Promise((resolve) => setTimeout(resolve, 1000));

    // 处理标题
    const titleTextarea = document.querySelector('textarea[placeholder="请输入文章标题（2～30个字）"]');
    if (titleTextarea) {
      (titleTextarea as HTMLTextAreaElement).value = articleData.title?.slice(0, 30) || "";
      titleTextarea.dispatchEvent(new Event("input", { bubbles: true }));
      titleTextarea.dispatchEvent(new Event("change", { bubbles: true }));
    }
    console.log("titleTextarea", titleTextarea);

    // 处理内容
    const editor = document.querySelector('div[contenteditable="true"]') as HTMLElement;
    if (!editor) {
      console.log("未找到编辑器元素");
      return;
    }

    editor.focus();
    const pasteEvent = new ClipboardEvent("paste", {
      bubbles: true,
      cancelable: true,
      clipboardData: new DataTransfer(),
    });
    pasteEvent.clipboardData.setData("text/html", content || "");
    editor.dispatchEvent(pasteEvent);
    editor.dispatchEvent(new Event("input", { bubbles: true }));
    editor.dispatchEvent(new Event("change", { bubbles: true }));

    await new Promise((resolve) => setTimeout(resolve, 5000));
  }

  async function processCover(coverData: FileData): Promise<void> {
    // 清除现有封面
    const clearExistingCovers = async () => {
      for (let i = 0; i < 20; i++) {
        const closeButton = document.querySelector(".article-cover-delete") as HTMLElement;
        if (!closeButton) break;
        console.log("Clicking close button", closeButton);
        closeButton.click();
        await new Promise((resolve) => setTimeout(resolve, 500));
      }
    };

    await clearExistingCovers();

    // 上传新封面
    const uploadButton = document.querySelector('div[class="article-cover-add"]');
    if (!uploadButton) return;

    console.log("Found upload image button");
    uploadButton.dispatchEvent(new Event("click", { bubbles: true }));
    await new Promise((resolve) => setTimeout(resolve, 1000));

    // 切换到上传图片标签
    const tabs = document.querySelectorAll("div.byte-tabs-header-title");
    const uploadTab = Array.from(tabs).find((tab) => tab.textContent?.includes("上传图片"));
    if (uploadTab) {
      uploadTab.dispatchEvent(new Event("click", { bubbles: true }));
      await new Promise((resolve) => setTimeout(resolve, 1000));
    }

    // 上传文件
    const fileInput = document.querySelector('input[type="file"]');
    if (!fileInput) {
      console.log("未找到文件输入元素");
      return;
    }

    const dataTransfer = new DataTransfer();
    console.log("try upload file", coverData);

    const response = await fetch(coverData.url);
    const buffer = await response.arrayBuffer();
    const file = new File([buffer], coverData.name, { type: coverData.type });
    dataTransfer.items.add(file);

    if (dataTransfer.files.length > 0) {
      (fileInput as HTMLInputElement).files = dataTransfer.files;
      fileInput.dispatchEvent(new Event("change", { bubbles: true }));
      fileInput.dispatchEvent(new Event("input", { bubbles: true }));
    }

    await new Promise((resolve) => setTimeout(resolve, 5000));

    // 确认上传
    const confirmButton = document.querySelector('button[data-e2e="imageUploadConfirm-btn"]');
    if (confirmButton) {
      console.log("Clicking confirm button for image upload");
      confirmButton.dispatchEvent(new Event("click", { bubbles: true }));
      await new Promise((resolve) => setTimeout(resolve, 2000));
    }
  }

  // ── 辅助：等待元素出现 ──
  function waitForButton(textPattern: RegExp, timeout = 8000): Promise<Element | null> {
    return new Promise((resolve) => {
      const check = () => {
        const all = document.querySelectorAll("button, a[role=\"button\"], div[role=\"button\"]");
        const found = Array.from(all).find((el) => textPattern.test(el.textContent || "") && (el as HTMLElement).offsetParent !== null);
        if (found) { resolve(found); return; }
        setTimeout(check, 300);
      };
      check();
      setTimeout(() => resolve(null), timeout);
    });
  }

  // ── 辅助：关闭遮挡层 ──
  function closeOverlays() {
    // 点击遮罩层
    const masks = document.querySelectorAll(".byte-drawer-mask, .byte-modal-mask, [class*=\"mask\"]");
    masks.forEach((m) => { try { (m as HTMLElement).click(); } catch {} });
    // 点击关闭按钮
    const closes = document.querySelectorAll(".byte-drawer-close, .byte-modal-close, [class*=\"close\"], [aria-label*=\"关闭\"], [aria-label*=\"close\"]");
    closes.forEach((c) => { try { (c as HTMLElement).click(); } catch {} });
  }

  // ═══════════════════════════════════════════
  //  主流程 — v4: 编辑页填内容，预览页只点发布按钮
  // ═══════════════════════════════════════════
  const isPreviewPage = window.location.href.includes("weitoutiao/publish");
  console.log("[MultiPost-Toutiao] Page:", isPreviewPage ? "预览页" : "编辑页");

  try {
    // ── 预览页模式：只点发布按钮，不填内容 ──
    if (isPreviewPage) {
      console.log("[MultiPost-Toutiao] 预览页：点击发布按钮...");
      const clickBtn = (pattern: RegExp) => {
        const all = document.querySelectorAll("button");
        const btn = Array.from(all).find(b => pattern.test(b.textContent || "") && (b as HTMLElement).offsetParent !== null);
        if (btn) { (btn as HTMLElement).click(); console.log("[MultiPost-Toutiao] 预览页点击:", btn.textContent?.trim()); return true; }
        return false;
      };
      clickBtn(/确认并发布|确定发布|确认/);
      await new Promise((r) => setTimeout(r, 3000));
      clickBtn(/^\s*发布\s*$/);
      await new Promise((r) => setTimeout(r, 2000));
      console.log("[MultiPost-Toutiao] ✅ 预览页发布完成");
      return;
    }

    // ── 编辑页模式：填内容 + 点发布 ──
    console.log("[MultiPost-Toutiao] Step 1: 填充内容...");
    await processContent(articleData.htmlContent);
    console.log("[MultiPost-Toutiao] Step 1: 完成");

    if (processedData.cover) {
      console.log("[MultiPost-Toutiao] Step 2: 上传封面...");
      await processCover(processedData.cover);
      console.log("[MultiPost-Toutiao] Step 2: 完成");
    }

    if (!data.isAutoPublish) {
      console.log("[MultiPost-Toutiao] 手动模式，内容已填充");
      return;
    }

    // Step A: 关闭遮挡 → 点击"预览并发布"
    closeOverlays();
    await new Promise((r) => setTimeout(r, 500));
    const step1 = document.querySelector("button.publish-btn") as HTMLElement;
    if (step1?.textContent?.includes("预览并发布")) {
      console.log("[MultiPost-Toutiao] Step A: 点击「预览并发布」");
      step1.click();
      await new Promise((r) => setTimeout(r, 3000));
    } else {
      console.log("[MultiPost-Toutiao] Step A: 未找到预览并发布按钮");
      return;
    }

    // Step B: 点击确认弹窗中的「确认并发布」
    const confirm = await waitForButton(/确认并发布|确定发布/, 6000);
    if (confirm) {
      console.log("[MultiPost-Toutiao] Step B: 点击「" + (confirm as HTMLElement).textContent?.trim() + "」");
      (confirm as HTMLElement).click();
      await new Promise((r) => setTimeout(r, 3000));
    } else {
      console.log("[MultiPost-Toutiao] Step B: 未找到确认按钮，跳过");
    }

    // Step C: 预览页点击最终「发布」
    const final = await waitForButton(/^\s*发布\s*$/, 6000);
    if (final) {
      console.log("[MultiPost-Toutiao] Step C: 点击最终「发布」");
      (final as HTMLElement).click();
      await new Promise((r) => setTimeout(r, 2000));
    } else {
      console.log("[MultiPost-Toutiao] Step C: 未找到最终发布按钮（可能已发布成功）");
    }

    console.log("[MultiPost-Toutiao] ✅ 发布流程完成");
  } catch (error) {
    const msg = error instanceof Error ? error.message : String(error);
    if (msg.includes("Frame") || msg.includes("removed") || msg.includes("closed")) {
      console.log("[MultiPost-Toutiao] ✅ 页面跳转，发布成功");
    } else {
      console.error("[MultiPost-Toutiao] ❌", msg);
    }
  }
}
