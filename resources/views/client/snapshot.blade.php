<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>检测快照 #{{ $check->id }} - {{ $platformInfo['name'] ?? $check->ai_platform }}</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{background:#0d0e1a;color:#d0d0d0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;padding:20px}
        .container{max-width:780px;margin:0 auto}
        .card{background:rgba(22,24,40,0.92);border:1px solid rgba(165,180,252,0.08);border-radius:16px;padding:20px 24px;margin-bottom:14px}
        .badge{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:8px;font-size:12px;font-weight:600}
        .btn{display:inline-flex;align-items:center;gap:4px;padding:8px 16px;border-radius:10px;font-size:13px;text-decoration:none;transition:all .2s}
        .btn-outline{border:1px solid rgba(165,180,252,0.2);color:#9ca3af;background:transparent}
        .btn-outline:hover{border-color:rgba(165,180,252,0.5);color:#c4b5fd}
        .btn-primary{background:rgba(99,102,241,0.15);border:1px solid rgba(129,140,248,0.3);color:#a5b4fc}
        .btn-primary:hover{background:rgba(99,102,241,0.25)}
        .ref-link{display:block;padding:10px 14px;background:rgba(255,255,255,0.02);border:1px solid rgba(165,180,252,0.06);border-radius:8px;margin-bottom:6px;color:#c4b5fd;text-decoration:none;font-size:13px;transition:all .2s}
        .ref-link:hover{background:rgba(99,102,241,0.08);border-color:rgba(129,140,248,0.2)}
        .divider{height:1px;background:rgba(165,180,252,0.06);margin:14px 0}
    </style>
</head>
<body>
<div class="container">

    {{-- 头部 — 对标摘星 --}}
    <div style="margin-bottom:16px">
        <div style="font-size:22px;font-weight:700;margin-bottom:4px">
            @if($platformInfo)
            <span style="font-size:28px">{{ $platformInfo['icon'] }}</span>
            <span style="color:{{ $platformInfo['color'] ?? '#a5b4fc' }}">{{ $platformInfo['name'] }}</span>
            @else
            {{ $check->ai_platform }}
            @endif
        </div>
        <div style="font-size:15px;color:#e0e0e0;font-weight:500">{{ $check->query_keyword }}</div>
        <div style="font-size:12px;color:#6b7280;margin-top:4px">
            {{ $check->checked_at?->format('Y-m-d H:i:s') ?? '-' }}
            · 内容由 AI 生成，不能完全保障真实
        </div>
    </div>

    {{-- AI回复原文 — 核心内容 --}}
    <div class="card">
        <div style="color:#e0e0e0;font-size:14px;line-height:1.9;white-space:pre-wrap">{!! preg_replace('/(https?:\/\/[^\s\n\r]{10,})/', '<a href="$1" target="_blank" style="color:#a5b4fc;text-decoration:underline">$1</a>', e($check->response_snippet) ?: '暂未保存 AI 回复内容') !!}</div>
    </div>

    {{-- 引用文章 --}}
    @php $citedIds = $check->cited_article_ids ?? []; @endphp
    @if(!empty($citedIds))
    <div class="card" style="border-color:rgba(165,180,252,0.2)">
        <div style="font-size:13px;font-weight:600;margin-bottom:10px;color:#a5b4fc">📰 被引用的已发布文章</div>
        @foreach(\App\Models\Article::whereIn('id', $citedIds)->get() as $art)
        <a href="https://www.doubao.com/search?q={{ urlencode($art->title) }}" target="_blank" class="ref-link">
            📄 {{ $art->title }}
            <span style="color:#6b7280;font-size:11px;margin-left:8px">{{ $art->keywords }} · {{ $art->published_at?->format('Y-m-d') }}</span>
        </a>
        @endforeach
    </div>
    @endif

    {{-- 参考来源 — 对标摘星"参考XX篇资料" --}}
    @php $meta = is_array($check->raw_response_meta) ? $check->raw_response_meta : []; @endphp
    <div class="card" style="border-color:rgba(165,180,252,0.15)">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
            <div>
                <span style="font-size:13px;color:#9ca3af">参考</span>
                <span style="font-size:20px;font-weight:700;color:#a5b4fc;margin:0 4px">{{ $meta['refs'] ?? '?' }}</span>
                <span style="font-size:13px;color:#9ca3af">篇资料</span>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                @if(!empty($meta['thread_url']))
                <a href="{{ $meta['thread_url'] }}" target="_blank" class="btn btn-primary">查看完整对话 →</a>
                @endif
                @if($platformInfo)
                <a href="{{ $platformInfo['url'] }}" target="_blank" class="btn btn-outline">在平台验证 ↗</a>
                @endif
            </div>
        </div>
        @if(!empty($meta['thread_url']))
        <div class="divider"></div>
        <div style="font-size:11px;color:#6b7280;word-break:break-all">来源: {{ $meta['thread_url'] }}</div>
        @endif
        <div style="font-size:11px;color:#4b5563;margin-top:4px">💡 参考资料的原文链接需在豆包登录后，点击对话页内的「参考XX篇资料」面板查看</div>
    </div>

    {{-- 底部 --}}
    <div style="text-align:center;padding:16px 0">
        <div style="font-size:11px;color:#4b5563">{{ $workspace->name }} · Powered by Qonhub AI GEO 系统</div>
    </div>
</div>
</body>
</html>
