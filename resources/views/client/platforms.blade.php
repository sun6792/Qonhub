<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>平台凭证中心 - Qonhub</title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen">
<div class="max-w-5xl mx-auto py-6 px-4">
  <div class="mb-4 flex items-center justify-between">
    <div>
      <h1 class="text-xl font-bold text-gray-900">🗄️ 平台凭证中心</h1>
      <p class="text-sm text-gray-500 mt-0.5">绑定后运营助手自动同步，一键分发。切换客户隔离缓存。</p>
    </div>
    <a href="{{ route('client.dashboard') }}" class="text-sm text-indigo-600 hover:underline">← 返回看板</a>
  </div>
  @if(session('message'))<div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700">{{ session('message') }}</div>@endif
  @if($errors->any())<div class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif

  @php
    $accts = \App\Models\ClientPlatformAccount::where('workspace_id',(int)$workspace->id)->get()->keyBy('platform_key');
    $totalBound = $accts->where('status','active')->count();

    // ── 三分类平台列表 ──
    $all = [];
    // 📱 自媒体 11个
    foreach(\App\Models\ClientPlatformAccount::supportedPlatforms() as $k=>$v){
      $all[]=['key'=>$k,'name'=>$v['name'],'cat'=>'📱 自媒体','reg_url'=>$v['login_url']??'#','badge'=>'⭐'];
    }
    // 📰 新闻媒体 24个（官媒12+行业12）
    $news = [
      ['sxkjb','山西科技报','https://www.sxkjb.com/','官媒'],['hqnews','河青新闻网','https://www.hqnews.cn/','官媒'],
      ['kejixinwen','科技新闻网','https://www.kejixinwen.com/','官媒'],['ldqxn','亮点黔西南','https://www.ldqxn.com/','官媒'],
      ['xianning','咸宁网','https://www.xnnews.cn/','官媒'],['lyrm','耒阳新闻网','https://www.ly-rm.cn/','官媒'],
      ['spnews','四平新闻网','https://www.dbnews.net/','官媒'],['zbnews','淄博新闻网','https://www.zbnews.net/','官媒'],
      ['redhongan','红安网','https://www.redhongan.com/','官媒'],['jdznews','景德镇新闻网','https://www.jdznews.com/','官媒'],
      ['ystf','云上团风','https://www.yunshangtuanfeng.com/','官媒'],['yancheng','盐城网','https://www.0515yc01.com/','官媒'],
      ['qsina','黔浪网','https://www.qsina.cn/','行业媒体'],['shangyexinzhi','商业新知','https://www.shangyexinzhi.com/','行业媒体'],
      ['cnblogs','博客园','https://www.cnblogs.com/','行业媒体'],['coatingol','涂料在线','https://www.coatingol.com/','行业媒体'],
      ['sinoasphalt','沥青在线','https://www.sinoasphalt.com/','行业媒体'],['huawang','华网','https://www.huawang.com/','行业媒体'],
      ['w10xitong','W10系统网','https://www.w10xitong.com/','行业媒体'],['piaoxian','飘仙建站','https://www.piaoxian.net/','行业媒体'],
      ['zhongji','中机在线','https://www.zhongji.cn/','行业媒体'],['okmart','中网化工','https://www.okmart.com/','行业媒体'],
      ['ntw360','中国涂料网','https://www.ntw360.com/','行业媒体'],['okbgh','OK资讯网','https://www.okkbgh.com/','行业媒体'],
    ];
    foreach($news as $n){ $all[]=['key'=>$n[0],'name'=>$n[1],'cat'=>'📰 新闻媒体','reg_url'=>$n[2],'badge'=>'']; }
    // 🏢 B2B 10个
    $b2b = [
      ['tz1288','天助网','https://www.tz1288.com/','🚀 聚合分发·1个顶30+'],
      ['b2b168','八方资源网','https://www.b2b168.com/','⭐'],['cn5135','无忧商务网','https://www.cn5135.com/',''],
      ['k2b2b','K2商务网','https://www.k2b2b.com/',''],['lswang','领商网','https://www.lswgmt.net/',''],
      ['wanjiabiz','万家商务网','https://www.wanjiabiz.com/',''],['jiuzhouziyuan','九州资源网','https://www.jiuzhouziyuan.com/',''],
      ['chaxun123','查询123','https://www.chaxun123.com/',''],['b2b188','B2B88商机导航','https://www.b2b188.cn/',''],
      ['qqwj','全球五金网','https://www.wjw.cn/',''],
    ];
    foreach($b2b as $b){ $all[]=['key'=>$b[0],'name'=>$b[1],'cat'=>'🏢 B2B锚点','reg_url'=>$b[2],'badge'=>$b[3]]; }
    // 排序：有badge排前面
    usort($all,function($a,$b){return(empty($a['badge'])?1:0)<=>(empty($b['badge'])?1:0);});
    $grouped=[];foreach($all as $p){$grouped[$p['cat']][]=$p;}
    $totalAll=count($all);
  @endphp

  <div class="mb-4 grid grid-cols-3 gap-3">
    <div class="bg-white rounded-xl shadow-sm p-4 text-center"><div class="text-2xl font-bold text-indigo-600">{{$totalBound}}</div><div class="text-xs text-gray-500">已绑定</div></div>
    <div class="bg-white rounded-xl shadow-sm p-4 text-center"><div class="text-2xl font-bold text-gray-400">{{$totalAll-$totalBound}}</div><div class="text-xs text-gray-500">待绑定</div></div>
    <div class="bg-white rounded-xl shadow-sm p-4 text-center"><div class="text-2xl font-bold text-gray-700">{{$totalAll}}</div><div class="text-xs text-gray-500">平台总数</div></div>
  </div>

  @foreach($grouped as $catName => $platforms)
  <div class="mb-4 bg-white rounded-xl shadow-sm overflow-hidden">
    @php $cb=0;foreach($platforms as $p){if(isset($accts[$p['key']])&&$accts[$p['key']]->isActive())$cb++;} @endphp
    <div class="border-b border-gray-100 px-5 py-3 flex items-center justify-between">
      <h2 class="text-sm font-semibold text-gray-800">{{$catName}}</h2>
      <span class="text-xs {{$cb>0?'text-green-600':'text-gray-400'}}">{{$cb}}/{{count($platforms)}}</span>
    </div>
    <div class="px-5 py-3"><div class="grid grid-cols-4 md:grid-cols-6 gap-2">
      @foreach($platforms as $p)
      @php $key=$p['key'];$acc=$accts->get($key);$bound=$acc&&$acc->isActive(); @endphp
      <div class="rounded-lg border p-2.5 {{$bound?'border-green-300 bg-green-50':'border-gray-200 bg-gray-50 hover:border-indigo-200'}}">
        <div class="flex items-center justify-between gap-1 mb-0.5">
          <span class="text-xs font-medium truncate {{!empty($p['badge'])?'text-red-700':'text-gray-800'}}" title="{{$p['name']}}">{{$p['name']}}</span>
          <span class="text-[10px] {{$bound?'text-green-600':'text-gray-300'}}">{{$bound?'✓':'-'}}</span>
        </div>
        @if(!empty($p['badge']))<div class="text-[9px] text-red-600 font-medium truncate mb-0.5">{{$p['badge']}}</div>@endif
        @if($bound&&$acc->platform_account_name)<div class="text-[10px] text-green-600 truncate mb-1.5">账号:{{$acc->platform_account_name}}</div>@endif
        @if(!$bound)
        @if(!empty($p['reg_url'])&&$p['reg_url']!=='#')
        <a href="{{$p['reg_url']}}" target="_blank" rel="noopener" class="block w-full text-center text-[10px] text-orange-600 border border-orange-200 rounded py-0.5 mb-1 hover:bg-orange-50">📝 前往注册 →</a>
        @endif
        <form method="POST" action="{{route('client.platforms.bind')}}">@csrf
          <input type="hidden" name="platform_key" value="{{$key}}">
          <input name="platform_account_name" required class="w-full rounded border border-gray-300 px-1.5 py-0.5 text-[10px] mb-1" placeholder="账号名（必填）">
          <textarea name="credential" class="w-full rounded border border-gray-300 px-1.5 py-0.5 text-[9px] mb-1" placeholder="Cookie/密码（选填）" rows="2"></textarea>
          <button class="w-full text-[10px] bg-indigo-600 text-white py-0.5 rounded hover:bg-indigo-700">保存凭证</button>
        </form>
        @else
        <form method="POST" action="{{route('client.platforms.unbind')}}">@csrf
          <input type="hidden" name="platform_key" value="{{$key}}">
          <button class="w-full text-[10px] text-red-400 hover:text-red-600 border border-red-200 rounded py-0.5">解绑</button>
        </form>
        @endif
      </div>
      @endforeach
    </div></div>
  </div>
  @endforeach
  <div class="text-center text-xs text-gray-400 mt-4">💡 绑定后运营助手自动同步。凭证加密存储，按客户隔离。天助网一次绑定覆盖30+站点。</div>
</div>
</body>
</html>
