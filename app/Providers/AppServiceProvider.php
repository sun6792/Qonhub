<?php

namespace App\Providers;

use App\Models\Admin;
use App\Services\Admin\AdminUpdateMetadataService;
use App\Services\Admin\AdminWelcomeModalService;
use App\Services\AI\AgentFactory;
use App\Services\AI\LlmAdapterFactory;
use App\Services\AI\LlmOrchestratorService;
use App\Services\AI\TokenQuotaService;
use App\Services\Agent\AgentDispatcherService;
use App\Services\Agent\ScoutAgentService;
use App\Services\Agent\StrategyAgentService;
use App\Services\Agent\ContentAgentService;
use App\Services\Agent\DeployAgentService;
use App\Services\Agent\ReviewAgentService;
use App\Services\Agent\AgentToolRegistry;
use App\Services\Agent\RpaRoutingDecider;
use App\Services\Agent\Tools\AnchorStatusTool;
use App\Services\Agent\Tools\GeoScoreTool;
use App\Services\Agent\Tools\KeywordLibraryTool;
use App\Services\Agent\Tools\KnowledgeRetrievalTool;
use App\Services\Agent\Tools\RpaPublishTool;
use App\Services\Agent\Tools\PlaywrightMcpTool;
use App\Services\Agent\Tools\SensitiveWordTool;
use App\Services\GeoFlow\ArticleGeoFlowService;
use App\Services\GeoFlow\HorizonMetricsAdapter;
use App\Services\GeoFlow\JobQueueService;
use App\Services\GeoFlow\TaskLifecycleService;
use App\Services\GeoFlow\TaskMonitoringQueryService;
use App\Support\GeoFlow\OutboundHttpProxy;
use App\View\Composers\SiteLayoutComposer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(JobQueueService::class);
        $this->app->singleton(HorizonMetricsAdapter::class);
        $this->app->singleton(TaskMonitoringQueryService::class);
        $this->app->singleton(TaskLifecycleService::class);
        $this->app->singleton(ArticleGeoFlowService::class);
        $this->app->singleton(LlmOrchestratorService::class);
        $this->app->singleton(LlmAdapterFactory::class);
        $this->app->singleton(TokenQuotaService::class);
        $this->app->singleton(AgentFactory::class);
        $this->app->singleton(AgentDispatcherService::class);
        $this->app->singleton(ScoutAgentService::class);
        $this->app->singleton(StrategyAgentService::class);
        $this->app->singleton(ContentAgentService::class);
        $this->app->singleton(DeployAgentService::class);
        $this->app->singleton(ReviewAgentService::class);
        $this->app->singleton(RpaRoutingDecider::class);
        $this->app->singleton(AgentToolRegistry::class, function ($app) {
            $registry = new AgentToolRegistry;
            // 注册 6 个标准工具
            $registry->registerMany([
                $app->make(GeoScoreTool::class),
                $app->make(KnowledgeRetrievalTool::class),
                $app->make(SensitiveWordTool::class),
                $app->make(AnchorStatusTool::class),
                $app->make(RpaPublishTool::class),
                $app->make(KeywordLibraryTool::class),
            ]);
            // 配置白名单
            $allTools = ['geo_score', 'knowledge_retrieval', 'sensitive_word_check', 'anchor_status', 'rpa_publish', 'keyword_library'];
            $registry->setWhitelist('scout', ['anchor_status', 'keyword_library']);
            $registry->setWhitelist('strategy', $allTools);
            $registry->setWhitelist('content', ['geo_score', 'knowledge_retrieval', 'sensitive_word_check']);
            $registry->setWhitelist('deploy', ['rpa_publish', 'anchor_status', 'playwright_mcp']);
            $registry->setWhitelist('review', $allTools);

            // Phase 4: 注册 Playwright MCP 工具
            $registry->register($app->make(PlaywrightMcpTool::class));

            return $registry;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 全局限流配置
        RateLimiter::for('api', fn ($request) => Limit::perMinute(120)->by(
            optional($request->user('admin'))->id ?: $request->ip()
        ));
        RateLimiter::for('rpa', fn ($request) => Limit::perMinute(300)->by(
            $request->header('X-Api-Key') ?: $request->ip()
        ));

        Http::globalMiddleware(OutboundHttpProxy::middleware());

        // 本地开发环境跳过 SSL 验证（企业网络 HTTPS 拦截兼容）
        if (app()->environment('local')) {
            Http::globalMiddleware(static function (callable $handler): callable {
                return static function (\Psr\Http\Message\RequestInterface $request, array $options) use ($handler) {
                    $options['verify'] = false;
                    return $handler($request, $options);
                };
            });
        }

        View::composer(['site.layout', 'theme.*.layout'], SiteLayoutComposer::class);

        View::composer('admin.layouts.app', function ($view): void {
            $admin = auth('admin')->user();
            $view->with(
                'adminWelcomeModalPayload',
                $admin instanceof Admin ? app(AdminWelcomeModalService::class)->buildModalPayload($admin) : null
            );
            $view->with(
                'adminUpdateNotificationPayload',
                $admin instanceof Admin ? app(AdminUpdateMetadataService::class)->buildNotificationPayload() : null
            );
        });
    }
}
