<?php

namespace App\Services\AI;

/**
 * 大模型调用请求值对象。
 */
class ChatRequest
{
    /**
     * @param  string            $providerCode       供应商代码 (deepseek/qwen/ernie/...)
     * @param  string            $modelId            模型ID (deepseek-v4-flash/qwen-max/...)
     * @param  list<array{role:string,content:string}>  $messages  消息列表
     * @param  array             $options            额外选项 (max_tokens/temperature/...)
     * @param  int               $workspaceId         租户ID (额度扣减)
     * @param  array             $tools               Function Calling 工具定义
     * @param  bool              $streamEnabled       是否流式输出
     * @param  int|null          $agentExecutionId    关联的智能体执行记录ID (用于对话快照)
     */
    public function __construct(
        public readonly string $providerCode,
        public readonly string $modelId,
        public readonly array  $messages,
        public readonly array  $options = [],
        public readonly int    $workspaceId = 0,
        public readonly array  $tools = [],
        public readonly bool   $streamEnabled = false,
        public readonly ?int   $agentExecutionId = null,
    ) {}
}
