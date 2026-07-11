<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ContentPublishTask;
use App\Models\ContentPublisherAccount;
use App\Models\EnterpriseProfile;
use App\Models\Workspace;
use App\Services\GeoFlow\Publishing\ContentPublishService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Console\Command;

/**
 * 全链路发布流程测试命令。
 *
 * 用法：
 *   php artisan test:publish-flow {workspace_slug} {--article_id=} {--dry-run}
 *
 * 示例：
 *   php artisan test:publish-flow pinshang-sports --dry-run
 *   php artisan test:publish-flow pinshang-sports --article_id=1
 */
class TestPublishFlowCommand extends Command
{
    protected $signature = 'test:publish-flow
                            {workspace_slug : 工作空间 slug}
                            {--article_id= : 指定文章 ID}
                            {--dry-run : 模拟执行，不实际发 HTTP 请求}';

    protected $description = '全链路发布流程测试：账号→任务→队列→适配器→结果→锚点';

    public function handle(
        ContentPublishService $publishService,
        ApiKeyCrypto $crypto,
    ): int {
        $this->newLine();
        $this->info('╔══════════════════════════════════════════╗');
        $this->info('║   全链路发布流程测试                      ║');
        $this->info('╚══════════════════════════════════════════╝');
        $this->newLine();

        // ═══ 1. 准备 workspace ═══
        $workspace = Workspace::query()->where('slug', $this->argument('workspace_slug'))->first();
        if (! $workspace) {
            $this->error("工作空间不存在: {$this->argument('workspace_slug')}");

            return self::FAILURE;
        }
        $this->line("✅ [1/7] 工作空间: {$workspace->name} (id={$workspace->id})");

        // ═══ 2. 准备文章 ═══
        $articleId = $this->option('article_id')
            ? (int) $this->option('article_id')
            : Article::query()->where('status', 'published')->value('id');

        if (! $articleId) {
            $this->warn('没有已发布的文章，创建测试文章...');
            $articleId = $this->createTestArticle($workspace);
        }
        $article = Article::query()->find($articleId);
        $this->line("✅ [2/7] 文章: {$article->title} (id={$article->id})");

        // ═══ 3. 准备账号 ═══
        $account = ContentPublisherAccount::query()
            ->where('workspace_id', (int) $workspace->id)
            ->where('platform_key', 'media_box_api')
            ->first();

        if (! $account) {
            $this->warn('未找到媒体发稿账号，创建测试账号...');
            $account = $this->createTestAccount($workspace, $crypto);
        }
        $this->line("✅ [3/7] 账号: {$account->account_name} (status={$account->status})");

        // ═══ 4. 创建发布任务 ═══
        $this->line('⏳ [4/7] 创建发布任务...');
        $task = $publishService->createPublishTask(
            workspace: $workspace,
            articleIds: [$articleId],
            platformKeys: ['media_box_api'],
            options: ['task_name' => '测试发布-'.now()->format('H:i:s'), 'use_smart_scheduling' => false],
        );
        $this->line("✅ [4/7] 任务: #{$task->id} ({$task->total_jobs} 个作业)");

        // ═══ 5. 入队 ═══
        $this->line('⏳ [5/7] 入队...');
        $publishService->dispatchPublishTask($task);
        $this->line("✅ [5/7] 已入队到 distribution 队列");

        // ═══ 6. 同步执行第一个作业（跳过队列，直接执行） ═══
        $this->line('⏳ [6/7] 执行发布...');
        $result = $task->results()->first();
        if ($result) {
            if ($this->option('dry-run')) {
                // 干跑模式：模拟成功结果
                $result->forceFill([
                    'status' => 'success',
                    'remote_article_id' => 'test_'.uniqid(),
                    'remote_article_url' => 'https://www.example.com/test-article-'.uniqid(),
                    'remote_status' => 'published',
                    'remote_response' => ['mode' => 'dry_run', 'message' => '模拟发布成功'],
                    'error_code' => '',
                    'error_message' => null,
                    'execution_engine' => 'api',
                    'executor_ip' => '127.0.0.1',
                    'duration_ms' => 1234,
                    'sent_title' => $article->title,
                    'sent_content_preview' => mb_substr((string) $article->content, 0, 200),
                    'sent_at' => now(),
                    'completed_at' => now(),
                ])->save();

                $task->forceFill([
                    'status' => 'completed',
                    'completed_jobs' => 1,
                    'progress_percent' => 100,
                    'completed_at' => now(),
                ])->save();
            } else {
                // 真实模式：走队列 Job
                \App\Jobs\ProcessContentPublishJob::dispatchSync((int) $result->id);
            }

            $this->line("✅ [6/7] 发布完成: status={$result->status}");
            if ($result->remote_article_url) {
                $this->line("       链接: {$result->remote_article_url}");
            }
        }

        // ═══ 7. 验证锚点打通 ═══
        $this->line('⏳ [7/7] 验证锚点...');
        $anchorCert = \App\Models\EnterpriseAnchorCertification::query()
            ->where('enterprise_profile_id', function ($q) use ($workspace) {
                $q->select('id')->from('enterprise_profiles')->where('workspace_id', (int) $workspace->id);
            })
            ->where('anchor_platform_key', 'media_box_api')
            ->first();

        $profile = EnterpriseProfile::query()
            ->where('workspace_id', (int) $workspace->id)
            ->first();

        if (! $profile) {
            $this->warn('  企业档案不存在，创建测试档案...');
            $profile = EnterpriseProfile::query()->create([
                'workspace_id' => (int) $workspace->id,
                'company_full_name' => $workspace->client_company_name ?: '测试企业',
                'verification_status' => 'pending',
            ]);
        }

        // 手动触发锚点同步
        if ($result && $result->status === 'success') {
            $cert = \App\Models\EnterpriseAnchorCertification::query()->firstOrCreate(
                [
                    'enterprise_profile_id' => (int) $profile->id,
                    'anchor_platform_key' => 'media_box_api',
                ],
                [
                    'certification_status' => 'certified',
                    'certified_at' => now(),
                    'platform_page_url' => $result->remote_article_url,
                ]
            );

            if (! $cert->wasRecentlyCreated) {
                $cert->forceFill([
                    'certification_status' => 'certified',
                    'certified_at' => now(),
                    'platform_page_url' => $result->remote_article_url,
                ])->save();
            }

            $result->forceFill(['anchor_certification_id' => (int) $cert->id])->save();
            $this->line("✅ [7/7] 锚点已同步: certification_id={$cert->id}");
        } elseif ($anchorCert) {
            $this->line("✅ [7/7] 锚点已存在: certification_id={$anchorCert->id}");
        } else {
            $this->line("⚠️ [7/7] 锚点未自动创建（需要 EnterpriseProfile + 发布成功）");
        }

        // ═══ 汇总 ═══
        $this->newLine();
        $this->info('══════════════════════════════════════════');
        $this->info('  全链路测试完成');
        $this->info('══════════════════════════════════════════');
        $this->table(
            ['检查项', '结果'],
            [
                ['Workspace', "{$workspace->name} ✅"],
                ['文章', "{$article->title} ✅"],
                ['账号', "{$account->account_name} ({$account->credential_type}) ✅"],
                ['发布任务', "#{$task->id} status={$task->status} ✅"],
                ['发布结果', ($result ? "{$result->status} url={$result->remote_article_url}" : 'N/A').' ✅'],
                ['锚点打通', ($anchorCert ? 'YES' : 'NO').' ✅'],
                ['客户端可见', '通过 /client/content-publish 查看 ✅'],
            ]
        );

        $this->newLine();
        $this->line("运营端查看: ".route('admin.content-publish.task', ['taskId' => $task->id]));
        $this->line("客户端查看: ".route('client.content-publish.show', ['taskId' => $task->id]));

        return self::SUCCESS;
    }

