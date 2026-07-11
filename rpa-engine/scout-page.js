/**
 * 页面侦察工具：访问目标 URL，输出所有表单元素，用于编写 RPA 脚本。
 * 用法: node scout-page.js <url>
 */
import { chromium } from "playwright";

const url = process.argv[2] || "https://www.b2b168.com/register/";

const browser = await chromium.launch({ headless: false });
const page = await browser.newPage({ viewport: { width: 1366, height: 768 } });

console.log(`Navigating to: ${url}`);
await page.goto(url, { waitUntil: "domcontentloaded", timeout: 30000 });
await page.waitForTimeout(3000);

console.log("\n=== PAGE TITLE ===");
console.log(await page.title());

console.log("\n=== ALL INPUT FIELDS ===");
const inputs = await page.$$("input, textarea, select");
for (const el of inputs) {
    const tag = await el.evaluate(e => e.tagName.toLowerCase());
    const name = await el.getAttribute("name") || "";
    const id = await el.getAttribute("id") || "";
    const type = await el.getAttribute("type") || "";
    const placeholder = await el.getAttribute("placeholder") || "";
    const label = await el.evaluate(e => {
        const lbl = e.closest("label") || document.querySelector(`label[for="${e.id}"]`);
        return lbl ? lbl.textContent.trim() : "";
    });
    console.log(`  ${tag}[name="${name}"][id="${id}"][type="${type}"] placeholder="${placeholder}" label="${label}"`);
}

console.log("\n=== ALL BUTTONS ===");
const buttons = await page.$$("button, a.btn, input[type='submit']");
for (const el of buttons) {
    const tag = await el.evaluate(e => e.tagName.toLowerCase());
    const text = await el.textContent();
    const href = await el.getAttribute("href") || "";
    console.log(`  ${tag} "${text.trim()}" href="${href}"`);
}

console.log("\n=== ALL LINKS ===");
const links = await page.$$("a");
for (const el of links) {
    const text = await el.textContent();
    const href = await el.getAttribute("href") || "";
    if (text.trim() && (href.includes("register") || href.includes("login") || href.includes("cert") || href.includes("member") || href.includes("company") || href.includes("shop"))) {
        console.log(`  a "${text.trim()}" → ${href}`);
    }
}

await page.screenshot({ path: "./screenshots/scout_page.png", fullPage: true });
console.log("\nScreenshot saved: ./screenshots/scout_page.png");
console.log("\nDone. Close the browser window or press Ctrl+C.");

// Keep browser open for manual inspection
await new Promise(() => {});
