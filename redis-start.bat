@echo off
chcp 65001 >nul
title Qonhub Redis

set REDIS_DIR=E:\Qonhubgeo\GEOFlow-main\redis

cd /d "%REDIS_DIR%"

REM 检查是否已在运行
"%REDIS_DIR%\redis-cli.exe" ping >nul 2>&1
if %errorlevel%==0 (
    echo [OK] Redis 已在运行
    "%REDIS_DIR%\redis-cli.exe" info server | findstr redis_version
    pause
    exit /b
)

REM 确保数据目录存在
if not exist "%REDIS_DIR%\data" mkdir "%REDIS_DIR%\data"

echo 正在启动 Redis...
start "Qonhub-Redis" /MIN "%REDIS_DIR%\redis-server.exe" redis.qonhub.conf
timeout /t 2 >nul

REM 验证启动
"%REDIS_DIR%\redis-cli.exe" ping >nul 2>&1
if %errorlevel%==0 (
    echo [OK] Redis 启动成功 (127.0.0.1:6379)
) else (
    echo [ERROR] Redis 启动失败，查看日志: %REDIS_DIR%\data\redis.log
)
pause
