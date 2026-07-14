@echo off
chcp 65001 >nul
title 豆流 AI 自启动

set PHP=C:\php8.4\php.exe
set PROJECT=E:\Qonhubgeo\GEOFlow-main
set REDIS_DIR=E:\Qonhubgeo\GEOFlow-main\redis
set HOST=127.0.0.1
set PORT=18080

cd /d "%PROJECT%"

echo [%date% %time%] 豆流 AI 自动启动中...
echo [%date% %time%] Auto-start >> "%PROJECT%\storage\logs\autostart.log" 2>nul

REM ====== 1. Redis ======
"%REDIS_DIR%\redis-cli.exe" ping >nul 2>&1
if %errorlevel% neq 0 (
    echo   Starting Redis...
    start "DL-Redis" /MIN "%REDIS_DIR%\redis-server.exe" "%REDIS_DIR%\redis.qonhub.conf"
    timeout /t 2 >nul
)

REM ====== 2. Laravel 主站 ======
curl -s -o NUL http://%HOST%:%PORT% 2>nul
if %errorlevel% neq 0 (
    echo   Starting Laravel...
    powershell -Command "Start-Process -FilePath '%PHP%' -ArgumentList '-d max_execution_time=0 artisan serve --host=%HOST% --port=%PORT%' -WorkingDirectory '%PROJECT%' -WindowStyle Minimized" >nul 2>&1
    timeout /t 3 >nul
)

REM ====== 3. 队列 Worker ======
echo   Starting Worker...
powershell -Command "Start-Process -FilePath '%PHP%' -ArgumentList '-d max_execution_time=0 artisan queue:work redis --queue=geoflow,distribution --sleep=3 --tries=1 --timeout=600 --max-jobs=0 --max-time=0' -WorkingDirectory '%PROJECT%' -WindowStyle Minimized" >nul 2>&1

REM ====== 4. RPA 引擎 ======
curl -s -o NUL http://%HOST%:9901 2>nul
if %errorlevel% neq 0 (
    echo   Starting RPA...
    powershell -Command "Start-Process -FilePath 'node' -ArgumentList 'server.js' -WorkingDirectory '%PROJECT%\rpa-engine' -WindowStyle Minimized" >nul 2>&1
)

echo [%date% %time%] 全部启动完成
echo [%date% %time%] Started OK >> "%PROJECT%\storage\logs\autostart.log" 2>nul
