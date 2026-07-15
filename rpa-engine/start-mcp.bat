@echo off
chcp 65001 >nul
title Playwright MCP 浏览器自动化服务

set MCP_PORT=8932
set LOG_DIR=%~dp0logs
set NVM_SYMLINK=C:\Program Files\nodejs

if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"

echo ============================================
echo   Playwright MCP 浏览器自动化服务
echo   端口: %MCP_PORT%
echo   日志: %LOG_DIR%\mcp.log
echo ============================================
echo.

set restart_count=0

:LOOP
set /a restart_count+=1

echo [%date% %time%] MCP 启动 (第%restart_count%次)
echo [%date% %time%] MCP started (restart #%restart_count%) >> "%LOG_DIR%\mcp.log"

npx @playwright/mcp --port %MCP_PORT% >> "%LOG_DIR%\mcp.log" 2>&1

echo [%date% %time%] MCP 退出 (code: %errorlevel%)，5秒后自动重启...
echo [%date% %time%] MCP exited (code: %errorlevel%) >> "%LOG_DIR%\mcp.log"

timeout /t 5 >nul
goto LOOP
