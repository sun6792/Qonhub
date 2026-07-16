<?php
/** Scout URL 导入 v2.6.1 — curl快速抓 + Playwright SPA兜底 */
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$wsId = (int)($argv[1] ?? 0);
$platform = $argv[2] ?? '';
$targetUrl = $argv[3] ?? '';
$keyword = $argv[4] ?? '';

if (!$wsId || !$platform || !$targetUrl || !$keyword) {
    echo "用法: php scout_from_url.php <ws_id> <platform> <url> <keyword>\n";
    echo "示例: php scout_from_url.php 8 doubao https://www.doubao.com/thread/xxx 豆流AI\n";
    exit(1);
}

$ps = App\Services\GeoFlow\AiVisibilityService::AI_PLATFORMS;
if (!isset($ps[$platform])) { echo "未知平台\n"; exit(1); }
$info = $ps[$platform];

echo "━━━ Scout URL 导入 ━━━\n";
echo "平台: {$info['name']} | 关键词: $keyword\n";

// ── 第1级: PHP curl 快速抓取 ──
$ch = curl_init($targetUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 20, CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
]);
$html = curl_exec($ch);
curl_close($ch);
$text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8')));

// ── SPA检测: 文字太少/全是JS代码 → 触发Playwright渲染 ──
$isSpa = strlen($text) < 500 || str_contains($text, 'window.gfdatav1') || str_contains($text, '__webpack');

if ($isSpa) {
    echo "检测到SPA页面, Playwright渲染中...\n";
    $nodeScript = realpath(__DIR__.'/../rpa-engine/automations/render_url.cjs');
    $cmd = 'cd '.escapeshellarg(__DIR__.'/../rpa-engine').' && node '.escapeshellarg($nodeScript).' '.escapeshellarg($targetUrl).' 2>&1';
    exec($cmd, $out);
    $json = json_decode(implode("\n", $out), true);
    if ($json && ($json['status'] ?? '') === 'success') {
        $text = $json['text'];
        echo "Playwright渲染: ".strlen($text)."字符\n";
    } else {
        echo "渲染失败, 用curl结果继续\n";
    }
}

// ── 命中判定（否定词前置过滤） ──
$negatives = ['不了解','不知道','不清楚','无法确认','没有信息','没有相关',
    '没有找到','没听说过','没有记录','没有明确','没有公开','没有足够','并不存在'];
$dontKnow = false;
foreach ($negatives as $n) { if (mb_strpos($text, $n) !== false) { $dontKnow = true; break; } }

$hit = !$dontKnow && mb_strpos($text, $keyword) !== false;

// 引用数提取
$refs = null;
if (preg_match('/参考\s*(\d+)\s*篇/', $text, $m)) $refs = (int)$m[1];

echo "提及: ".($hit?'✅ YES':'❌ NO')." | 参考: ".($refs?:'未知')."篇\n";

// ── 入库 ──
App\Models\AiVisibilityCheck::create([
    'workspace_id' => $wsId,
    'ai_platform' => $platform,
    'query_keyword' => $keyword,
    'query_text' => $keyword,
    'mentioned' => $hit,
    'mention_type' => $hit ? 'brand_name' : null,
    'response_snippet' => $text,
    'raw_response_meta' => [
        'method' => 'url_import',
        'source_url' => $targetUrl,
        'refs' => $refs,
        'content_length' => strlen($text),
    ],
    'checked_at' => now(),
]);

app(App\Services\GeoFlow\AiVisibilityService::class)->generateDailySnapshots();
$ov = app(App\Services\GeoFlow\AiVisibilityService::class)->dashboardOverview($wsId);
echo "Dashboard: {$ov['covered_platforms']}/{$ov['total_platforms']} 平台覆盖\n";

$latest = App\Models\AiVisibilityCheck::where('workspace_id', $wsId)->latest()->first();
echo "📋 快照: /client/snapshot/{$latest->id}\n";
