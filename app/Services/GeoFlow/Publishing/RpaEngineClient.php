<?php

namespace App\Services\GeoFlow\Publishing;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * RPA 引擎 HTTP 客户端，实现 RpaEngineInterface。
 *
 * 通过 HTTP API 与独立部署的 Node.js + Playwright 微服务通信，
 * 主系统不直接依赖 Node.js，RPA 服务宕机不影响其他渠道正常使用。
 *
 * 配置项（config/geoflow.php + .env）：
 *   RPA_ENGINE_URL=http://127.0.0.1:9901
 *   RPA_ENGINE_API_KEY=qonhub-rpa-secret-change-me
 *   RPA_ENGINE_TIMEOUT=300
 */
class RpaEngineClient implements RpaEngineInterface
{
    private string $baseUrl;
    private string $apiKey;
    private int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('geoflow.rpa_engine_url', 'http://127.0.0.1:9901'), '/');
        $this->apiKey = (string) config('geoflow.rpa_engine_api_key', 'qonhub-rpa-secret-change-me');
        $this->timeout = (int) config('geoflow.rpa_engine_timeout', 300);
    }

    // ── RpaEngineInterface 实现 ──────────────────────────

    /**
     * {@inheritdoc}
     */
    public function executeTask(array $task): array
    {
        $action = $task['action'] ?? 'publish';

        return match ($action) {
            'register_and_certify' => $this->callRegisterApi($task),
            'publish_article' => $this->callPublishApi($task),
            default => throw new RuntimeException("Unknown RPA action: {$action}"),
        };
    }

    /**
     * {@inheritdoc}
     */
    public function createTaskAsync(array $task): string
    {
        $action = $task['action'] ?? 'register_and_certify';
        $endpoint = $action === 'publish_article' ? '/api/v1/publish' : '/api/v1/register';

        $resp = Http::timeout($this->timeout)
            ->withHeader('X-API-Key', $this->apiKey)
            ->post("{$this->baseUrl}{$endpoint}", $task);

        if (! $resp->successful()) {
            throw new RuntimeException("RPA engine error: HTTP {$resp->status()} — {$resp->body()}");
        }

        $data = $resp->json();

        return $data['task_id'] ?? throw new RuntimeException('RPA engine returned no task_id');
    }

    /**
     * {@inheritdoc}
     */
    public function getTaskStatus(string $taskId): array
    {
        $resp = Http::timeout(10)
            ->withHeader('X-API-Key', $this->apiKey)
            ->get("{$this->baseUrl}/api/v1/tasks/{$taskId}");

        if (! $resp->successful()) {
            return ['status' => 'error', 'result' => ['error' => "HTTP {$resp->status()}"]];
        }

        return $resp->json();
    }

    /**
     * {@inheritdoc}
     */
    public function createFingerprint(array $config): string
    {
        // 浏览器指纹由 RPA 引擎在任务执行时随机化，此处返回配置标识
        return 'fp_' . substr(md5(json_encode($config)), 0, 12);
    }

    /**
     * {@inheritdoc}
     */
    public function getAvailableProxies(?string $region = null): array
    {
        // 代理 IP 池由外部提供，当前返回空列表
        // 后续可对接快代理/芝麻代理等 API
        return [];
    }

    // ── 健康检测 ──────────────────────────────────────────

    /**
     * 检查 RPA 引擎健康状态。
     *
     * @return array{healthy: bool, message: string, details: array}
     */
    public function healthCheck(): array
    {
        try {
            $resp = Http::timeout(5)
                ->withHeader('X-API-Key', $this->apiKey)
                ->get("{$this->baseUrl}/api/v1/health");

            if ($resp->successful()) {
                $data = $resp->json();

                return [
                    'healthy' => true,
                    'message' => 'RPA engine connected',
                    'details' => $data,
                ];
            }

            return [
                'healthy' => false,
                'message' => "RPA engine returned HTTP {$resp->status()}",
                'details' => [],
            ];
        } catch (\Throwable $e) {
            return [
                'healthy' => false,
                'message' => "RPA engine unreachable: {$e->getMessage()}",
                'details' => [],
            ];
        }
    }

    // ── 内部 API 调用方法 ─────────────────────────────────

    private function callRegisterApi(array $task): array
    {
        $resp = Http::timeout($this->timeout)
            ->withHeader('X-API-Key', $this->apiKey)
            ->post("{$this->baseUrl}/api/v1/register", [
                'platform' => $task['platform'] ?? '',
                'account' => $task['account'] ?? [],
                'enterprise' => $task['enterprise'] ?? [],
                'options' => $task['options'] ?? [],
            ]);

        if (! $resp->successful()) {
            return $this->formatError('RPA_REGISTER_FAILED', "RPA engine error: {$resp->body()}");
        }

        $data = $resp->json();
        $taskId = $data['task_id'] ?? null;

        if (! $taskId) {
            return $this->formatError('RPA_NO_TASK_ID', 'RPA engine returned no task_id');
        }

        // 轮询等待任务完成
        return $this->pollTaskResult($taskId);
    }

    private function callPublishApi(array $task): array
    {
        $resp = Http::timeout($this->timeout)
            ->withHeader('X-API-Key', $this->apiKey)
            ->post("{$this->baseUrl}/api/v1/publish", [
                'platform' => $task['platform'] ?? '',
                'account' => $task['account'] ?? [],
                'content' => $task['content'] ?? [],
                'options' => $task['options'] ?? [],
            ]);

        if (! $resp->successful()) {
            return $this->formatError('RPA_PUBLISH_FAILED', "RPA engine error: {$resp->body()}");
        }

        $data = $resp->json();
        $taskId = $data['task_id'] ?? null;

        return $taskId ? $this->pollTaskResult($taskId) : $this->formatError('RPA_NO_TASK_ID', 'RPA engine returned no task_id');
    }

    /**
     * 轮询 RPA 任务结果，直到完成或超时。
     */
    private function pollTaskResult(string $taskId): array
    {
        $maxPolls = 60;       // 最多轮询60次
        $pollInterval = 3;    // 每3秒一次 = 最多等3分钟

        for ($i = 0; $i < $maxPolls; $i++) {
            sleep($pollInterval);

            $status = $this->getTaskStatus($taskId);

            if ($status['status'] === 'completed') {
                return array_merge(
                    ['success' => true],
                    $status['result'] ?? []
                );
            }

            if ($status['status'] === 'failed') {
                return $this->formatError(
                    'RPA_TASK_FAILED',
                    $status['error'] ?? $status['result']['error'] ?? 'Unknown RPA task failure'
                );
            }
        }

        return $this->formatError('RPA_TIMEOUT', "RPA task {$taskId} timed out after {$maxPolls} polls");
    }

    private function formatError(string $code, string $message): array
    {
        return [
            'success' => false,
            'shop_url' => '',
            'account_id' => '',
            'article_url' => '',
            'article_id' => '',
            'error' => "[{$code}] {$message}",
            'raw_response' => compact('code', 'message'),
            'engine' => 'rpa',
        ];
    }
}
