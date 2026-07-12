<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$s = app(App\Services\GeoFlow\GeoContentScorer::class);
$a = App\Models\Article::find(85);
if (! $a) { echo "ID 85 NOT FOUND\n"; exit(1); }

$c = (string) $a->content;

echo "=== 真实文章 ID:85 ===\n";
echo "标题: " . $a->title . "\n";
echo "总字数: " . mb_strlen($c) . "\n";

// geoskills evidence 1: Q&A
$qaCount = preg_match_all('/[？?]/u', $c, $m);
echo "\n🔍 geoskills Q&A: $qaCount 个问号\n";
echo "   FAQ段落: " . (mb_strpos($c, '常见问题') !== false ? "✅ 已插入" : "❌ 缺失") . "\n";
echo "   结论前置: " . (preg_match('/^.{0,100}[。.]\s*[^。.]*[是为]/u', $c) ? "✅" : "⚠️") . "\n";

// geoskills evidence 2: expert
$hasExpert = mb_strpos($c, '表示') !== false;
$hasQuote = preg_match_all('/"[^"]{10,}"/us', $c, $m2);
echo "\n🔍 geoskills 专家信号:\n";
echo "   '表示'句式: " . ($hasExpert ? "✅ " . mb_substr_count($c, '表示') . "处" : "❌") . "\n";
echo "   引号引用: " . $hasQuote . " 处\n";
$hasDataSource = mb_strpos($c, '数据') !== false && (mb_strpos($c, '显示') !== false || mb_strpos($c, '统计') !== false || mb_strpos($c, '报告') !== false);
echo "   数据来源: " . ($hasDataSource ? "✅" : "❌") . "\n";

// geoskills evidence 3: data
$r = $s->score($a->title, $c);
echo "\n🔍 geoskills 数据密度: " . $r['data_count'] . " 个数据点\n";
echo "   百分比: " . preg_match_all('/\d+%/u', $c, $m3) . " 处\n";
echo "   数值+单位: " . preg_match_all('/\d+[万亿千百吨公斤米升元个家]/u', $c, $m4) . " 处\n";
echo "   年份: " . preg_match_all('/\d{4}年/u', $c, $m4b) . " 处\n";

// geoskills evidence 4: hedge words
$hedgeCount = preg_match_all('/可能|也许|或许|大概|似乎|显得|一定程度上|大约|差不多|通常|往往|一般/u', $c, $m5);
echo "\n🔍 geoskills 虚词清洗: $hedgeCount 个虚词" . ($hedgeCount > 5 ? " ❌" : " ✅") . "\n";

// geoskills evidence 5: structure
$h2Count = preg_match_all('/^##\s/mu', $c, $m6);
echo "\n🔍 geoskills 结构: $h2Count 个 H2 标题" . ($h2Count >= 3 ? " ✅" : " ❌") . "\n";

// geoskills evidence 6: self-containment
$pronouns = preg_match_all('/\b(它|他们|她们|它们|这个|那个|这些|那些|他|她)\b/u', $c, $m7);
$wordCount = mb_strlen($c, 'UTF-8');
$pronounRatio = $wordCount > 0 ? round($pronouns / $wordCount * 100, 2) : 0;
echo "\n🔍 geoskills 自包含性: 代词密度 $pronounRatio%" . ($pronounRatio < 2 ? " ✅" : " ⚠️") . "\n";

// FINAL SCORE
echo "\n════════════════════════════\n";
echo "  GEO 总分: " . $r['score'] . " [" . $r['grade'] . "]\n";
echo "  QA:" . $r['dimensions']['answer_quality'] . " 专家:" . $r['dimensions']['expertise_signals'] . "\n";
echo "  数据:" . $r['dimensions']['statistical_density'] . " 结构:" . $r['dimensions']['structural_clarity'] . "\n";
echo "  自包含:" . $r['dimensions']['self_containment'] . " 虚词:" . $r['dimensions']['hedge_score'] . "\n";
echo "════════════════════════════\n";

// Content evidence: show the enhancement section
echo "\n=== 增强内容证据（末500字） ===\n";
echo mb_substr($c, max(0, mb_strlen($c) - 500)) . "\n";
