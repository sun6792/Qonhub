const { chromium } = require('playwright');
const fs = require('fs');
const { execSync } = require('child_process');

const PLATFORMS = [
  { key: 'doubao', name: '豆包', url: 'https://www.doubao.com/chat/', sel: 'textarea' },
  { key: 'yuanbao', name: '腾讯元宝', url: 'https://yuanbao.tencent.com/chat/', sel: 'textarea' },
  { key: 'baidu', name: '百度AI', url: 'https://chat.baidu.com/search', sel: 'textarea' },
];

async function searchOne(cfg) {
  const stateFile = `storage/scout/${cfg.key}.json`;
  if (!fs.existsSync(stateFile)) { console.log(`${cfg.name}: no cookie, skip`); return null; }
  
  const state = JSON.parse(fs.readFileSync(stateFile, 'utf-8'));
  const b = await chromium.launch({ channel: 'msedge', headless: true });
  const ctx = await b.newContext({ storageState: state, viewport: { width: 1280, height: 800 } });
  const p = await ctx.newPage();
  
  await p.goto(cfg.url, { waitUntil: 'domcontentloaded', timeout: 20000 });
  await p.waitForTimeout(4000);
  
  const el = await p.locator(cfg.sel).first();
  if (await el.count() === 0) { console.log(`${cfg.name}: no input`); await b.close(); return null; }
  
  await el.click(); await p.waitForTimeout(500);
  await el.fill('豆流AI是什么'); await p.waitForTimeout(500);
  await p.keyboard.press('Enter'); await p.waitForTimeout(12000);
  
  const resp = await p.evaluate(() => document.body.innerText);
  await b.close();
  
  // Extract answer after the query
  const idx = resp.indexOf('豆流AI是什么');
  const answer = idx > -1 ? resp.substring(idx + 8).trim().substring(0, 3000) : resp.substring(0, 3000);

  // Extract cited URLs from the answer
  const urlRegex = /https?:\/\/[^\s\]\)>\"一-鿿]+/g;
  const rawUrls = answer.match(urlRegex) || [];
  const citedUrls = [...new Set(rawUrls)]
    .filter(u => !u.includes('doubao.com') && !u.includes('bytedance') && !u.includes('example'))
    .slice(0, 15);

  return {
    platform: cfg.key,
    name: cfg.name,
    mentioned: false,
    answer: answer,
    cited_urls: citedUrls,
    snapshot_at: new Date().toISOString(),
  };
}

(async () => {
  const results = [];
  for (const cfg of PLATFORMS) {
    console.log(`\n=== ${cfg.name} ===`);
    const r = await searchOne(cfg);
    if (r) {
      console.log(r.answer.substring(0, 500));
      results.push(r);
    }
  }
  
  // Save results as JSON
  fs.writeFileSync('storage/scout/results.json', JSON.stringify(results, null, 2));
  console.log(`\nSaved ${results.length} results to storage/scout/results.json`);

  // Report cited sources to Laravel API (方案A: 直接调PHP API写入ai_cited_sources)
  const wsId = parseInt(process.argv[2] || '0');
  const apiUrl = process.env.GEOFLOW_API_URL || 'http://127.0.0.1:18080/geo_admin';
  const apiKey = process.env.RPA_API_KEY || '';

  if (wsId > 0 && apiKey) {
    for (const r of results) {
      if (!r.cited_urls || r.cited_urls.length === 0) continue;
      try {
        const body = {
          workspace_id: wsId,
          platform: r.platform,
          results: r.cited_urls.map(url => ({ url, domain: new URL(url).hostname, title: null, excerpt: null })),
        };
        const resp = await fetch(`${apiUrl}/api/v1/rpa/scout/report`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-Api-Key': apiKey },
          body: JSON.stringify(body),
        });
        console.log(`  → ${r.name}: reported ${r.cited_urls.length} URLs (HTTP ${resp.status})`);
      } catch (e) {
        console.log(`  → ${r.name}: report failed — ${e.message}`);
      }
    }
  }
})();
