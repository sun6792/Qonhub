const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const PLATFORMS = [
  { key: 'doubao', name: '豆包AI', url: 'https://www.doubao.com/chat/' },
  { key: 'yuanbao', name: '腾讯元宝', url: 'https://yuanbao.tencent.com/chat/' },
  { key: 'baidu', name: '百度AI', url: 'https://chat.baidu.com/search' },
  { key: 'xfyun', name: '讯飞星火', url: 'https://xinghuo.xfyun.cn/' },
  { key: 'quark', name: '夸克AI', url: 'https://ai.quark.cn/' },
  { key: 'nami', name: '纳米AI', url: 'https://bot.n.cn/' },
  { key: 'douyin', name: '抖音AI', url: 'https://www.douyin.com/aisearch' },
];

const STATE_DIR = path.join(__dirname, '..', 'rpa-engine', 'storage', 'scout');
if (!fs.existsSync(STATE_DIR)) fs.mkdirSync(STATE_DIR, { recursive: true });

async function loginOne(platform) {
  const browser = await chromium.launch({ channel: 'msedge', headless: false });
  const page = await browser.newPage({ viewport: { width: 1280, height: 800 } });
  
  console.log(`\n========================================`);
  console.log(`  ${platform.name} - 请在浏览器中登录`);
  console.log(`========================================`);
  
  await page.goto(platform.url, { waitUntil: 'domcontentloaded', timeout: 30000 });

  // 轮询检测登录完成（最多2分钟，每5秒检查一次）
  console.log('等待登录中... (最长2分钟，登录完成自动继续)');
  const startTime = Date.now();
  const maxWait = 120000;  // 2分钟上限
  let loggedIn = false;

  while (!loggedIn && (Date.now() - startTime) < maxWait) {
    await new Promise(r => setTimeout(r, 5000));  // 每5秒检查一次
    try {
      const url = page.url();
      const title = await page.title().catch(() => '');
      // 检测登录成功：URL 跳转到非登录页，或页面标题包含用户内容
      if (!url.includes('/login') && !url.includes('/signin') && title) {
        loggedIn = true;
        console.log(`检测到登录完成: ${title}`);
        break;
      }
    } catch { /* 页面加载中，继续等待 */ }
  }

  if (!loggedIn) {
    console.log('⚠️ 登录超时（2分钟），将保存当前状态');
  }
  
  const state = await page.context().storageState();
  const stateFile = path.join(STATE_DIR, `${platform.key}.json`);
  fs.writeFileSync(stateFile, JSON.stringify(state, null, 2));
  console.log(`Cookie saved: ${stateFile}`);
  
  await browser.close();
}

(async () => {
  for (let i = 0; i < PLATFORMS.length; i++) {
    console.log(`\n[${i+1}/7] ${PLATFORMS[i].name}`);
    await loginOne(PLATFORMS[i]);
  }
  console.log('\n========================================');
  console.log('  全部7个平台授权完成！');
  console.log('========================================');
})();
