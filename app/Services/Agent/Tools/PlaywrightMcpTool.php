<?php

namespace App\Services\Agent\Tools;

use App\Services\Agent\AgentToolInterface;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Client\ClientManager;

/**
 * Playwright MCP 浏览器自动化工具 — Phase 4 唯一 MCP。
 *
 * 封装 Playwright MCP Server 的核心能力，实现 AgentToolInterface，
 * 接入 AgentToolRegistry，A 型 DeployAgent 可直接调用。
 *
 * 所有调用强制携带 workspace_id，自动加载对应租户的 Cookie。
 */
class PlaywrightMcpTool implements AgentToolInterface
{
    private ?ClientManager $mcpClient = null;
    private ?string $sessionId = null;

    public function getName(): string
    {
        return 'playwright_mcp';
    }

    public function getDescription(): string
    {
        return '浏览器自动化工具——支持打开网页、获取页面结构、点击元素、输入文本、截图、提取结果。用于新渠道自适应注册/发文、验证码处理等场景。每次调用自动加载该租户的Cookie文件。';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'description' => '操作类型: navigate(打开网页) / snapshot(获取页面结构) / click(点击元素) / type(输入文本) / screenshot(截图) / extract(提取结果)',
                    'enum' => ['navigate', 'snapshot', 'click', 'type', 'screenshot', 'extract'],
                ],
                'url' => ['type' => 'string', 'description' => '目标 URL（navigate 操作必填）'],
                'selector' => ['type' => 'string', 'description' => 'CSS 选择器（click/type 操作必填）'],
                'text' => ['type' => 'string', 'description' => '输入文本（type 操作必填）'],
                'platform_key' => ['type' => 'string', 'description' => '目标平台标识（用于加载Cookie）'],
            ],
            'required' => ['action'],
        ];
    }

    public function execute(array $args, int $workspaceId): array
    {
        $action = (string) ($args['action'] ?? '');
        $platformKey = (string) ($args['platform_key'] ?? 'unknown');
        $cookiePath = storage_path("rpa-engine/storage/states/{$workspaceId}/{$platformKey}.json");

        try {
            $client = $this->getMcpClient();
            $context = [
                'workspace_id' => $workspaceId,
                'action' => $action,
                'platform' => $platformKey,
            ];

            switch ($action) {
                case 'navigate':
                    $url = (string) ($args['url'] ?? '');
                    if ($url === '') {
                        return ['success' => false, 'data' => null, 'error' => 'navigate 操作需要 url 参数'];
                    }
                    // 先加载 Cookie
                    if (file_exists($cookiePath)) {
                        $this->loadCookies($client, $cookiePath);
                    }
                    $result = $this->callMcpTool('browser_navigate', ['url' => $url]);
                    break;

                case 'snapshot':
                    $result = $this->callMcpTool('browser_snapshot', []);
                    break;

                case 'click':
                    $selector = (string) ($args['selector'] ?? '');
                    if ($selector === '') {
                        return ['success' => false, 'data' => null, 'error' => 'click 操作需要 selector 参数'];
                    }
                    $result = $this->callMcpTool('browser_click', ['element' => $selector, 'ref' => $selector]);
                    break;

                case 'type':
                    $selector = (string) ($args['selector'] ?? '');
                    $text = (string) ($args['text'] ?? '');
                    if ($selector === '' || $text === '') {
                        return ['success' => false, 'data' => null, 'error' => 'type 操作需要 selector 和 text 参数'];
                    }
                    $result = $this->callMcpTool('browser_type', ['element' => $selector, 'ref' => $selector, 'text' => $text]);
                    break;

                case 'screenshot':
                    $result = $this->callMcpTool('browser_take_screenshot', []);
                    break;

                case 'extract':
                    $result = $this->callMcpTool('browser_evaluate', [
                        'function' => '() => ({ url: window.location.href, title: document.title })',
                    ]);
                    break;

                default:
                    return ['success' => false, 'data' => null, 'error' => "不支持的操作: {$action}"];
            }

            // 保存 Cookie
            if (in_array($action, ['navigate', 'click', 'type'], true)) {
                $this->saveCookies($client, $cookiePath);
            }

            Log::info('Playwright MCP executed', $context + ['result' => 'success']);

            return [
                'success' => true,
                'data' => $result,
            ];

        } catch (\Throwable $e) {
            Log::error('Playwright MCP failed', $context + ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'data' => null,
                'error' => 'MCP执行失败: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * 获取或初始化 MCP 客户端连接。
     */
    private function getMcpClient(): ClientManager
    {
        if ($this->mcpClient && $this->sessionId) {
            return $this->mcpClient;
        }

        $this->mcpClient = app(ClientManager::class);
        $this->sessionId = $this->mcpClient->connect(
            config('mcp.servers.playwright.url', 'http://localhost:8931/sse')
        );

        return $this->mcpClient;
    }

    /**
     * 加载租户 Cookie 到浏览器。
     */
    private function loadCookies(ClientManager $client, string $cookiePath): void
    {
        if (! file_exists($cookiePath)) {
            return;
        }
        $cookies = json_decode(file_get_contents($cookiePath), true);
        if (is_array($cookies) && isset($cookies['cookies'])) {
            foreach ($cookies['cookies'] as $cookie) {
                $this->callMcpTool('browser_evaluate', [
                    'function' => sprintf(
                        '() => document.cookie = "%s=%s; domain=%s; path=%s"',
                        $cookie['name'] ?? '',
                        $cookie['value'] ?? '',
                        $cookie['domain'] ?? '',
                        $cookie['path'] ?? '/'
                    ),
                ]);
            }
        }
    }

    /**
     * 保存浏览器 Cookie 到文件。
     */
    private function saveCookies(ClientManager $client, string $cookiePath): void
    {
        $dir = dirname($cookiePath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $cookies = $this->callMcpTool('browser_evaluate', [
            'function' => '() => JSON.stringify({ cookies: document.cookie.split("; ").map(c => { const [name,value] = c.split("="); return {name,value,domain:location.hostname,path:"/"}; }) })',
        ]);
        file_put_contents($cookiePath, json_encode($cookies, JSON_PRETTY_PRINT));
    }

    /**
     * 调用 MCP 工具。
     */
    private function callMcpTool(string $toolName, array $args): array
    {
        if (! $this->mcpClient) {
            throw new \RuntimeException('MCP 客户端未连接');
        }

        return $this->mcpClient->callTool($toolName, $args, $this->sessionId);
    }
}
