/**
 * Qonhub AI RPA Engine — Playwright-based headless browser microservice.
 *
 * Endpoints (aligned with RpaEngineInterface):
 *   POST /api/v1/register  — B2B enterprise registration + certification
 *   POST /api/v1/publish    — Content publish (reserved for future)
 *   GET  /api/v1/health     — Health check
 *   GET  /api/v1/tasks/:id  — Task status query
 *
 * Env vars:
 *   RPA_PORT=9901         Server port
 *   RPA_API_KEY=...       API auth key
 *   RPA_HEADLESS=true     Run in headless mode
 *   RPA_SCREENSHOT_DIR=./screenshots
 */

import express from "express";
import { chromium } from "playwright";
import winston from "winston";
import { randomUUID } from "crypto";
import fs from "fs";
import path from "path";
import { fileURLToPath } from "url";

// ── Config ──────────────────────────────────────────────
const __dirname = path.dirname(fileURLToPath(import.meta.url));
const PORT = process.env.RPA_PORT || 9901;
const API_KEY = process.env.RPA_API_KEY || "qonhub-rpa-secret-change-me";
const HEADLESS = process.env.RPA_HEADLESS !== "false";
const SCREENSHOT_DIR = process.env.RPA_SCREENSHOT_DIR || path.join(__dirname, "screenshots");

// Ensure screenshot dir exists
if (!fs.existsSync(SCREENSHOT_DIR)) fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });

// ── Logger ──────────────────────────────────────────────
const logger = winston.createLogger({
    level: "info",
    format: winston.format.combine(
        winston.format.timestamp(),
        winston.format.printf(({ timestamp, level, message }) => `[${timestamp}] ${level.toUpperCase()}: ${message}`)
    ),
    transports: [
        new winston.transports.Console(),
        new winston.transports.File({ filename: path.join(__dirname, "logs", "rpa.log") }),
    ],
});

// ── Task Store (in-memory, production use Redis) ────────
const tasks = new Map();

// ── Automation Loader ──────────────────────────────────
const automations = {};

async function loadAutomations() {
    const dir = path.join(__dirname, "automations");
    if (!fs.existsSync(dir)) return;
    const files = fs.readdirSync(dir).filter(f => f.endsWith(".js"));
    for (const file of files) {
        const mod = await import(`./automations/${file}`);
        if (mod.platform && mod.execute) {
            automations[mod.platform] = mod;
            logger.info(`Loaded automation: ${mod.platform} — ${mod.description || ""}`);
        }
    }
}

// ── Express App ────────────────────────────────────────
const app = express();
app.use(express.json({ limit: "10mb" }));

// Auth middleware
function auth(req, res, next) {
    const key = req.headers["x-api-key"] || req.query.api_key || "";
    if (key !== API_KEY) return res.status(401).json({ error: "unauthorized" });
    next();
}

// ── Health Check ───────────────────────────────────────
app.get("/api/v1/health", (req, res) => {
    res.json({
        status: "healthy",
        uptime: process.uptime(),
        automations: Object.keys(automations),
        active_tasks: tasks.size,
        node_version: process.version,
    });
});

// ── Register (B2B Enterprise Certification) ─────────────
app.post("/api/v1/register", auth, async (req, res) => {
    const taskId = randomUUID();
    const { platform, account, enterprise, options } = req.body;

    if (!platform || !account || !enterprise) {
        return res.status(400).json({ error: "missing required fields: platform, account, enterprise" });
    }

    tasks.set(taskId, { status: "running", started_at: new Date().toISOString() });
    logger.info(`Task ${taskId}: register on ${platform} for ${enterprise.company_name || "unknown"}`);

    // Run async, respond immediately
    res.json({ task_id: taskId, status: "accepted" });

    try {
        const automation = automations[platform];
        if (!automation) {
            throw new Error(`No automation registered for platform: ${platform}`);
        }

        const result = await automation.execute({
            taskId,
            account,
            enterprise,
            options: {
                headless: HEADLESS,
                screenshotDir: SCREENSHOT_DIR,
                proxy: options?.bound_ip || null,
                timeout: options?.timeout_seconds || 180,
                ...options,
            },
            logger,
        });

        tasks.set(taskId, { status: "completed", result, finished_at: new Date().toISOString() });
        logger.info(`Task ${taskId}: completed — shop_url=${result.shop_url || "none"}`);
    } catch (err) {
        const errorMsg = err.message || String(err);
        tasks.set(taskId, { status: "failed", error: errorMsg, finished_at: new Date().toISOString() });
        logger.error(`Task ${taskId}: failed — ${errorMsg}`);
    }
});

// ── Publish (Content Publishing — reserved) ─────────────
app.post("/api/v1/publish", auth, async (req, res) => {
    const taskId = randomUUID();
    const { platform, account, content, options } = req.body;

    if (!platform || !content) {
        return res.status(400).json({ error: "missing required fields: platform, content" });
    }

    tasks.set(taskId, { status: "running", started_at: new Date().toISOString() });
    logger.info(`Task ${taskId}: publish on ${platform}`);

    res.json({ task_id: taskId, status: "accepted" });

    try {
        const automation = automations[platform];
        if (automation?.publish) {
            const result = await automation.publish({ taskId, account, content, options: { headless: HEADLESS, ...options }, logger });
            tasks.set(taskId, { status: "completed", result, finished_at: new Date().toISOString() });
        } else {
            throw new Error(`Publish not implemented for platform: ${platform}`);
        }
    } catch (err) {
        tasks.set(taskId, { status: "failed", error: err.message || String(err), finished_at: new Date().toISOString() });
    }
});

// ── Task Status ────────────────────────────────────────
app.get("/api/v1/tasks/:id", auth, (req, res) => {
    const task = tasks.get(req.params.id);
    if (!task) return res.status(404).json({ error: "task not found" });
    res.json(task);
});

// ── Screenshots (serve static) ──────────────────────────
app.use("/screenshots", auth, express.static(SCREENSHOT_DIR));

// ── Start ──────────────────────────────────────────────
await loadAutomations();
app.listen(PORT, "0.0.0.0", () => {
    logger.info(`Qonhub RPA Engine started on port ${PORT} (headless=${HEADLESS})`);
    logger.info(`Loaded automations: ${Object.keys(automations).join(", ") || "none"}`);
});

// Graceful shutdown
process.on("SIGTERM", () => { logger.info("SIGTERM — shutting down"); process.exit(0); });
process.on("SIGINT", () => { logger.info("SIGINT — shutting down"); process.exit(0); });
