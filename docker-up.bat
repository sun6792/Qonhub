@echo off
chcp 65001 >nul
title Qonhub AI Docker 部署

echo ============================================
echo   Qonhub AI Docker 容器化部署
echo   使用 .env.docker（不影响本地 .env）
echo ============================================
echo.

REM 检查 .env.docker 中的 API Key
findstr /C:"sk-" .env.docker >nul 2>&1
if %errorlevel% neq 0 (
    echo [提示] .env.docker 中未检测到 AI API Key。
    echo 请编辑 .env.docker，填入你的 DeepSeek API Key 等信息。
    echo.
)

echo 启动 Docker 服务...
docker-compose --env-file .env.docker up -d --build

echo.
echo === 服务状态 ===
docker-compose ps

echo.
echo ============================================
echo   Docker 部署完成！
echo   主站: http://localhost:18080
echo   后台: http://localhost:18080/geo_admin/login
echo.
echo   停止: docker-compose down
echo   日志: docker-compose logs -f
echo ============================================
pause
