@echo off
chcp 65001 >nul
title Qonhub AI Docker 部署

echo ============================================
echo   Qonhub AI Docker 容器化部署
echo   使用 .env.docker（不影响本地 .env）
echo ============================================
echo.

REM 首次使用：从模板创建 .env.docker
if not exist .env.docker (
    echo [首次运行] 从 .env.docker.example 创建 .env.docker...
    copy .env.docker.example .env.docker >nul
    echo [重要] 请编辑 .env.docker，填入：
    echo   1. APP_URL（你的域名或IP）
    echo   2. GEOFLOW_ADMIN_PASSWORD（管理员密码）
    echo   3. AI API Key（从本地 .env 复制）
    echo.
    pause
    exit /b
)

REM 检查 .env.docker 中的关键配置
findstr /C:"APP_URL=https://your-domain.com" .env.docker >nul 2>&1
if %errorlevel%==0 (
    echo [错误] 请先编辑 .env.docker，把 APP_URL 改成你的真实域名！
    pause
    exit /b
)
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
