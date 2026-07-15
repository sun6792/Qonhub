// 头条号授权登录 — 双击运行或 node auth-toutiao.cjs
const { chromium } = require('playwright');
const fs = require('fs');
(async () => {
  const b = await chromium.launch({ channel: 'msedge', headless: false });
  const p = await b.newPage({ viewport: { width: 1280, height: 800 } });
  await p.goto('https://mp.toutiao.com/', { waitUntil: 'domcontentloaded', timeout: 20000 });
  console.log('头条号已打开，请在浏览器中登录。登录完成后关闭窗口。');
  // 等5分钟让用户登录
  await new Promise(r => setTimeout(r, 300000));
  const s = await p.context().storageState();
  fs.mkdirSync('storage/states/8', { recursive: true });
  fs.writeFileSync('storage/states/8/toutiao.json', JSON.stringify(s));
  console.log('Cookie saved to storage/states/8/toutiao.json');
  await b.close();
})();
