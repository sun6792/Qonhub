@echo off
chcp 65001 >nul
set REDIS_DIR=E:\Qonhubgeo\GEOFlow-main\redis

echo 正在停止 Redis...
"%REDIS_DIR%\redis-cli.exe" shutdown 2>nul
if %errorlevel%==0 (
    echo [OK] Redis 已停止
) else (
    echo Redis 可能未在运行，强制结束进程...
    taskkill /F /IM redis-server.exe >nul 2>&1
    echo [OK] 已清理
)
pause
