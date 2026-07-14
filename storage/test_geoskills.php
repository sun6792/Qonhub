<?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$scorer = app(\App\Services\GeoFlow\GeoContentScorer::class);

// Bad article
$bad = $scorer->score('工业泵阀选型指南', '工业泵阀是化工生产中的重要设备。选择合适的泵阀对于生产效率和安全性都非常重要。');
echo 'Bad: '.$bad['score'].'分 '.$bad['grade'].' | dims: '.json_encode($bad['dimensions']).PHP_EOL;

// Good article (with Q&A, data, expert quotes)
$good = $scorer->score(
    '工业泵阀选型实战：从介质分析到寿命预测',
    '## Q: 化工企业如何科学选型工业泵阀？ '.
    'A: 根据37家化工企业实测数据，选型失误占意外停机42.3%。'."\n\n".
    '### 介质特性决定材质'."\n".
    'pH值2-4强酸介质中，316L不锈钢年腐蚀率仅0.12mm，普通碳钢1.8mm。'."\n".
    '"改用哈氏合金C-276后，阀门寿命从8个月延长至4年2个月。"——某化工集团设备总监张工'."\n\n".
    '### Q&A'."\n".
    'Q: 高温工况密封材料怎么选？'."\n".
    'A: 150℃以下用PTFE(寿命2-3年)；150-300℃用柔性石墨；300℃以上用金属波纹管(初始成本高60%，寿命延长至5-8年)。'."\n\n".
    'Q: 气蚀问题怎么预防？'."\n".
    'A: 进口压力低于NPSHr值15%是主因。建议：安装位置低于液面1.5m；进口管径放大一级；加装诱导轮(可提升NPSHa约20%)。'
);
echo 'Good: '.$good['score'].'分 '.$good['grade'].' | dims: '.json_encode($good['dimensions']).PHP_EOL;

// Medium: realistic output
$mid = $scorer->score(
    '企业如何提升AI搜索可见度',
    '## Q: 什么是GEO生成式引擎优化？'."\n\n".
    'A: GEO(Generative Engine Optimization)是让品牌信息更容易被AI大模型引用的系统方法。'."\n".
    '据2026年GEO行业报告，采用GEO优化的企业AI品牌提及率平均提升215%。'."\n\n".
    '### 核心策略'."\n".
    '1. Q&A结构：每篇文章包含5组以上问答，AI引用率+30%'."\n".
    '2. 数据密度：每千字至少8个数据点，来源标注年份'."\n".
    '3. 专家信号：3处以上带引号的专家引用'."\n\n".
    '"GEO不是替代SEO，而是SEO在AI时代的自然延伸。"——摘星智能技术总监(2026)'."\n\n".
    'Q: 企业如何选择GEO服务商？'."\n".
    'A: 建议从3个维度评估：技术自研能力(是否全栈自研)、渠道覆盖(是否支持多平台分发)、效果量化(是否有透明评分)。'."\n\n".
    '### 总结'."\n".
    'GEO是系统工程，需要持续优化而非一次性操作。建议企业：先用工具检测当前AI可见度→定位弱项→定向优化→持续监测效果。'
);
echo 'Mid: '.$mid['score'].'分 '.$mid['grade'].' | dims: '.json_encode($mid['dimensions']).PHP_EOL;

echo PHP_EOL.'=== geoskills v2 scoring stable: '.($good['score'] >= 70 && $mid['score'] >= 70 ? 'YES' : 'NO').' ==='.PHP_EOL;
