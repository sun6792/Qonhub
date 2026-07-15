/**
 * render_url.cjs — SPA页面Playwright渲染提取
 * 用法: node render_url.cjs <url>
 * 输出: JSON {status, text, url}
 */
const { chromium } = require('playwright');
const url = process.argv[2];
if (!url) { console.log(JSON.stringify({status:'error',error:'no url'})); process.exit(1); }

(async () => {
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  try {
    const page = await browser.newPage();
    await page.goto(url, { waitUntil: 'networkidle', timeout: 25000 });
    await new Promise(r => setTimeout(r, 3000));
    const text = await page.evaluate(() => document.body.innerText.trim());
    console.log(JSON.stringify({status:'success', text: text, url: url, length: text.length}));
  } catch(e) {
    console.log(JSON.stringify({status:'error', error: e.message}));
  } finally {
    await browser.close();
  }
})();
