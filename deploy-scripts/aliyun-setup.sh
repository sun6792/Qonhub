#!/bin/bash
# ============================================
#  豆流AI v2.6.0 阿里云一键部署脚本
#  执行: bash aliyun-setup.sh
# ============================================
set -e

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'
PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"

echo "============================================"
echo "  豆流 AI v2.6.0 阿里云部署"
echo "  $(date '+%Y-%m-%d %H:%M:%S')"
echo "============================================"

# ── 1. 环境检测 ──
echo -e "\n${YELLOW}[1/6] 环境检测${NC}"
command -v php >/dev/null 2>&1 || { echo -e "${RED}请先安装 PHP 8.2+${NC}"; exit 1; }
command -v node >/dev/null 2>&1 || { echo -e "${RED}请先安装 Node.js 18+${NC}"; exit 1; }
command -v npm >/dev/null 2>&1 || { echo -e "${RED}请先安装 npm${NC}"; exit 1; }
php -v | head -1
node -v
echo -e "${GREEN}环境检测通过${NC}"

# ── 2. PHP 依赖 ──
echo -e "\n${YELLOW}[2/6] PHP 依赖${NC}"
cd "$PROJECT_DIR"
if [ -d "vendor" ] && [ -f "vendor/autoload.php" ]; then
    echo -e "${GREEN}PHP 依赖已存在，跳过${NC}"
else
    echo "安装 composer 依赖..."
    composer install --no-dev --optimize-autoloader
    echo -e "${GREEN}PHP 依赖安装完成${NC}"
fi

# ── 3. Node.js 依赖 ──
echo -e "\n${YELLOW}[3/6] Node.js 依赖${NC}"
cd "$PROJECT_DIR/rpa-engine"
if [ -d "node_modules" ] && [ -f "node_modules/.package-lock.json" ]; then
    echo -e "${GREEN}Node.js 依赖已存在，跳过${NC}"
else
    echo "安装 npm 依赖..."
    npm install --production
    echo -e "${GREEN}Node.js 依赖安装完成${NC}"
fi

# ── 4. Playwright 浏览器 ──
echo -e "\n${YELLOW}[4/6] Playwright 浏览器 (Edge Chromium)${NC}"
if npx playwright install --dry-run chromium 2>/dev/null | grep -q "already"; then
    echo -e "${GREEN}Playwright 浏览器已安装，跳过${NC}"
else
    echo "下载 Playwright Chromium (Edge 兼容, ~300MB)..."
    npx playwright install chromium
    echo -e "${GREEN}Playwright 浏览器安装完成${NC}"
fi

# ── 5. 配置文件 ──
echo -e "\n${YELLOW}[5/6] 配置文件${NC}"
cd "$PROJECT_DIR"
if [ ! -f ".env" ]; then
    cp .env.example .env
    php artisan key:generate
    echo -e "${YELLOW}请编辑 .env 填入数据库/Redis/API Key 配置${NC}"
else
    echo -e "${GREEN}.env 已存在${NC}"
fi

# ── 6. 数据库迁移 ──
echo -e "\n${YELLOW}[6/6] 数据库迁移${NC}"
php artisan migrate --force
php artisan optimize:clear
echo -e "${GREEN}迁移完成${NC}"

# ── 完成 ──
echo ""
echo "============================================"
echo -e "${GREEN}  部署完成！${NC}"
echo ""
echo "  启动命令:"
echo "    主站:     php artisan serve --host=0.0.0.0 --port=18080"
echo "    队列:     php artisan queue:work redis --queue=geoflow,distribution,agent_scout"
echo "    RPA引擎:  cd rpa-engine && node server.js"
echo "    MCP服务:  cd rpa-engine && npx @playwright/mcp --port 8932"
echo ""
echo "  访问地址:"
echo "    运营端:   http://你的IP:18080/geo_admin"
echo "    客户端:   http://你的IP:18080/client"
echo "============================================"
