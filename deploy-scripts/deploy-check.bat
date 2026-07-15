@echo off
chcp 65001 >nul
title 豆流AI v2.6.0 部署自检

set PHP=C:\php8.4\php.exe
set PROJECT=%~dp0..

echo ============================================
echo   豆流 AI v2.6.0 部署自检
echo ============================================
echo.

echo [1/5] PHP 依赖...
cd /d "%PROJECT%"
if exist "vendor\autoload.php" (
    echo [OK] PHP 依赖已安装
) else (
    echo [安装] composer install --no-dev
    composer install --no-dev
)

echo [2/5] Node.js 依赖...
cd /d "%PROJECT%\rpa-engine"
if exist "node_modules\.package-lock.json" (
    echo [OK] Node.js 依赖已安装
) else (
    echo [安装] npm install
    npm install
)

echo [3/5] Playwright 浏览器...
npx playwright install chromium --dry-run >nul 2>&1
if %errorlevel%==0 (
    echo [OK] Playwright 浏览器已安装
) else (
    echo [安装] npx playwright install chromium (约300MB)
    npx playwright install chromium
)

echo [4/5] 环境配置...
cd /d "%PROJECT%"
if exist ".env" (
    echo [OK] .env 已存在
) else (
    copy .env.example .env
    echo [WARN] 请编辑 .env 填入配置
)

echo [5/5] 数据库迁移...
%PHP% artisan migrate --force 2>nul && echo [OK] 迁移完成 || echo [WARN] 迁移失败，检查 .env 数据库配置
%PHP% artisan optimize:clear >nul

echo.
echo ============================================
echo   部署自检完成
echo   启动: 运行项目根目录的 qonhub.bat
echo ============================================
pause
