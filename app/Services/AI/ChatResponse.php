<?php

namespace App\Services\AI;

/**
 * 大模型调用响应值对象。
 */
class ChatResponse
{
    /**
     * @param  string           $text          响应文本
     * @param  int              $tokensUsed    Token 消耗
     * @param  string           $modelId       实际使用的模型ID
     * @param  string           $providerCode  实际使用的供应商
     * @param  list<array>      $attempts      故障切换尝试记录
     * @param  list<array>|null $toolCalls     Function Calling 工具调用
     * @param  int|null         $workspaceId   工作空间ID（用于快照隔离）
     */
    public function __construct(
        public readonly string $text,
        public readonly int    $tokensUsed = 0,
        public readonly string $modelId = '',
        public readonly string $providerCode = '',
        public readonly array  $attempts = [],
        public readonly ?array $toolCalls = null,
        public readonly ?int   $workspaceId = null,
    ) {}

    public function isTextResponse(): bool
    {
        return $this->toolCalls === null || $this->toolCalls === [];
    }

    public function withAttempts(array $attempts): self
    {
        return new self(
            text: $this->text,
            tokensUsed: $this->tokensUsed,
            modelId: $this->modelId,
            providerCode: $this->providerCode,
            attempts: $attempts,
            toolCalls: $this->toolCalls,
            workspaceId: $this->workspaceId,
        );
    }
}
