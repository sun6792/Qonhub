<?php

namespace App\Providers;

use App\Services\GeoFlow\AiVisibilityService;
use App\Services\GeoFlow\PlatformAccountService;
use App\Services\GeoFlow\WorkspaceService;
use Illuminate\Support\ServiceProvider;

class WorkspaceServiceProvider extends ServiceProvider
{
    /**
     * 注册服务绑定。
     */
    public function register(): void
    {
        $this->app->singleton(WorkspaceService::class, fn (): WorkspaceService => new WorkspaceService);
        $this->app->singleton(PlatformAccountService::class, function ($app): PlatformAccountService {
            return new PlatformAccountService($app->make(\App\Support\GeoFlow\ApiKeyCrypto::class));
        });
        $this->app->singleton(AiVisibilityService::class, function ($app): AiVisibilityService {
            return new AiVisibilityService($app->make(\App\Support\GeoFlow\ApiKeyCrypto::class));
        });
    }

    /**
     * 启动：加载工作空间路由。
     */
    public function boot(): void
    {
        // 路由已通过 bootstrap/app.php 的 then 回调加载

        // 注册 Console 命令
        if ($this->app->runningInConsole()) {
            $this->commands([
                \App\Console\Commands\CheckAiVisibilityCommand::class,
            ]);
        }
    }
}
