@echo off
chcp 65001 >nul
title Qonhub Worker 守护进程

set PHP=C:\php8.4\php.exe
set PROJECT=E:\Qonhubgeo\GEOFlow-main
set MAX_RESTARTS=100

cd /d "%PROJECT%"

echo ============================================
echo   Qonhub AI Worker 守护进程
echo   自动监控，崩溃秒级重启
echo ============================================
echo.

set restart_count=0

:LOOP
set /a restart_count+=1

echo [%date% %time%] Worker 启动 (第%restart_count%次)

REM 记录日志
echo [%date% %time%] Worker started (restart #%restart_count%) >> "%PROJECT%\storage\logs\watchdog.log"

REM 运行 Worker，捕获退出码
"%PHP%" artisan queue:work --queue=geoflow,distribution --sleep=3 --tries=1 --timeout=300

set exit_code=%errorlevel%

echo [%date% %time%] Worker 退出 (code: %exit_code%)，3秒后自动重启...
echo [%date% %time%] Worker exited (code: %exit_code%) >> "%PROJECT%\storage\logs\watchdog.log"

REM 如果重启次数超限，可能是严重错误，停止重启
if %restart_count% GEQ %MAX_RESTARTS% (
    echo.
    echo !!! 已连续重启 %MAX_RESTARTS% 次，可能存在问题请检查！
    echo [%date% %time%] CRITICAL: %MAX_RESTARTS% consecutive restarts, giving up >> "%PROJECT%\storage\logs\watchdog.log"
    pause
    exit /b 1
)

timeout /t 3 >nul
goto LOOP
