@echo off
chcp 65001 >nul
title Qonhub AI 运维助手

set PHP=C:\php8.4\php.exe
set PGSQL_BIN=C:\pgsql\bin
set PGSQL_DATA=C:\pgsql\data
set REDIS_DIR=E:\Qonhubgeo\GEOFlow-main\redis
set PROJECT_DIR=E:\Qonhubgeo\GEOFlow-main
set HOST=127.0.0.1
set PORT=18080
set TARGET_PORT=18090
set TARGET_DIR=D:\360MoveData\Users\lx\Desktop\测试\geoflow-target-site-test-local

:MENU
cls
echo ============================================
echo   Qonhub AI 运维助手
echo ============================================
echo.
echo   [1] 一键启动全部服务（含 Redis + 守护进程）
echo   [2] 一键停止全部服务
echo   [3] 仅启动主站（含 Redis）
echo   [4] 仅启动客户站
echo   [5] 重启 Worker（代码改了选这个）
echo   [6] 查看运行状态
echo   [7] 打开主站后台
echo   [8] 打开客户站前台
echo   [9] 打开内容弹药库
echo   [10] Redis 启动
echo   [11] Redis 停止
echo   [0] 退出
echo.
set /p choice=请输入选项:

if "%choice%"=="1" goto START_ALL
if "%choice%"=="2" goto STOP_ALL
if "%choice%"=="3" goto START_MAIN
if "%choice%"=="4" goto START_TARGET
if "%choice%"=="5" goto RESTART_WORKER
if "%choice%"=="6" goto STATUS
if "%choice%"=="7" goto OPEN_ADMIN
if "%choice%"=="8" goto OPEN_TARGET
if "%choice%"=="9" goto OPEN_ARMORY
if "%choice%"=="10" goto START_REDIS
if "%choice%"=="11" goto STOP_REDIS
if "%choice%"=="0" exit /b
goto MENU

:START_ALL
echo.
echo [1/5] 启动 PostgreSQL...
call :START_PGSQL
echo [2/5] 启动 Redis...
call :START_REDIS_SVC
echo [3/5] 启动主站...
call :START_MAIN_SITE
echo [4/5] 启动客户站...
call :START_TARGET_SITE
echo [5/5] 启动 Worker 守护进程（崩溃自动重启）...
call :START_WATCHDOG
echo.
echo ============================================
echo   全部启动完成！
echo   主站: http://%HOST%:%PORT%
echo   后台: http://%HOST%:%PORT%/geo_admin/login
echo   客户站: http://localhost:%TARGET_PORT%
echo   Redis: %HOST%:6379
echo ============================================
pause
goto MENU

:STOP_ALL
echo.
echo 正在停止 Redis...
call :STOP_REDIS_SVC
echo 正在停止所有 PHP 进程...
taskkill /F /IM php.exe >nul 2>&1
echo [OK] 已停止主站 + Worker + 客户站
echo.
echo 停止 PostgreSQL? (y/n)
set /p pg=
if /i "%pg%"=="y" (
    "%PGSQL_BIN%\pg_ctl.exe" -D "%PGSQL_DATA%" stop 2>&1
    echo [OK] PostgreSQL 已停止
)
pause
goto MENU

:START_MAIN
call :START_PGSQL
call :START_REDIS_SVC
call :START_MAIN_SITE
call :START_WATCHDOG
pause
goto MENU

:START_TARGET
call :START_TARGET_SITE
pause
goto MENU

:RESTART_WORKER
echo 停止旧 Worker...
taskkill /F /FI "WINDOWTITLE eq Qonhub Worker*" >nul 2>&1
timeout /t 1 >nul
echo 启动守护进程...
call :START_WATCHDOG
pause
goto MENU

:OPEN_ARMORY
start http://%HOST%:%PORT%/geo_admin/distribution/armory
goto MENU

