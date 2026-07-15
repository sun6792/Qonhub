FROM mcr.microsoft.com/playwright:v1.48.0-jammy

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --production

COPY . .

# Playwright Chromium 已内置在基础镜像中 (Edge兼容)
# 无需额外 npx playwright install
ENV NODE_ENV=production
ENV RPA_PORT=9901
ENV RPA_API_KEY=qonhub-rpa-secret-change-me
ENV RPA_HEADLESS=true
ENV RPA_BROWSER=chromium  # 设为 chromium 使用Edge兼容的Chromium内核

EXPOSE 9901

CMD ["node", "server.js"]
