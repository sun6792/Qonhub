@echo off
chcp 65001 >nul
title Qonhub 运营助手 - 桌面模式

echo.
echo ╔══════════════════════════════════════╗
echo ║   Qonhub AI 运营助手 (桌面模式)      ║
echo ║   浏览器可见操作 · Cookie本地存储     ║
echo ╚══════════════════════════════════════╝
echo.
echo 启动后将打开两个窗口:
echo   1. 运营助手控制台 (localhost:9901)
echo   2. 客户端看板 (localhost:18080/client)
echo.
echo 浏览器操作过程全程可见，Cookie保存在本地。
echo.
echo 按任意键启动...
pause >nul

start http://127.0.0.1:9901
start http://127.0.0.1:18080/client/login

echo.
echo ✅ 运营助手已启动 (桌面模式)
echo    Dashboard: http://127.0.0.1:9901
echo    客户端看板: http://127.0.0.1:18080/client
echo.
echo ⚠️  请勿关闭本窗口，关闭将停止RPA引擎
echo.
node server.js
