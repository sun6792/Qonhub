<?php

/**
 * 工作空间、超管监控台、客户看板路由。
 *
 * 本文件通过 WorkspaceServiceProvider 加载，所有路由均挂在已有中间件组下，
 * 不修改 web.php / api.php 等现有路由文件。
 */

use App\Http\Controllers\Admin\AiVisibilityController;
use App\Http\Controllers\Admin\OperatorMonitorController;
use App\Http\Controllers\Admin\PlatformAccountController;
use App\Http\Controllers\Admin\WorkspaceController;
use App\Http\Controllers\Site\ClientPortalController;
use Illuminate\Support\Facades\Route;

// ===== 全部包裹在 web 中间件组内 =====
$adminPrefix = trim((string) config('geoflow.admin_base_path', '/geo_admin'), '/');

Route::middleware('web')->group(function () use ($adminPrefix): void {

Route::prefix($adminPrefix)->name('admin.')->middleware(['admin.auth', 'admin.activity'])->group(function (): void {

    // --- 工作空间 CRUD（所有登录管理员可用） ---
    Route::prefix('workspaces')->name('workspaces.')->group(function (): void {
        Route::get('/', [WorkspaceController::class, 'index'])->name('index');
        Route::get('create', [WorkspaceController::class, 'create'])->name('create');
        Route::post('create', [WorkspaceController::class, 'store'])->name('store');
        Route::get('{slug}', [WorkspaceController::class, 'show'])->name('show');
        Route::get('{slug}/edit', [WorkspaceController::class, 'edit'])->name('edit');
        Route::put('{slug}', [WorkspaceController::class, 'update'])->name('update');
        Route::post('{slug}/assign', [WorkspaceController::class, 'assignResource'])->name('assign');
        Route::post('{slug}/regenerate-token', [WorkspaceController::class, 'regenerateToken'])->name('regenerate-token');
        Route::post('{slug}/client-user', [WorkspaceController::class, 'createClientUser'])->name('client-user.create');
        Route::post('{slug}/toggle-platform', [WorkspaceController::class, 'togglePlatformStatus'])->name('toggle-platform');
        Route::post('{slug}/client-user/reset-password', [WorkspaceController::class, 'resetClientPassword'])->name('client-user.reset-password');
        Route::get('{slug}/client-user/{clientUserId}/reveal-password', [WorkspaceController::class, 'revealClientPassword'])->name('client-user.reveal-password')->whereNumber('clientUserId');
        Route::post('{slug}/delete', [WorkspaceController::class, 'destroy'])->name('delete');
    });

    // --- 平台账号管理 ---
    Route::prefix('platform-accounts')->name('platform-accounts.')->group(function (): void {
        Route::get('/', [PlatformAccountController::class, 'index'])->name('index');
        Route::get('{workspaceId}', [PlatformAccountController::class, 'show'])->name('show')->whereNumber('workspaceId');
        Route::post('{workspaceId}/{platformKey}/revoke', [PlatformAccountController::class, 'revoke'])
            ->name('revoke')->whereNumber('workspaceId');
    });

    // --- AI引用报告 ---
    Route::prefix('ai-visibility')->name('ai-visibility.')->group(function (): void {
        Route::get('/', [AiVisibilityController::class, 'index'])->name('index');
        Route::get('{workspaceId}', [AiVisibilityController::class, 'show'])->name('show')->whereNumber('workspaceId');
        Route::post('check', [AiVisibilityController::class, 'triggerCheck'])->name('check');
    });

    // --- 超管监控台 ---
    Route::prefix('operator-monitor')->name('operator-monitor.')->middleware('admin.super')->group(function (): void {
        Route::get('/', [OperatorMonitorController::class, 'index'])->name('index');
        Route::get('{adminId}', [OperatorMonitorController::class, 'detail'])->name('detail')->whereNumber('adminId');
    });
});

}); // 关闭 web 中间件组

// ===== 客户端看板路由 =====
Route::middleware('web')->prefix('client')->name('client.')->group(function (): void {
    Route::get('login', [\App\Http\Controllers\Site\ClientAuthController::class, 'showLoginForm'])->name('login');
    Route::post('login', [\App\Http\Controllers\Site\ClientAuthController::class, 'login'])->name('login.attempt');
    Route::post('logout', [\App\Http\Controllers\Site\ClientAuthController::class, 'logout'])->name('logout');

    Route::get('/', [ClientPortalController::class, 'dashboard'])->name('dashboard');
    Route::get('/articles', [ClientPortalController::class, 'articles'])->name('articles');
    Route::get('/ai-visibility', [ClientPortalController::class, 'aiVisibility'])->name('ai-visibility');

    // 兼容旧token链接（已登录客户自动跳转）
    Route::get('{slug}', function (string $slug) {
        return redirect()->route('client.login');
    });
});
