@echo off
chcp 65001 >nul
echo ========================================
echo   Qonhub AI - 一键环境安装
echo ========================================
echo.

REM === 检查 PHP ===
where php >nul 2>&1
if %errorlevel% neq 0 (
    echo [错误] 未找到 PHP，请先安装 PHP 8.2+
    echo 下载地址: https://windows.php.net/download/
    pause
    exit /b 1
)
echo [OK] PHP 已安装
php -v | findstr "PHP"

REM === 检查 Composer ===
where composer >nul 2>&1
if %errorlevel% neq 0 (
    echo [错误] 未找到 Composer，请先安装 Composer
    echo 下载地址: https://getcomposer.org/
    pause
    exit /b 1
)
echo [OK] Composer 已安装

REM === 检查 PostgreSQL ===
where psql >nul 2>&1
if %errorlevel% neq 0 (
    echo [提示] 未找到 PostgreSQL，请先安装
    echo 下载地址: https://www.postgresql.org/download/windows/
)
echo [OK] PostgreSQL 已安装

REM === 复制环境变量 ===
if not exist .env (
    echo [执行] 创建 .env 文件...
    copy .env.local.example .env
    echo [提示] 请编辑 .env 填写数据库密码和 AI API Key
)

REM === 安装 PHP 依赖 ===
echo [执行] composer install...
composer install --no-interaction --prefer-dist --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix
if %errorlevel% neq 0 (
    echo [错误] composer install 失败
    pause
    exit /b 1
)

REM === 生成 APP_KEY ===
echo [执行] 生成 APP_KEY...
php artisan key:generate --force

REM === 编译前端资源 ===
echo [执行] npm install && npm run build...
call npm install
call npm run build

REM === 数据库迁移 ===
echo [执行] 数据库迁移...
php artisan migrate --force

REM === 初始化数据 ===
echo [执行] 初始化数据...
php artisan geoflow:install

REM === 创建存储链接 ===
echo [执行] 创建存储链接...
php artisan storage:link

echo.
echo ========================================
echo   安装完成！
echo   启动命令: php artisan serve --host=127.0.0.1 --port=18080
echo   后台地址: http://127.0.0.1:18080/geo_admin/login
echo   默认账号: admin / password
echo ========================================
pause
