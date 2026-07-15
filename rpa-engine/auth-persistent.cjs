/**
 * 持久化授权登录 — 登录一次，永久有效
 * 用法: node auth-persistent.cjs <platform>
 * 平台: toutiao | baijiahao | sohu | xiaohongshu | doubao | yuanbao | ...
 */
const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

const platform = process.argv[2];
if (!platform) { console.log('用法: node auth-persistent.cjs <platform>'); process.exit(1); }

const PLATFORMS = {
  toutiao:     { url: 'https://mp.toutiao.com/',          name: '头条号' },
  baijiahao:   { url: 'https://baijiahao.baidu.com/',      name: '百家号' },
  sohu:        { url: 'https://mp.sohu.com/',              name: '搜狐号' },
  xiaohongshu: { url: 'https://creator.xiaohongshu.com/',  name: '小红书' },
  doubao:      { url: 'https://www.doubao.com/chat/',      name: '豆包' },
  yuanbao:     { url: 'https://yuanbao.tencent.com/chat/', name: '腾讯元宝' },
};
const cfg = PLATFORMS[platform];
if (!cfg) { console.log('未知平台: ' + platform); process.exit(1); }

// ══ 关键：固定持久化目录，登录一次永久有效 ══
const PROFILE_DIR = path.join(__dirname, 'storage', 'browser-profile', 'shared');

(async () => {
  // launchPersistentContext — 像真实浏览器，关了再开Cookie还在
  const ctx = await chromium.launchPersistentContext(PROFILE_DIR, {
    channel: 'msedge',
    headless: false,  // 可见，让用户手动登录
    viewport: { width: 1280, height: 800 },
  });

  const page = ctx.pages()[0] || await ctx.newPage();
  await page.goto(cfg.url, { waitUntil: 'domcontentloaded', timeout: 30000 });

  console.log(`${cfg.name} 已打开，请在浏览器中登录。`);
  console.log('登录完成后，Cookie 自动持久化到: ' + PROFILE_DIR);
  console.log('下次任何脚本用 launchPersistentContext 指向同一目录，自动加载登录态。');
  console.log('浏览器窗口保持打开，登录完成后手动关闭即可。');

  // 保持运行直到用户关闭
  await new Promise(() => {}); // 永久等待
})();
