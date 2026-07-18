@echo off
chcp 65001 >nul
echo ============================================
echo   重启 RPA 引擎
echo ============================================
echo.
echo [1/2] 停止旧进程...
for /f "tokens=5" %%a in ('netstat -ano ^| findstr ":9901 " ^| findstr "LISTENING"') do (
    echo   停止 PID %%a...
    taskkill /PID %%a /F 2>nul
)
timeout /t 2 >nul
echo.
echo [2/2] 启动新引擎...
cd /d E:\Qonhubgeo\douliu-main\rpa-engine
set RPA_PORT=9901
REM 从 .env 读取 RPA_API_KEY；若未设置则使用下方默认值（生产环境务必修改）
if exist "..\.env" for /f "tokens=2 delims==" %%a in ('findstr /b "RPA_ENGINE_API_KEY=" "..\.env"') do set RPA_API_KEY=%%a
if not defined RPA_API_KEY set RPA_API_KEY=qonhub-rpa-secret-change-me
set RPA_HEADLESS=true
set RPA_BACKEND=local
set RPA_BIND_ADDR=127.0.0.1
start "Qonhub-RPA-Engine" /MIN node server.js
echo.
echo RPA 引擎启动中，等待验证...
timeout /t 4 >nul
curl -s http://127.0.0.1:9901/api/v1/health
echo.
echo ============================================
echo   重启完成！
echo ============================================
pause