:STATUS
echo.
echo === PostgreSQL ===
"%PGSQL_BIN%\pg_isready.exe" -h %HOST% -p 5432 2>&1
echo.
echo === Redis ===
"%REDIS_DIR%\redis-cli.exe" ping 2>nul
echo.
echo === 主站 (端口 %PORT%) ===
curl -s -o NUL -w "HTTP %%{http_code}" http://%HOST%:%PORT% 2>nul
echo.
echo === 客户站 (端口 %TARGET_PORT%) ===
curl -s -o NUL -w "HTTP %%{http_code}" http://localhost:%TARGET_PORT% 2>nul
echo.
echo === 队列 Worker ===
tasklist /FI "IMAGENAME eq php.exe" /FO TABLE 2>nul | findstr php
echo.
echo === DB 队列待处理 ===
"%PHP%" -r "$c=pg_connect('host=%HOST% port=5432 dbname=geo_flow user=geo_user password=geo_password');$r=pg_query($c,'SELECT COUNT(*) FROM jobs');echo '待处理: '.pg_fetch_result($r,0).PHP_EOL;" 2>nul
echo.
pause
goto MENU

:START_REDIS
call :START_REDIS_SVC
pause
goto MENU

:STOP_REDIS
call :STOP_REDIS_SVC
pause
goto MENU

:OPEN_ADMIN
start http://%HOST%:%PORT%/geo_admin/login
goto MENU

:OPEN_TARGET
start http://localhost:%TARGET_PORT%
goto MENU

REM ==================== 子函数 ====================

:START_PGSQL
"%PGSQL_BIN%\pg_isready.exe" -h %HOST% -p 5432 >nul 2>&1
if %errorlevel%==0 (
    echo [OK] PostgreSQL 已在运行
    exit /b
)
"%PGSQL_BIN%\pg_ctl.exe" -D "%PGSQL_DATA%" start 2>&1
exit /b

:START_MAIN_SITE
curl -s -o NUL http://%HOST%:%PORT% 2>nul
if %errorlevel%==0 (
    echo [OK] 主站已在运行
    exit /b
)
start "qonhub-site" /MIN "%PHP%" -d max_execution_time=0 artisan serve --host=%HOST% --port=%PORT%
timeout /t 3 >nul
echo [OK] 主站已启动
exit /b

:START_TARGET_SITE
curl -s -o NUL http://localhost:%TARGET_PORT% 2>nul
if %errorlevel%==0 (
    echo [OK] 客户站已在运行
    exit /b
)
start "qonhub-target" /MIN "%PHP%" -S localhost:%TARGET_PORT% -t "%TARGET_DIR%"
timeout /t 2 >nul
echo [OK] 客户站已启动
exit /b

:START_WATCHDOG
start "Qonhub Worker 守护进程" /MIN cmd /c "%PROJECT_DIR%\watchdog.bat"
timeout /t 3 >nul
echo [OK] 守护进程已启动（Worker 崩溃自动重启）
exit /b

:START_REDIS_SVC
REM 检查是否已在运行
"%REDIS_DIR%\redis-cli.exe" ping >nul 2>&1
if %errorlevel%==0 (
    echo [OK] Redis 已在运行 (127.0.0.1:6379)
    exit /b
)
REM 确保数据目录存在
if not exist "%REDIS_DIR%\data" mkdir "%REDIS_DIR%\data"
REM 启动 Redis
start "Qonhub-Redis" /MIN "%REDIS_DIR%\redis-server.exe" "%REDIS_DIR%\redis.qonhub.conf"
timeout /t 2 >nul
"%REDIS_DIR%\redis-cli.exe" ping >nul 2>&1
if %errorlevel%==0 (
    echo [OK] Redis 启动成功 (127.0.0.1:6379)
) else (
    echo [ERROR] Redis 启动失败，请查看日志: %REDIS_DIR%\data\redis.log
)
exit /b

:STOP_REDIS_SVC
"%REDIS_DIR%\redis-cli.exe" shutdown 2>nul
if %errorlevel%==0 (
    echo [OK] Redis 已正常停止
) else (
    echo Redis 未响应，清理残留进程...
    taskkill /F /IM redis-server.exe >nul 2>&1
    echo [OK] 已清理
)
exit /b
