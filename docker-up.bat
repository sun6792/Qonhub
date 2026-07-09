@echo off
chcp 65001 >nul
title Qonhub AI Docker 部署

echo ============================================
echo   Qonhub AI Docker 容器化部署
echo ============================================
echo.
echo 注意：Docker 需要 .env 中以下配置与本地不同：
echo   DB_HOST=postgres  (不是 127.0.0.1)
echo   QUEUE_CONNECTION=redis  (不是 database)
echo   BROADCAST_CONNECTION=reverb  (不是 null)
echo.
echo 本脚本会自动检查并提示修复。

REM 检查 .env 中的关键配置
findstr /C:"DB_HOST=127.0.0.1" .env >nul 2>&1
if %errorlevel%==0 (
    echo [警告] DB_HOST=127.0.0.1 不适合 Docker，正在自动修正...
    powershell -Command "(Get-Content .env) -replace 'DB_HOST=127.0.0.1', 'DB_HOST=postgres' | Set-Content .env"
    echo [OK] DB_HOST 已改为 postgres
)

findstr /C:"QUEUE_CONNECTION=database" .env >nul 2>&1
if %errorlevel%==0 (
    echo [警告] QUEUE_CONNECTION=database 不适合 Docker，正在自动修正...
    powershell -Command "(Get-Content .env) -replace 'QUEUE_CONNECTION=database', 'QUEUE_CONNECTION=redis' | Set-Content .env"
    echo [OK] QUEUE_CONNECTION 已改为 redis
)

findstr /C:"BROADCAST_CONNECTION=null" .env >nul 2>&1
if %errorlevel%==0 (
    echo [警告] BROADCAST_CONNECTION=null 不适合 Docker，正在自动修正...
    powershell -Command "(Get-Content .env) -replace 'BROADCAST_CONNECTION=null', 'BROADCAST_CONNECTION=reverb' | Set-Content .env"
    echo [OK] BROADCAST_CONNECTION 已改为 reverb
)

findstr /C:"CACHE_STORE=database" .env >nul 2>&1
if %errorlevel%==0 (
    powershell -Command "(Get-Content .env) -replace 'CACHE_STORE=database', 'CACHE_STORE=redis' | Set-Content .env"
    echo [OK] CACHE_STORE 已改为 redis
)

findstr /C:"SESSION_DRIVER=database" .env >nul 2>&1
if %errorlevel%==0 (
    powershell -Command "(Get-Content .env) -replace 'SESSION_DRIVER=database', 'SESSION_DRIVER=redis' | Set-Content .env"
    echo [OK] SESSION_DRIVER 已改为 redis
)

echo.
echo === 配置检查完成，开始启动 Docker ===
echo.

docker-compose up -d --build

echo.
echo === Docker 服务状态 ===
docker-compose ps

echo.
echo ============================================
echo   启动完成！
echo   主站: http://localhost:18080
echo   后台: http://localhost:18080/geo_admin/login
echo.
echo   停止命令: docker-compose down
echo ============================================
pause
