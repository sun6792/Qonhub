/**
 * CDP常驻模式授权 — 连接已运行的浏览器，永不卡退
 * 先运行 start-browser.bat 启动常驻浏览器
 * 然后: node auth-cdp.cjs toutiao
 */
const { chromium } = require('playwright');

const platform = process.argv[2];
if (!platform) { console.log('用法: node auth-cdp.cjs <platform>'); process.exit(1); }

const PLATFORMS = {
  toutiao:     { url: 'https://mp.toutiao.com/',          name: '头条号' },
  baijiahao:   { url: 'https://baijiahao.baidu.com/',      name: '百家号' },
  sohu:        { url: 'https://mp.sohu.com/',              name: '搜狐号' },
  xiaohongshu: { url: 'https://creator.xiaohongshu.com/',  name: '小红书' },
};
const cfg = PLATFORMS[platform];
if (!cfg) { console.log('未知平台: ' + platform); process.exit(1); }

(async () => {
  try {
    // 连接已运行的浏览器（CDP），不启动新浏览器
    const browser = await chromium.connectOverCDP('http://127.0.0.1:9222');
    const context = browser.contexts()[0];
    const page = await context.newPage();

    await page.goto(cfg.url, { waitUntil: 'domcontentloaded', timeout: 20000 });
    console.log(`${cfg.name} 已打开，请在浏览器中登录。`);
    console.log('登录完成后 Cookie 自动持久化，永远不需要重新登录。');
    console.log('浏览器窗口保持打开，登录完关闭此终端即可。');

    // 保持运行
    await new Promise(() => {});
  } catch (e) {
    console.log('❌ 连接失败！请先双击运行 start-browser.bat 启动常驻浏览器');
    console.log('错误: ' + e.message);
  }
})();
