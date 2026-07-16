# ═══════════════════════════════════════════════════
#  豆流 AI — 一键启动脚本 (Windows PowerShell)
#
#  用法: .\start.ps1
#        .\start.ps1 -SkipRedis    (如果 Redis 已手动启动)
#        .\start.ps1 -CheckOnly    (仅检查环境)
# ═══════════════════════════════════════════════════

param(
    [switch]$SkipRedis,
    [switch]$CheckOnly
)

$ErrorActionPreference = "Continue"
$projectRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $projectRoot

Write-Host ""
Write-Host "════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host "  豆流 AI — 开发环境启动" -ForegroundColor Cyan
Write-Host "════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host ""

# ═══════════════════════════════════════════════════
# Step 0 — 环境检查
# ═══════════════════════════════════════════════════
Write-Host "▶ Step 0: 环境检查" -ForegroundColor Yellow
php artisan dev:check
if ($LASTEXITCODE -ne 0) {
    Write-Host "⚠️  环境检查发现问题，但仍可尝试启动" -ForegroundColor Yellow
}
Write-Host ""

if ($CheckOnly) { exit 0 }

# ═══════════════════════════════════════════════════
# Step 1 — Redis
# ═══════════════════════════════════════════════════
if (-not $SkipRedis) {
    Write-Host "▶ Step 1: 启动 Redis" -ForegroundColor Yellow
    $redisDir = "$projectRoot\redis"
    $redisConf = "$redisDir\redis.qonhub.conf"

    if (Test-Path "$redisDir\redis-server.exe") {
        # 先检查 Redis 是否已在运行
        $redisRunning = & "$redisDir\redis-cli.exe" ping 2>$null
        if ($redisRunning -eq "PONG") {
            Write-Host "  ✅ Redis 已在运行" -ForegroundColor Green
        } else {
            if (-not (Test-Path "$redisDir\data")) {
                New-Item -ItemType Directory -Path "$redisDir\data" -Force | Out-Null
            }
            Start-Process -FilePath "$redisDir\redis-server.exe" -ArgumentList $redisConf -WindowStyle Minimized
            Start-Sleep -Seconds 2
            # 验证
            $ping = & "$redisDir\redis-cli.exe" ping 2>$null
            if ($ping -eq "PONG") {
                Write-Host "  ✅ Redis 已启动 (127.0.0.1:6379)" -ForegroundColor Green
            } else {
                Write-Host "  ⚠️  Redis 启动中，请稍候..." -ForegroundColor Yellow
            }
        }
    } else {
        Write-Host "  ⚠️  未找到本地 Redis，请确保 Redis 已运行" -ForegroundColor Yellow
    }
    Write-Host ""
}

# ═══════════════════════════════════════════════════
# Step 2 — Laravel 开发服务器 + 队列 + Vite (concurrently)
# ═══════════════════════════════════════════════════
Write-Host "▶ Step 2: 启动应用服务" -ForegroundColor Yellow
Write-Host "  php artisan serve --host=127.0.0.1 --port=18080" -ForegroundColor DarkGray
Write-Host "  php artisan queue:work redis --tries=3 --timeout=600" -ForegroundColor DarkGray
Write-Host "  vite (前端热更新)" -ForegroundColor DarkGray
Write-Host ""

# 检查 node_modules
if (-not (Test-Path "$projectRoot\node_modules")) {
    Write-Host "  ⚠️  node_modules 未安装，正在执行 npm install..." -ForegroundColor Yellow
    npm install
}

Write-Host "  管理后台: http://localhost:18080/geo_admin" -ForegroundColor Cyan
Write-Host "  Horizon : http://localhost:18080/horizon" -ForegroundColor Cyan
Write-Host "  前端热更新: http://localhost:5173" -ForegroundColor Cyan
Write-Host ""
Write-Host "  按 Ctrl+C 停止所有服务" -ForegroundColor DarkGray
Write-Host "════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host ""

npm run start
