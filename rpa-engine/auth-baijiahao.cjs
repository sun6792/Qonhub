// 百家号授权登录
const { chromium } = require('playwright');
const fs = require('fs');
(async () => {
  const b = await chromium.launch({ channel: 'msedge', headless: false });
  const p = await b.newPage({ viewport: { width: 1280, height: 800 } });
  await p.goto('https://baijiahao.baidu.com/', { waitUntil: 'domcontentloaded', timeout: 20000 });
  console.log('百家号已打开，请登录。完成后关闭窗口。');
  await new Promise(r => setTimeout(r, 300000));
  const s = await p.context().storageState();
  fs.mkdirSync('storage/states/8', { recursive: true });
  fs.writeFileSync('storage/states/8/baijiahao.json', JSON.stringify(s));
  console.log('Cookie saved');
  await b.close();
})();
