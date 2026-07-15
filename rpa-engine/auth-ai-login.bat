@echo off
chcp 65001 >nul
title 豆流AI — 7平台授权登录
cd /d "%~dp0"

echo ============================================
echo   豆流AI v2.6.0 — AI平台授权登录
echo   每个平台会弹出Edge浏览器窗口
echo   登录完成后关闭浏览器即可，Cookie自动保存
echo ============================================
echo.

set N=1
for %%p in (doubao yuanbao baidu xfyun quark nami douyin) do (
    echo [!N!/7] 正在打开 %%p ...
    start "%%p 授权登录" cmd /c "node -e \"const{chromium}=require('playwright');(async()=>{const b=await chromium.launch({channel:'msedge',headless:false});const p=await b.newPage({viewport:{width:1280,height:800}});const urls={doubao:'https://www.doubao.com/chat/',yuanbao:'https://yuanbao.tencent.com/chat/',baidu:'https://chat.baidu.com/search',xfyun:'https://xinghuo.xfyun.cn/',quark:'https://ai.quark.cn/',nami:'https://bot.n.cn/',douyin:'https://www.douyin.com/aisearch'};console.log('正在打开: %%p');await p.goto(urls['%%p'],{waitUntil:'domcontentloaded',timeout:30000});console.log('请登录 %%p ，完成后关闭此窗口');await new Promise(r=>setTimeout(r,180000));const s=await p.context().storageState();require('fs').mkdirSync('storage/scout',{recursive:true});require('fs').writeFileSync('storage/scout/%%p.json',JSON.stringify(s));console.log('%%p Cookie已保存');await b.close();})()\" && exit"
    timeout /t 3 >nul
    set /a N+=1
)
echo.
echo ============================================
echo   全部7个平台授权完成！
echo   Cookie已保存到 rpa-engine/storage/scout/
echo ============================================
pause
