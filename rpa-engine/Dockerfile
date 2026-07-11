FROM mcr.microsoft.com/playwright:v1.48.0-jammy

WORKDIR /app

COPY package.json ./
RUN npm install

COPY . .

# Playwright browsers are pre-installed in the base image
ENV NODE_ENV=production
ENV RPA_PORT=9901
ENV RPA_API_KEY=qonhub-rpa-secret-change-me
ENV RPA_HEADLESS=true

EXPOSE 9901

CMD ["node", "server.js"]
