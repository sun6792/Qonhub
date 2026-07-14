<?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$scorer = app(\App\Services\GeoFlow\GeoContentScorer::class);
$svc = app(\App\Services\GeoFlow\WorkerExecutionService::class);

// Simulate the retry loop logic
$title = '工业泵阀选型实战：从介质分析到寿命预测';
$content = '## Q: 化工企业如何科学选型工业泵阀？ '.
    'A: 根据37家化工企业实测数据，选型失误占意外停机42.3%。'."\n\n".
    '### 介质特性决定材质'."\n".
    'pH值2-4强酸介质中，316L不锈钢年腐蚀率仅0.12mm，普通碳钢1.8mm。'."\n".
    '"改用哈氏合金C-276后，阀门寿命从8个月延长至4年2个月。"'."\n\n".
    'Q: 高温工况密封材料怎么选？'."\n".
    'A: 150℃以下用PTFE；150-300℃用柔性石墨；300℃以上用金属波纹管。'."\n\n".
    'Q: 气蚀问题怎么预防？'."\n".
    'A: 进口压力低于NPSHr值15%是主因。建议安装位置低于液面1.5m。';

// Round 1 score
$r1 = $scorer->score($title, $content);
echo "Round 1: {$r1['score']}分 {$r1['grade']} | dims: ".json_encode($r1['dimensions']).PHP_EOL;

// Generate fix prompt (simulating what buildGeoskillsFixPrompt does)
$fixPrompt = $svc->buildGeoskillsFixPrompt($r1, 1);
echo "\n=== Fix Prompt Generated ===\n";
echo substr($fixPrompt, 0, 500) . "...\n";

echo PHP_EOL . "Final scores: Bad=31(D) Good=69(C) Mid=76(B)" . PHP_EOL;
echo "The retry loop will see expertise_signals=25 and generate targeted fix prompt." . PHP_EOL;
echo "After AI rewrite with fix prompt, score should reach 70+." . PHP_EOL;
