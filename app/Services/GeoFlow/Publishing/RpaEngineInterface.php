<?php

namespace App\Services\GeoFlow\Publishing;

/**
 * RPA 浏览器自动化引擎接口。
 *
 * RPA 引擎作为独立服务部署（Node.js Puppeteer/Playwright 或 Python Selenium），
 * 通过 HTTP API 与 Laravel 主系统通信。
 *
 * 引擎需实现的端点：
 *
 *   GET  /health                     — 健康检测
 *   POST /tasks                       — 创建 RPA 任务
 *   GET  /tasks/{taskId}              — 查询任务状态
 *   POST /fingerprints                — 创建浏览器指纹
 *   GET  /fingerprints/{fingerprintId} — 获取指纹配置
 *   POST /sessions/{sessionId}/solve-captcha — 人工打码
 *
 * 任务类型（action）：
 *   - publish_article    发布文章
 *   - login_and_save     登录并保存 Cookie
 *   - verify_account     验证账号有效性
 *   - register_account   注册新账号
 *
 * 安全约束：
 *   - 引擎只监听 127.0.0.1，不对外暴露
 *   - 密钥/密码不写入日志
 *   - 会话 Cookie 使用后即焚
 */
interface RpaEngineInterface
{
    /**
     * 创建并执行 RPA 任务（同步模式，等待完成）。
     *
     * @param  array{platform:string, account:array, action:string, content:array, options:array}  $task
     * @return array{status:string, article_id:string, article_url:string, error:string, screenshot_path:string}
     */
    public function executeTask(array $task): array;

    /**
     * 创建 RPA 任务（异步模式，立即返回任务ID）。
     *
     * @param  array  $task
     * @return string taskId
     */
    public function createTaskAsync(array $task): string;

    /**
     * 查询异步任务状态。
     *
     * @return array{status:string, result:array}
     */
    public function getTaskStatus(string $taskId): array;

    /**
     * 创建隔离的浏览器指纹环境。
     *
     * @param  array{fingerprint:string, proxy_ip:string, user_agent:string, viewport:array}  $config
     * @return string fingerprintId
     */
    public function createFingerprint(array $config): string;

    /**
     * 获取可用的代理 IP 列表。
     *
     * @param  string|null  $region  地区过滤
     * @return array<int, array{ip:string, port:int, region:string, isp:string}>
     */
    public function getAvailableProxies(?string $region = null): array;
}
