const { chromium } = require('playwright');
const fs = require('fs');
(async () => {
  const state = JSON.parse(fs.readFileSync('storage/scout/doubao.json', 'utf-8'));
  const b = await chromium.launch({ channel: 'msedge', headless: true });
  const ctx = await b.newContext({ storageState: state, viewport: { width: 1280, height: 800 } });
  const p = await ctx.newPage();
  
  await p.goto('https://www.doubao.com/chat/', { waitUntil: 'networkidle', timeout: 30000 });
  await p.waitForTimeout(4000);
  
  // 1. 找并点击"联网搜索"开关
  const searchToggle = await p.locator('text=联网搜索, [class*=search], [class*=web], div:has-text("联网")').first();
  if (await searchToggle.count() > 0) {
    console.log('找到联网搜索开关，点击启用');
    await searchToggle.click();
    await p.waitForTimeout(1000);
  }
  
  // 也尝试找搜索按钮/图标
  const searchBtn = await p.locator('[class*=search-btn], [class*=deep], button:has-text("搜索")').first();
  if (await searchBtn.count() > 0) {
    console.log('找到搜索按钮');
    await searchBtn.click();
    await p.waitForTimeout(1000);
  }
  
  // 2. 输入
  const el = await p.locator('textarea').first();
  if (await el.count() === 0) { console.log('no textarea'); await b.close(); return; }
  await el.click(); await p.waitForTimeout(300);
  await el.fill('豆流AI是什么'); await p.waitForTimeout(300);
  await p.keyboard.press('Enter');
  
  console.log('等待回复...');
  await p.waitForTimeout(15000);
  
  const resp = await p.evaluate(() => document.body.innerText);
  const idx = resp.indexOf('豆流AI是什么');
  const answer = idx > -1 ? resp.substring(idx).substring(0, 4000) : resp.substring(0, 4000);
  console.log('\n=== 结果 ===');
  console.log(answer);
  
  await b.close();
})();