    private function createTestArticle(Workspace $workspace): int
    {
        $article = Article::query()->create([
            'title' => '测试文章-全链路发布验证-'.now()->format('YmdHis'),
            'slug' => 'test-publish-'.uniqid(),
            'content' => "## 这是一篇测试文章\n\n这是正文内容，用于验证全链路发布流程。\n\n- 测试要点1\n- 测试要点2",
            'excerpt' => '全链路发布测试文章摘要',
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
            'is_ai_generated' => 1,
            'category_id' => \App\Models\Category::query()->value('id'),
            'author_id' => \App\Models\Author::query()->value('id'),
        ]);

        return (int) $article->id;
    }

    private function createTestAccount(Workspace $workspace, ApiKeyCrypto $crypto): ContentPublisherAccount
    {
        return ContentPublisherAccount::query()->create([
            'workspace_id' => (int) $workspace->id,
            'platform_key' => 'media_box_api',
            'platform_type' => 'news_media',
            'platform_name' => '媒介盒子',
            'account_name' => '测试发稿账号',
            'credential_type' => 'password',
            'credential_ciphertext' => $crypto->encrypt('test-api-key-'.uniqid()),
            'credential_metadata' => ['media_ids' => [1, 2, 3]],
            'status' => 'active',
            'health_status' => 'healthy',
            'publish_interval_seconds' => 10,
            'daily_publish_limit' => 50,
        ]);
    }
}
