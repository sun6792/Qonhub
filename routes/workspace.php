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
        Route::post('{slug}/client-user/{clientUserId}/delete', [WorkspaceController::class, 'deleteClientUser'])->name('client-user.delete')->whereNumber('clientUserId');
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

    // --- 企业信息锚点管理（B2B GEO 认证） ---
    Route::prefix('enterprise-anchor')->name('enterprise-anchor.')->group(function (): void {
        Route::get('/', [\App\Http\Controllers\Admin\EnterpriseAnchorController::class, 'overview'])->name('overview');
        Route::get('{slug}', [\App\Http\Controllers\Admin\EnterpriseAnchorController::class, 'manage'])->name('manage');
        Route::post('{slug}/profile', [\App\Http\Controllers\Admin\EnterpriseAnchorController::class, 'saveProfile'])->name('save-profile');
        Route::post('{slug}/certify', [\App\Http\Controllers\Admin\EnterpriseAnchorController::class, 'markCertified'])->name('mark-certified');
        Route::post('{slug}/revoke', [\App\Http\Controllers\Admin\EnterpriseAnchorController::class, 'revokeCertification'])->name('revoke-certification');
        Route::post('{slug}/napw-check', [\App\Http\Controllers\Admin\EnterpriseAnchorController::class, 'checkNapw'])->name('check-napw');
        Route::post('{slug}/rpa-register/{platformKey}', [\App\Http\Controllers\Admin\EnterpriseAnchorController::class, 'rpaRegister'])->name('rpa-register');
    });

    // --- 全渠道内容发布运营台 ---
    Route::prefix('content-publish')->name('content-publish.')->group(function (): void {
        Route::get('/', [\App\Http\Controllers\Admin\ContentPublishController::class, 'index'])->name('index');
        Route::post('/store', [\App\Http\Controllers\Admin\ContentPublishController::class, 'store'])->name('store');
        Route::get('/task/{taskId}', [\App\Http\Controllers\Admin\ContentPublishController::class, 'taskDetail'])->name('task')->whereNumber('taskId');
        Route::post('/task/{taskId}/retry', [\App\Http\Controllers\Admin\ContentPublishController::class, 'retry'])->name('retry')->whereNumber('taskId');
        Route::post('/task/{taskId}/cancel', [\App\Http\Controllers\Admin\ContentPublishController::class, 'cancel'])->name('cancel')->whereNumber('taskId');
        Route::get('/task/{taskId}/progress', [\App\Http\Controllers\Admin\ContentPublishController::class, 'taskProgress'])->name('progress')->whereNumber('taskId');
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
    Route::get('/competitiveness', [ClientPortalController::class, 'competitiveness'])->name('competitiveness');
    Route::post('/content-request', [ClientPortalController::class, 'contentRequestStore'])->name('content-request.store');
    Route::post('/enterprise-profile/save', [ClientPortalController::class, 'enterpriseProfileSave'])->name('enterprise-profile.save');
    Route::post('/competitiveness/store', [ClientPortalController::class, 'competitorStore'])->name('competitiveness.store');
    Route::post('/competitiveness/delete/{id}', [ClientPortalController::class, 'competitorDelete'])->name('competitiveness.delete')->whereNumber('id');
    Route::get('/platforms', [ClientPortalController::class, 'platforms'])->name('platforms');
    Route::post('/platforms/bind', [ClientPortalController::class, 'platformStore'])->name('platforms.bind');
    Route::post('/platforms/unbind', [ClientPortalController::class, 'platformUnbind'])->name('platforms.unbind');

    // 客户端一键发布中心
    Route::prefix('content-publish')->name('content-publish.')->group(function (): void {
        Route::get('/', [\App\Http\Controllers\Client\ContentPublishController::class, 'index'])->name('index');
        Route::get('/create', [\App\Http\Controllers\Client\ContentPublishController::class, 'create'])->name('create');
        Route::post('/store', [\App\Http\Controllers\Client\ContentPublishController::class, 'store'])->name('store');
        // B2B 企业认证
        Route::get('/certify', [\App\Http\Controllers\Client\ContentPublishController::class, 'certify'])->name('certify');
        Route::post('/certify-store', [\App\Http\Controllers\Client\ContentPublishController::class, 'certifyStore'])->name('certify-store');
        Route::get('/{taskId}', [\App\Http\Controllers\Client\ContentPublishController::class, 'show'])->name('show')->whereNumber('taskId');
    });

    // 兼容旧token链接（已登录客户自动跳转）
    Route::get('{slug}', function (string $slug) {
        return redirect()->route('client.login');
    });
});
