<?php

namespace App\Services\Agent;

/**
 * 统一工具接口 — Phase 3 核心抽象。
 *
 * 所有 PHP 原生工具和 MCP 工具（Phase 4）都实现此接口，
 * AgentToolRegistry 通过此接口统一管理和调用。
 */
interface AgentToolInterface
{
    /**
     * 工具唯一名称（如 geo_score / knowledge_retrieval / rpa_publish）。
     */
    public function getName(): string;

    /**
     * 工具描述（供 LLM Function Calling 使用）。
     */
    public function getDescription(): string;

    /**
     * 工具参数的 JSON Schema（供 LLM Function Calling 使用）。
     *
     * @return array{type:string, properties:array, required:list<string>}
     */
    public function getSchema(): array;

    /**
     * 执行工具调用。
     *
     * @param  array  $args         LLM 传入的参数
     * @param  int    $workspaceId  租户隔离
     * @return array{success:bool, data:mixed, error?:string}
     */
    public function execute(array $args, int $workspaceId): array;
}
