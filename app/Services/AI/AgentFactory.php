<?php

namespace App\Services\AI;

use App\Ai\Agents\MarkdownContentWriterAgent;

/**
 * AI Agent 工厂 — 统一 Agent 创建入口。
 *
 * Phase 1: createTextAgent() — 纯文本 Agent
 * Phase 3: createToolAgent() — 带工具的 Agent (Function Calling)
 */
class AgentFactory
{
    /**
     * 创建纯文本写作 Agent（复用 MarkdownContentWriterAgent）。
     *
     * Phase 1 改造所有 AI 调用点时使用此方法替代直接 new MarkdownContentWriterAgent()。
     */
    public function createTextAgent(int $maxTokens = 4096): MarkdownContentWriterAgent
    {
        return new MarkdownContentWriterAgent(maxTokens: $maxTokens);
    }

    /**
     * 创建带系统指令的 Agent。
     */
    public function createTextAgentWithInstructions(string $instructions, int $maxTokens = 4096): MarkdownContentWriterAgent
    {
        return new MarkdownContentWriterAgent(
            instructions: $instructions,
            maxTokens: $maxTokens,
        );
    }
}
