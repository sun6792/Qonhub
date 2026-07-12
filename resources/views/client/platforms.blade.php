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
      <p class="text-sm text-gray-500 mt-0.5">所有自媒体·B2B锚点凭证集中管理，加密存储。助手读取后一键分发。</p>
    </div>
    <a href="{{ route('client.dashboard') }}" class="text-sm text-indigo-600 hover:underline">← 返回看板</a>
  </div>

  @if(session('message'))
  <div class="mb-4 rounded-lg bg-green-50 border border-green-200 p-3 text-sm text-green-700">{{ session('message') }}</div>
  @endif
  @if($errors->any())
  <div class="mb-4 rounded-lg bg-red-50 border border-red-200 p-3 text-sm text-red-700">{{ $errors->first() }}</div>
  @endif

  @php
    $accts = \App\Models\ClientPlatformAccount::where('workspace_id', (int)$workspace->id)->get()->keyBy('platform_key');
    // 弹药库分发渠道（从配置提取）
    $distChannels = [];
    $templates = config('media-templates.templates', []);
    foreach($templates as $tpl) {
      foreach(($tpl['platforms'] ?? []) as $p) {
        $k = 'dist_' . ($tpl['key'] ?? '') . '_' . ($p['name'] ?? '');
        $k = preg_replace('/[^a-z0-9_]/', '_', strtolower($k));
        $distChannels[$k] = ['name' => ($p['name'] ?? $tpl['name']), 'type' => '分发渠道', 'desc' => ($tpl['style'] ?? '')];
      }
    }
    $distChannels = [];
    $templates = config('media-templates.templates', []);
    foreach($templates as $tpl) {
      foreach(($tpl['platforms'] ?? []) as $p) {
        $k = substr(md5(($tpl['key']??'').($p['name']??'')), 0, 12);
        $distChannels[$k] = ['name' => $p['name'] ?? $tpl['name'], 'type' => '分发渠道'];
      }
    }
    $allPlatforms = [
      '📱 自媒体' => \App\Models\ClientPlatformAccount::supportedPlatforms(),
      '📰 内容分发渠道' => $distChannels,
      '🏢 B2B锚点' => \App\Services\GeoFlow\EnterpriseAnchorService::anchorPlatforms(),
    ];
    $totalBound = $accts->where('status','active')->count();
    $totalAll = 0;
    foreach($allPlatforms as $list) $totalAll += count($list);
  @endphp

  {{-- 概览 --}}
  <div class="mb-4 grid grid-cols-3 gap-3">
    <div class="bg-white rounded-xl shadow-sm p-4 text-center">
      <div class="text-2xl font-bold text-indigo-600">{{ $totalBound }}</div>
      <div class="text-xs text-gray-500">已绑定</div>
    </div>
    <div class="bg-white rounded-xl shadow-sm p-4 text-center">
      <div class="text-2xl font-bold text-gray-400">{{ $totalAll - $totalBound }}</div>
      <div class="text-xs text-gray-500">待绑定</div>
    </div>
    <div class="bg-white rounded-xl shadow-sm p-4 text-center">
      <div class="text-2xl font-bold text-gray-700">{{ $totalAll }}</div>
      <div class="text-xs text-gray-500">平台总数</div>
    </div>
  </div>

  {{-- 分类平台列表 --}}
  @foreach($allPlatforms as $catName => $platforms)
  @php $isDist = ($catName === '📰 内容分发渠道'); @endphp
  <div class="mb-4 bg-white rounded-xl shadow-sm overflow-hidden">
    <div class="border-b border-gray-100 px-5 py-3 flex items-center justify-between">
      <h2 class="text-sm font-semibold text-gray-800">{{ $catName }}</h2>
      @if($isDist)
      <span class="text-xs text-blue-600">{{ count($platforms) }} 个API渠道 · 自动可用</span>
      @else
      @php $catBound = 0; $catTotal = count($platforms);
        foreach($platforms as $k => $v) { if(isset($accts[$k]) && $accts[$k]->isActive()) $catBound++; }
      @endphp
      <span class="text-xs {{ $catBound > 0 ? 'text-green-600' : 'text-gray-400' }}">{{ $catBound }}/{{ $catTotal }}</span>
      @endif
    </div>
    <div class="px-5 py-3">
      <div class="grid grid-cols-4 md:grid-cols-6 gap-2">
        @foreach($platforms as $key => $info)
        @php $acc = $accts->get($key); $bound = $acc && $acc->isActive(); @endphp
        <div class="rounded-lg border p-2.5 {{ $isDist ? 'border-blue-200 bg-blue-50/30' : ($bound ? 'border-green-300 bg-green-50' : 'border-gray-200 bg-gray-50 hover:border-indigo-200') }}">
          <div class="flex items-center justify-between gap-1 mb-1">
            <span class="text-xs font-medium text-gray-800 truncate" title="{{ $info['name'] }}">{{ $info['name'] }}</span>
            <span class="text-[10px] {{ $bound ? 'text-green-600' : 'text-gray-300' }}">{{ $bound ? '✓' : '-' }}</span>
          </div>
          @if($bound && $acc->platform_account_name)
          <div class="text-[10px] text-green-600 truncate mb-1.5">{{ $acc->platform_account_name }}</div>
          @endif
          {{-- 绑定/解绑表单 --}}
          @if($isDist)
          <div class="text-[10px] text-blue-600 text-center">API · 自动可用</div>
          @elseif(!$bound)
          <form method="POST" action="{{ route('client.platforms.bind') }}">
            @csrf
            <input type="hidden" name="platform_key" value="{{ $key }}">
            <input name="platform_account_name" class="w-full rounded border border-gray-300 px-1.5 py-0.5 text-[10px] mb-1" placeholder="账号名/ID">
            <button class="w-full text-[10px] bg-indigo-600 text-white py-0.5 rounded hover:bg-indigo-700">绑定</button>
          </form>
          @else
          <form method="POST" action="{{ route('client.platforms.unbind') }}">
            @csrf
            <input type="hidden" name="platform_key" value="{{ $key }}">
            <button class="w-full text-[10px] text-red-400 hover:text-red-600 border border-red-200 rounded py-0.5">解绑</button>
          </form>
          @endif
        </div>
        @endforeach
      </div>
    </div>
  </div>
  @endforeach

  <div class="text-center text-xs text-gray-400 mt-4">
    💡 绑定后，运营助手自动同步。Cookie加密存储，永久复用免重登。
  </div>
</div>
</body>
</html>
