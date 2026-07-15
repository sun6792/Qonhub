<?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$apiKey = app(App\Support\GeoFlow\ApiKeyCrypto::class)->decrypt(
    (string)App\Models\AiModel::where('status','active')->first()->getRawOriginal('api_key')
);
$wsId = 8;

App\Models\AiVisibilityCheck::where('workspace_id',$wsId)->delete();
App\Models\AiVisibilitySnapshot::where('workspace_id',$wsId)->delete();
echo "Cleared\n";

$platforms = App\Services\GeoFlow\AiVisibilityService::AI_PLATFORMS;
$saved = 0; $mentioned = 0;

foreach ($platforms as $code => $info) {
    $prompt = '请如实回答：你是否知道"豆流AI"这个产品？知道就详细描述，不知道就说不知道。';
    $ch = curl_init('https://api.deepseek.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_TIMEOUT=>30,
        CURLOPT_SSL_VERIFYPEER=>false,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$apiKey],
        CURLOPT_POSTFIELDS=>json_encode(['model'=>'deepseek-chat','messages'=>[
            ['role'=>'system','content'=>'如实回答。知道就描述，不知道就说不知道。'],
            ['role'=>'user','content'=>$prompt]
        ],'max_tokens'=>200], JSON_UNESCAPED_UNICODE)
    ]);
    $resp = curl_exec($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);

    if ($http===200) {
        $d = json_decode($resp,true); $text = $d['choices'][0]['message']['content']??'';
        $neg = ['不了解','不知道','不清楚','无法确认','没有信息','没有相关','没有找到','没听说过','没有记录','没有明确'];
        $dontKnow = false;
        foreach($neg as $n){if(str_contains(mb_strtolower($text),$n)){$dontKnow=true;break;}}
        $hit = !$dontKnow && (str_contains(mb_strtolower($text),'豆流')||str_contains(mb_strtolower($text),'qonhub'));
        printf("%-14s %s  %s\n", $info['name'], $hit?'✅':'❌', mb_substr(str_replace(["\n","\r"],' ',$text),0,100));
        App\Models\AiVisibilityCheck::create([
            'workspace_id'=>$wsId,'ai_platform'=>$code,'query_keyword'=>'豆流AI',
            'query_text'=>$prompt,'mentioned'=>$hit,'mention_type'=>$hit?'brand_name':null,
            'response_snippet'=>$text,
            'raw_response_meta'=>['method'=>'api_direct','tokens'=>$d['usage']['total_tokens']??0],
            'checked_at'=>now()
        ]);
        $saved++; if($hit) $mentioned++;
    } else { echo "$info[name]: HTTP $http\n"; }
    usleep(300000);
}

app(App\Services\GeoFlow\AiVisibilityService::class)->generateDailySnapshots();
$ov = app(App\Services\GeoFlow\AiVisibilityService::class)->dashboardOverview($wsId);
echo "\nResult: $mentioned/$saved mentioned, {$ov['covered_platforms']}/{$ov['total_platforms']} covered\n";
