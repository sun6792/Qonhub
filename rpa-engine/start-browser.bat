@echo off
chcp 65001 >nul
title 豆流AI - 浏览器常驻（最小化勿关）
echo 浏览器常驻已启动，最小化此窗口即可，不要关闭。
echo 所有授权、发文、检测都通过此浏览器执行。
start msedge --remote-debugging-port=9222 --user-data-dir="E:\Qonhubgeo\GEOFlow-main\storage\browser-profile\shared" --no-first-run --no-default-browser-check
echo Edge已启动(调试端口9222)，此窗口可最小化。
pause
