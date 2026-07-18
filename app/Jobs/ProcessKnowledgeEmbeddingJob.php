<?php

namespace App\Jobs;

use App\Models\KnowledgeBase;
use App\Services\GeoFlow\KnowledgeChunkSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 知识库异步向量化任务。
 *
 * 从 HTTP 请求中剥离 embedding API 调用，放入队列异步执行。
 * 支持批量 embedding (默认300条/批) + 失败自动降级为 hash 向量。
 */
class ProcessKnowledgeEmbeddingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public int $backoff = 60;

    public function __construct(
        private readonly int $knowledgeBaseId,
        private readonly bool $requireRealEmbedding = false
    ) {}

    public function handle(KnowledgeChunkSyncService $syncService): void
    {
        $knowledgeBaseId = $this->knowledgeBaseId;

        // 从 DB 读取内容，避免大文本塞 Redis（队列 payload 限制）
        $knowledgeBase = KnowledgeBase::query()->whereKey($knowledgeBaseId)->first();
        if (! $knowledgeBase) {
            Log::warning('KnowledgeEmbeddingJob: knowledge base not found', ['id' => $knowledgeBaseId]);
            return;
        }
        $content = (string) ($knowledgeBase->content ?? '');
        if ($content === '') {
            Log::warning('KnowledgeEmbeddingJob: empty content', ['id' => $knowledgeBaseId]);
            KnowledgeBase::query()->whereKey($knowledgeBaseId)->update([
                'embedding_status' => 'completed',
                'embedding_progress' => 100,
            ]);
            return;
        }

        // 标记开始处理
        KnowledgeBase::query()->whereKey($knowledgeBaseId)->update([
            'embedding_status' => 'processing',
            'embedding_progress' => 0,
            'embedding_error' => null,
        ]);

        try {
            Log::info('KnowledgeEmbeddingJob started', [
                'knowledge_base_id' => $knowledgeBaseId,
                'content_length' => mb_strlen($content, 'UTF-8'),
            ]);

            $chunkCount = $syncService->sync(
                $knowledgeBaseId,
                $content,
                $this->requireRealEmbedding
            );

            // 标记完成
            KnowledgeBase::query()->whereKey($knowledgeBaseId)->update([
                'embedding_status' => 'completed',
                'embedding_progress' => 100,
                'embedding_error' => null,
            ]);

            Log::info('KnowledgeEmbeddingJob completed', [
                'knowledge_base_id' => $knowledgeBaseId,
                'chunks' => $chunkCount,
            ]);

        } catch (\Throwable $e) {
            Log::error('KnowledgeEmbeddingJob failed', [
                'knowledge_base_id' => $knowledgeBaseId,
                'error' => $e->getMessage(),
            ]);

            KnowledgeBase::query()->whereKey($knowledgeBaseId)->update([
                'embedding_status' => 'failed',
                'embedding_error' => mb_substr($e->getMessage(), 0, 500),
            ]);

            throw $e;
        }
    }
}
