<?php

namespace App\Services\Agent;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 统一工具注册中心 — Phase 3 核心。
 *
 * 职责：
 *   - 工具注册与发现
 *   - 按名称查找工具
 *   - 权限校验（workspace 隔离）
 *   - 调用次数限流（单任务 ≤5 次）
 *   - 审计日志（全量记录）
 *   - 白名单管控（按 agent 类型限制可用工具集）
 *
 * @phpstan-type ToolCallRecord array{tool:string, agent:string, args:array, workspace_id:int, duration_ms:float, success:bool, error?:string}
 */
class AgentToolRegistry
{
    /** @var array<string, AgentToolInterface> */
    private array $tools = [];

    /** @var array<string, list<string>> 每个 Agent 可调用的工具白名单 */
    private array $agentWhitelist = [];

    /** @var int 单任务内工具调用上限 */
    private const MAX_CALLS_PER_SESSION = 5;

    /**
     * 注册工具。
     */
    public function register(AgentToolInterface $tool): void
    {
        $this->tools[$tool->getName()] = $tool;
    }

    /**
     * 批量注册工具。
     *
     * @param  list<AgentToolInterface>  $tools
     */
    public function registerMany(array $tools): void
    {
        foreach ($tools as $tool) {
            $this->register($tool);
        }
    }

    /**
     * 设置 Agent 白名单。
     */
    public function setWhitelist(string $agentType, array $toolNames): void
    {
        $this->agentWhitelist[$agentType] = $toolNames;
    }

    /**
     * 列出所有已注册的工具（OpenAI Function Calling 格式）。
     *
     * @return list<array{type:string, function:array}>
     */
    public function toOpenAiFunctions(?string $agentType = null): array
    {
        $functions = [];
        foreach ($this->tools as $tool) {
            // 白名单过滤
            if ($agentType && ! $this->isToolAllowed($agentType, $tool->getName())) {
                continue;
            }
            $functions[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool->getName(),
                    'description' => $tool->getDescription(),
                    'parameters' => $tool->getSchema(),
                ],
            ];
        }

        return $functions;
    }

    /**
     * 按名称查找工具。
     */
    public function find(string $name): ?AgentToolInterface
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * 执行工具调用（带安全护栏）。
     *
     * @param  string  $toolName     工具名
     * @param  array   $args         参数
     * @param  int     $workspaceId  租户 ID
     * @param  string  $agentType    调用方 Agent 类型
     * @param  string  $sessionId    任务会话 ID（用于限流计数）
     * @return array{success:bool, data:mixed, error?:string}
     */
    public function execute(string $toolName, array $args, int $workspaceId, string $agentType, string $sessionId): array
    {
        $startTime = microtime(true);

        // ① 工具存在性检查
        $tool = $this->find($toolName);
        if (! $tool) {
            return $this->failResult($toolName, $agentType, $workspaceId, $startTime, "工具不存在: {$toolName}");
        }

        // ② 白名单检查
        if (! $this->isToolAllowed($agentType, $toolName)) {
            return $this->failResult($toolName, $agentType, $workspaceId, $startTime, "Agent '{$agentType}' 无权调用工具 '{$toolName}'");
        }

        // ③ 限流检查
        if (! $this->checkRateLimit($sessionId)) {
            return $this->failResult($toolName, $agentType, $workspaceId, $startTime, '工具调用次数超限(最大' . self::MAX_CALLS_PER_SESSION . '次)，回退到B型默认逻辑');
        }

        // ④ 执行
        try {
            $result = $tool->execute($args, $workspaceId);
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            $this->incrementCallCount($sessionId);
            $this->auditLog($toolName, $agentType, $args, $workspaceId, $duration, true);

            return $result;

        } catch (\Throwable $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            $this->auditLog($toolName, $agentType, $args, $workspaceId, $duration, false, $e->getMessage());

            return $this->failResult($toolName, $agentType, $workspaceId, $startTime, $e->getMessage());
        }
    }

    /**
     * 检查指定 Agent 是否可调用指定工具。
     */
    public function isToolAllowed(string $agentType, string $toolName): bool
    {
        $whitelist = $this->agentWhitelist[$agentType] ?? [];
        // 空白名单 = 全开放
        if ($whitelist === []) {
            return true;
        }

        return in_array($toolName, $whitelist, true);
    }

    /**
     * 检查会话内调用次数是否超限。
     */
    private function checkRateLimit(string $sessionId): bool
    {
        $count = (int) Cache::get("tool:session:{$sessionId}", 0);

        return $count < self::MAX_CALLS_PER_SESSION;
    }

    /**
     * 增加调用计数。
     */
    private function incrementCallCount(string $sessionId): void
    {
        $key = "tool:session:{$sessionId}";
        Cache::increment($key, 1);
        Cache::expire($key, 3600); // 1 小时过期
    }

    /**
     * 审计日志。
     */
    private function auditLog(string $toolName, string $agentType, array $args, int $workspaceId, float $durationMs, bool $success, ?string $error = null): void
    {
        $context = [
            'tool' => $toolName,
            'agent' => $agentType,
            'workspace_id' => $workspaceId,
            'duration_ms' => $durationMs,
            'success' => $success,
            'args_summary' => json_encode($args, JSON_UNESCAPED_UNICODE),
        ];

        if ($error) {
            $context['error'] = $error;
        }

        Log::info('AgentTool called', $context);
    }

    /**
     * 生成失败结果。
     */
    private function failResult(string $toolName, string $agentType, int $workspaceId, float $startTime, string $error): array
    {
        $duration = round((microtime(true) - $startTime) * 1000, 2);
        $this->auditLog($toolName, $agentType, [], $workspaceId, $duration, false, $error);

        return [
            'success' => false,
            'data' => null,
            'error' => $error,
        ];
    }

    /**
     * 获取已注册工具数量。
     */
    public function count(): int
    {
        return count($this->tools);
    }
}
