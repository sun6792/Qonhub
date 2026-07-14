@echo off
chcp 65001 >nul
title Qonhub Worker 守护进程

set PHP=C:\php8.4\php.exe
set PROJECT=E:\Qonhubgeo\GEOFlow-main
set MAX_QUICK_CRASHES=5
set QUICK_CRASH_WINDOW=30

cd /d "%PROJECT%"

echo ============================================
echo   豆流 AI Worker 守护进程
echo   持久运行，崩溃自动恢复
echo ============================================
echo.

set restart_count=0
set crash_times=0
set last_crash_time=0

:LOOP
set /a restart_count+=1

echo [%date% %time%] Worker 启动 (第%restart_count%次)
echo [%date% %time%] Worker started (restart #%restart_count%) >> "%PROJECT%\storage\logs\watchdog.log"

REM 记录本次启动时间（秒级精度用循环序号代替）
set start_loop=%restart_count%

REM 运行 Worker：max_execution_time=0 防止超时退出
REM --max-jobs=0 --max-time=0 表示永不因任务数量/时间限制而退出
"%PHP%" -d max_execution_time=0 artisan queue:work redis --queue=geoflow,distribution --sleep=3 --tries=1 --timeout=600 --max-jobs=0 --max-time=0

set exit_code=%errorlevel%
set /a elapsed_loops=%restart_count% - %start_loop%

echo [%date% %time%] Worker 退出 (code: %exit_code%, 存活约%elapsed_loops%轮)，3秒后自动重启...
echo [%date% %time%] Worker exited (code: %exit_code%) >> "%PROJECT%\storage\logs\watchdog.log"

REM 快速崩溃检测：如果短时间内连续崩溃，增加等待时间
if %elapsed_loops% LEQ 1 (
    set /a crash_times+=1
    if !crash_times! GEQ %MAX_QUICK_CRASHES% (
        echo.
        echo !!! Worker 连续快速崩溃 %MAX_QUICK_CRASHES% 次，可能存在严重错误
        echo     等待 60 秒后重试...
        echo [%date% %time%] RAPID CRASH DETECTED: %MAX_QUICK_CRASHES% times, waiting 60s >> "%PROJECT%\storage\logs\watchdog.log"
        timeout /t 60 >nul
        set crash_times=0
    )
) else (
    REM 正常运行了一段时间才退出，重置快速崩溃计数
    set crash_times=0
)

timeout /t 3 >nul
goto LOOP
