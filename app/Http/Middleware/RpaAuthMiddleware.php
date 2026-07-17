<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * RPA 引擎 API 认证中间件：校验 X-Api-Key 头。
 *
 * 组合使用 `rpa.cors` + `rpa.auth` 实现 CORS 开放 + 密钥认证。
 * API Key 来自 GEOFLOW_RPA_ENGINE_API_KEY 环境变量（配置项 geoflow.rpa_engine_api_key）。
 */
class RpaAuthMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('geoflow.rpa_engine_api_key', '');

        // 未配置 API Key 时拒绝所有请求（安全优先）
        if ($expected === '' || $expected === 'qonhub-rpa-secret-change-me') {
            // 允许本地开发环境使用默认 key，但记录警告
            if (app()->environment('local') && $expected === 'qonhub-rpa-secret-change-me') {
                Log::warning('RPA Engine: using default API key. Set RPA_ENGINE_API_KEY in .env for production.');
            } else {
                return response()->json([
                    'ok' => false,
                    'error' => 'RPA API Key 未配置或仍为默认值，请在 .env 中设置 RPA_ENGINE_API_KEY',
                ], 500);
            }
        }

        $provided = trim((string) $request->header('X-Api-Key', ''));

        if ($provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json([
                'ok' => false,
                'error' => 'Unauthorized: 无效或缺失 X-Api-Key',
            ], 401);
        }

        return $next($request);
    }
}
