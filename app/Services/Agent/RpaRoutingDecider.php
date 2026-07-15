<?php

namespace App\Services\Agent;

/**
 * RPA 双轨路由决策器 — 纯代码规则，不由 LLM 决策。
 *
 * 决策规则：
 *   - 有成熟自研脚本 (15个) → native_rpa
 *   - 有 Open API → direct_api
 *   - 其余 → playwright_mcp (Phase 4 启用)
 */
class RpaRoutingDecider
{
    /** @var list<string> 已有成熟 RPA 脚本的渠道 */
    private const NATIVE_RPA_PLATFORMS = [
        'b2b168', 'shunqi', 'huangye88', 'tz1288', 'k2b2b', 'lswang',
        'chaxun123', 'jiuzhouziyuan', 'wanjiabiz', 'wjw', 'cn5135',
        'toutiao_publish', 'baijiahao_publish', 'xiaohongshu_publish', 'sohu_publish',
    ];

    /** @var list<string> 有 Open API 的渠道 */
    private const DIRECT_API_PLATFORMS = [
        'media_box_api',
    ];

    /**
     * 自研 RPA 失败计数器（Redis key: rpa_fail:{workspaceId}:{platformKey}）。
     */
    private const NATIVE_RPA_FAIL_THRESHOLD = 2;

    /**
     * 决定指定平台走哪条路由轨道。
     *
     * 规则（纯代码决策，不由 LLM 判断）：
     *   1. 有成熟脚本 + 未连续失败 ≥2 次 → native_rpa
     *   2. 有成熟脚本 + 连续失败 ≥2 次 → playwright_mcp（降级）
     *   3. 有 Open API → direct_api
     *   4. 其他 → playwright_mcp（新渠道自适应）
     *
     * @return string 'native_rpa' | 'direct_api' | 'playwright_mcp'
     */
    public function decide(string $platformKey, int $workspaceId = 0): string
    {
        // 有成熟脚本 → 先走自研 RPA
        if (in_array($platformKey, self::NATIVE_RPA_PLATFORMS, true)) {
            // 连续失败 ≥2 次 → 自动降级到 Playwright MCP
            if ($workspaceId > 0 && $this->shouldFallbackToMcp($workspaceId, $platformKey)) {
                return 'playwright_mcp';
            }
            return 'native_rpa';
        }

        // 有 Open API → 直连
        if (in_array($platformKey, self::DIRECT_API_PLATFORMS, true)) {
            return 'direct_api';
        }

        // 新渠道 → Playwright MCP 自适应
        return 'playwright_mcp';
    }

    /**
     * 记录自研 RPA 执行结果（用于降级决策）。
     */
    public function recordNativeRpaResult(int $workspaceId, string $platformKey, bool $success): void
    {
        $key = "rpa_fail:{$workspaceId}:{$platformKey}";
        if ($success) {
            \Illuminate\Support\Facades\Cache::forget($key);
        } else {
            \Illuminate\Support\Facades\Cache::increment($key, 1);
            \Illuminate\Support\Facades\Cache::expire($key, 3600);
        }
    }

    /**
     * 检查是否应降级到 Playwright MCP。
     */
    private function shouldFallbackToMcp(int $workspaceId, string $platformKey): bool
    {
        $failures = (int) \Illuminate\Support\Facades\Cache::get("rpa_fail:{$workspaceId}:{$platformKey}", 0);
        return $failures >= self::NATIVE_RPA_FAIL_THRESHOLD;
    }

    /**
     * 检查是否有成熟脚本。
     */
    public function hasMatureScript(string $platformKey): bool
    {
        return in_array($platformKey, self::NATIVE_RPA_PLATFORMS, true);
    }

    /**
     * 返回所有支持的路由方式。
     */
    public function availableRoutes(): array
    {
        return [
            'native_rpa' => [
                'count' => count(self::NATIVE_RPA_PLATFORMS),
                'description' => '15个成熟自研Playwright脚本，稳定高效',
            ],
            'direct_api' => [
                'count' => count(self::DIRECT_API_PLATFORMS),
                'description' => '平台Open API直连',
            ],
            'playwright_mcp' => [
                'count' => 0,
                'description' => 'Playwright MCP自适应（Phase 4启用）',
            ],
        ];
    }
}
