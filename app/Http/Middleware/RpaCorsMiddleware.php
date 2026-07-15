<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * [新增] RPA API CORS 中间件：允许本地运营助手(localhost:9901)跨域访问。
 */
class RpaCorsMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // 预检请求直接返回200，Firefox严格要求
        if ($request->isMethod('OPTIONS')) {
            return response()->noContent(200)
                ->header('Access-Control-Allow-Origin', '*')
                ->header('Access-Control-Allow-Methods', 'GET,POST,OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Content-Type, X-Api-Key, Authorization')
                ->header('Access-Control-Max-Age', '86400');
        }

        $response = $next($request);

        return $response
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Methods', 'GET,POST,OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type, X-Api-Key, Authorization');
    }
}
