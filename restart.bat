@echo off
chcp 65001 >nul
title 豆流 AI 进程守护

set PHP=C:\php8.4\php.exe
set PROJECT=E:\Qonhubgeo\GEOFlow-main
set HOST=127.0.0.1
set PORT=18080
set WORKER_QUEUES=geoflow,distribution
set WORKER_TIMEOUT=600

cd /d "%PROJECT%"

:MENU
cls
echo ============================================
echo   豆流 AI 进程守护
echo ============================================
echo.
echo   [1] 一键重启全部（推荐：杀进程 + 启动）
echo   [2] 仅杀掉全部进程
echo   [3] 仅启动全部服务
echo   [4] 查看运行状态
echo   [0] 退出
echo.
set /p choice=请输入选项:

if "%choice%"=="1" goto RESTART_ALL
if "%choice%"=="2" goto KILL_ALL
if "%choice%"=="3" goto START_ALL
if "%choice%"=="4" goto STATUS
if "%choice%"=="0" exit /b
goto MENU

REM ═══════════════════════════════════════════════
REM  重启全部：杀干净 → 等 2 秒 → 启动
REM ═══════════════════════════════════════════════
:RESTART_ALL
echo.
echo [1/4] 杀掉全部 PHP 进程...
call :KILL_PHP
echo [2/4] 启动 Laravel 主站...
call :START_LARAVEL
echo [3/4] 启动队列 Worker...
call :START_WORKER
echo [4/4] 启动 RPA 引擎...
call :START_RPA
echo.
echo ============================================
echo   全部重启完成！所有进程已加载最新代码
echo   主站: http://%HOST%:%PORT%
echo   后台: http://%HOST%:%PORT%/geo_admin/login
echo   客户端: http://%HOST%:%PORT%/client/login
echo   RPA: http://%HOST%:9901
echo ============================================
pause
goto MENU

REM ═══════════════════════════════════════════════
REM  仅杀进程
REM ═══════════════════════════════════════════════
:KILL_ALL
echo.
call :KILL_PHP
echo.
echo 所有 PHP 进程已清除（PostgreSQL 和 Redis 未动）
pause
goto MENU

REM ═══════════════════════════════════════════════
REM  仅启动
REM ═══════════════════════════════════════════════
:START_ALL
echo.
echo [1/3] 启动 Laravel 主站...
call :START_LARAVEL
echo [2/3] 启动队列 Worker...
call :START_WORKER
echo [3/3] 启动 RPA 引擎...
call :START_RPA
echo.
echo 全部启动完成。
pause
goto MENU

REM ═══════════════════════════════════════════════
REM  状态
REM ═══════════════════════════════════════════════
:STATUS
echo.
echo === PHP 进程 ===
tasklist /FI "IMAGENAME eq php.exe" /FO TABLE 2>nul | findstr php
echo.
echo === Laravel (%PORT%) ===
curl -s -o NUL -w "HTTP %%{http_code}" http://%HOST%:%PORT% 2>nul
echo.
echo === RPA (9901) ===
curl -s -o NUL -w "HTTP %%{http_code}" http://%HOST%:9901 2>nul
echo.
echo === Redis ===
"%PROJECT%\redis\redis-cli.exe" ping 2>nul
echo.
echo === PostgreSQL ===
"C:\pgsql\bin\pg_isready.exe" -h %HOST% -p 5432 2>nul
echo.
pause
goto MENU

REM ═══════════════════════════════════════════════
REM  子函数
REM ═══════════════════════════════════════════════

:KILL_PHP
REM 杀掉所有 php.exe（PHPStudy 的保留）
for /f "tokens=2" %%i in ('tasklist /FI "IMAGENAME eq php.exe" /FO TABLE 2^>nul ^| findstr "php.exe"') do (
    echo   终止 PID %%i
    taskkill /F /PID %%i >nul 2>&1
)
REM 等待进程完全退出
timeout /t 2 >nul
exit /b

:START_LARAVEL
REM 先检查端口是否已在使用
curl -s -o NUL http://%HOST%:%PORT% 2>nul
if %errorlevel%==0 (
    echo   [OK] 主站已在运行 (:%PORT%)
    exit /b
)
REM PowerShell 独立进程启动，不受当前窗口影响
powershell -Command "Start-Process -FilePath '%PHP%' -ArgumentList '-d max_execution_time=0 artisan serve --host=%HOST% --port=%PORT%' -WorkingDirectory '%PROJECT%' -WindowStyle Minimized" >nul 2>&1
timeout /t 3 >nul
curl -s -o NUL http://%HOST%:%PORT% 2>nul
if %errorlevel%==0 (echo   [OK] 主站已启动) else (echo   [ERROR] 主站启动失败)
exit /b

:START_WORKER
powershell -Command "Start-Process -FilePath '%PHP%' -ArgumentList '-d max_execution_time=0 artisan queue:work redis --queue=%WORKER_QUEUES% --sleep=3 --tries=1 --timeout=%WORKER_TIMEOUT% --max-jobs=0 --max-time=0' -WorkingDirectory '%PROJECT%' -WindowStyle Minimized" >nul 2>&1
timeout /t 2 >nul
tasklist /FI "IMAGENAME eq php.exe" 2>nul | findstr php.exe | findstr /V "phpStudyServer" >nul
if %errorlevel%==0 (echo   [OK] Worker 已启动) else (echo   [ERROR] Worker 启动失败)
exit /b

:START_RPA
curl -s -o NUL http://%HOST%:9901 2>nul
if %errorlevel%==0 (
    echo   [OK] RPA 引擎已在运行 (:9901)
    exit /b
)
REM 后台启动 Node.js RPA
powershell -Command "Start-Process -FilePath 'node' -ArgumentList 'server.js' -WorkingDirectory '%PROJECT%\rpa-engine' -WindowStyle Minimized" >nul 2>&1
timeout /t 3 >nul
curl -s -o NUL http://%HOST%:9901 2>nul
if %errorlevel%==0 (echo   [OK] RPA 引擎已启动) else (echo   [WARN] RPA 引擎可能未启动，请检查 Node.js)
exit /b
