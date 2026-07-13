@extends('client.layout')

@section('content')
@php
  $accts = \App\Models\ClientPlatformAccount::where('workspace_id',(int)$workspace->id)->get()->keyBy('platform_key');
  $totalBound = $accts->where('status','active')->count();

  $all = [];
  foreach(\App\Models\ClientPlatformAccount::supportedPlatforms() as $k=>$v){
    $all[]=['key'=>$k,'name'=>$v['name'],'cat'=>'📱 自媒体','reg_url'=>$v['login_url']??'#','badge'=>'⭐'];
  }
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
  $b2b = [
    ['tz1288','天助网','https://www.tz1288.com/','🚀 1个顶30+'],['b2b168','八方资源网','https://www.b2b168.com/','⭐'],
    ['cn5135','无忧商务网','https://www.cn5135.com/',''],['k2b2b','K2商务网','https://www.k2b2b.com/',''],
    ['lswang','领商网','https://www.lswgmt.net/',''],['wanjiabiz','万家商务网','https://www.wanjiabiz.com/',''],
    ['jiuzhouziyuan','九州资源网','https://www.jiuzhouziyuan.com/',''],['chaxun123','查询123','https://www.chaxun123.com/',''],
    ['b2b188','B2B88商机导航','https://www.b2b188.cn/',''],['qqwj','全球五金网','https://www.wjw.cn/',''],
  ];
  foreach($b2b as $b){ $all[]=['key'=>$b[0],'name'=>$b[1],'cat'=>'🏢 B2B锚点','reg_url'=>$b[2],'badge'=>$b[3]]; }
  usort($all,function($a,$b){return(empty($a['badge'])?1:0)<=>(empty($b['badge'])?1:0);});
  $grouped=[];foreach($all as $p){$grouped[$p['cat']][]=$p;}
  $totalAll=count($all);
@endphp

<div class="space-y-5">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-ai-primary">🗄️ 平台凭证中心</h1>
            <p class="text-sm text-ai-secondary mt-1">绑定后运营助手自动同步，一键分发。切换客户隔离缓存。</p>
        </div>
        <a href="{{ route('client.dashboard') }}" class="text-sm hover:underline" style="color:#a5b4fc">← 返回看板</a>
    </div>

    @if(session('message'))<div class="rounded-lg px-4 py-3 text-sm" style="background:rgba(52,211,153,0.1); border:1px solid rgba(52,211,153,0.2); color:#6ee7b7">{{ session('message') }}</div>@endif
    @if(isset($errors) && $errors->any())<div class="rounded-lg px-4 py-3 text-sm" style="background:rgba(248,113,113,0.1); border:1px solid rgba(248,113,113,0.2); color:#fca5a5">{{ $errors->first() }}</div>@endif

    <div class="grid grid-cols-3 gap-3">
        <div class="bento-card p-4 text-center"><div class="text-2xl font-bold gradient-ai">{{$totalBound}}</div><div class="text-xs text-ai-dim mt-1">已绑定</div></div>
        <div class="bento-card p-4 text-center"><div class="text-2xl font-bold text-ai-dim">{{$totalAll-$totalBound}}</div><div class="text-xs text-ai-dim mt-1">待绑定</div></div>
        <div class="bento-card p-4 text-center"><div class="text-2xl font-bold text-ai-primary">{{$totalAll}}</div><div class="text-xs text-ai-dim mt-1">平台总数</div></div>
    </div>

    @foreach($grouped as $catName => $platforms)
    <div class="bento-card overflow-hidden">
        @php $cb=0;foreach($platforms as $p){if(isset($accts[$p['key']])&&$accts[$p['key']]->isActive())$cb++;} @endphp
        <div class="flex items-center justify-between px-5 py-3" style="border-bottom:1px solid rgba(99,102,241,0.08)">
            <h2 class="text-sm font-semibold text-ai-primary">{{$catName}}</h2>
            <span class="text-xs" style="color:{{$cb>0?'#a5b4fc':'#6b7280'}}">{{$cb}}/{{count($platforms)}}</span>
        </div>
        <div class="px-5 py-3">
            <div class="grid grid-cols-3 md:grid-cols-6 gap-2">
                @foreach($platforms as $p)
                @php $key=$p['key'];$acc=$accts->get($key);$bound=$acc&&$acc->isActive(); @endphp
                <div class="rounded-xl border p-2.5 transition hover:border-indigo-400/25"
                     style="background:rgba(14,16,28,0.5); border-color:{{$bound?'rgba(167,139,250,0.3)':'rgba(99,102,241,0.08)'}}">
                    <div class="flex items-center justify-between gap-1 mb-0.5">
                        <span class="text-xs font-medium truncate {{!empty($p['badge'])?'text-indigo-300':'text-ai-primary'}}" title="{{$p['name']}}">{{$p['name']}}</span>
                        <span class="text-[10px]" style="color:{{$bound?'#a5b4fc':'#4b5563'}}">{{$bound?'✓':'-'}}</span>
                    </div>
                    @if(!empty($p['badge']))<div class="text-[9px] font-medium truncate mb-0.5" style="color:#a5b4fc">{{$p['badge']}}</div>@endif
                    @if($bound&&$acc->platform_account_name)<div class="text-[10px] truncate mb-1.5" style="color:#a5b4fc">账号:{{$acc->platform_account_name}}</div>@endif
                    @if(!$bound)
                    @if(!empty($p['reg_url'])&&$p['reg_url']!=='#')
                    <a href="{{$p['reg_url']}}" target="_blank" rel="noopener" class="block w-full text-center text-[10px] rounded py-0.5 mb-1 transition hover:opacity-80"
                       style="color:#fbbf24; border:1px solid rgba(251,191,36,0.2); background:rgba(251,191,36,0.05)">📝 前往注册 →</a>
                    @endif
                    <form method="POST" action="{{route('client.platforms.bind')}}">@csrf
                        <input type="hidden" name="platform_key" value="{{$key}}">
                        <input name="platform_account_name" required class="w-full rounded-lg px-1.5 py-0.5 text-[10px] text-white placeholder-gray-500 mb-1 focus:outline-none focus:ring-1 focus:ring-indigo-500/50"
                               style="background:rgba(14,16,28,0.8); border:1px solid rgba(99,102,241,0.1)" placeholder="账号名">
                        <textarea name="credential" class="w-full rounded-lg px-1.5 py-0.5 text-[9px] text-white placeholder-gray-500 mb-1 focus:outline-none focus:ring-1 focus:ring-indigo-500/50"
                                  style="background:rgba(14,16,28,0.8); border:1px solid rgba(99,102,241,0.1)" placeholder="Cookie/密码" rows="2"></textarea>
                        <button class="w-full text-[10px] text-white py-0.5 rounded-lg transition"
                                style="background:linear-gradient(135deg,#6366f1,#8b5cf6)">保存凭证</button>
                    </form>
                    @else
                    <form method="POST" action="{{route('client.platforms.unbind')}}">@csrf
                        <input type="hidden" name="platform_key" value="{{$key}}">
                        <button class="w-full text-[10px] rounded-lg py-0.5 transition"
                                style="color:#fca5a5; border:1px solid rgba(248,113,113,0.15)">解绑</button>
                    </form>
                    @endif
                </div>
                @endforeach
            </div>
        </div>
    </div>
    @endforeach
    <div class="text-center text-xs mt-4" style="color:rgba(210,200,235,0.45)">💡 绑定后运营助手自动同步。凭证加密存储，按客户隔离。天助网一次绑定覆盖30+站点。</div>
</div>
@endsection
