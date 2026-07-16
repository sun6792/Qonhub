<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $workspace->name ?? '客户看板' }} - Qonhub AI</title>
    <script src="{{ asset('js/tailwindcss.js') }}"></script>
    <script src="{{ asset('js/chart.min.js') }}"></script>
    <script src="{{ asset('js/three.min.js') }}"></script>
    <link rel="stylesheet" href="{{ asset('css/font-awesome.min.css') }}">
    <script>
      // Tailwind config: AI dark theme — 与 Galaxy 星空色系统一
      tailwind.config = {
        theme: {
          extend: {
            colors: {
              'ai-bg': '#06080f',
              'ai-surface': 'rgba(12, 14, 24, 0.88)',
              'ai-card': 'rgba(16, 18, 30, 0.82)',
              'ai-border': 'rgba(99,102,241,0.08)',
              'ai-glow': 'rgba(99, 102, 241, 0.18)',
            }
          }
        }
      }
    </script>
    <style>
      /* ── v2.7.0 AI 暗色主题 Design Tokens ── */
      :root {
        --ai-bg: #06080f;
        --ai-surface: rgba(21, 23, 38, 0.88);
        --ai-card: rgba(16, 18, 30, 0.82);
        --ai-border: rgba(165,180,252,0.1);
        --ai-border-hover: rgba(196,181,253,0.25);
        --ai-glow: rgba(99,102,241,0.08);
        --ai-text-primary: rgba(255,255,255,0.85);
        --ai-text-secondary: rgba(255,255,255,0.55);
        --ai-text-dim: rgba(200,198,225,0.5);
        --ai-nav-bg: rgba(16,18,30,0.92);
        --ai-accent: #a5b4fc;
        --ai-accent-dim: rgba(129,140,248,0.2);
        --ai-gradient: linear-gradient(135deg,#6366f1,#8b5cf6);
      }

      /* Bento card glow system */
      .bento-card {
        --glow-x: 50%; --glow-y: 50%; --glow-intensity: 0;
        position: relative; border-radius: 20px;
        border: 1px solid rgba(165,180,252,0.1);
        background: rgba(21, 23, 38, 0.88);
        backdrop-filter: blur(14px);
        -webkit-backdrop-filter: blur(14px);
        transition: transform 0.4s ease, box-shadow 0.4s ease, border-color 0.4s ease;
        overflow: hidden;
      }
      .bento-card::after {
        content: ''; position: absolute; inset: 0; padding: 1px;
        border-radius: inherit;
        background: radial-gradient(300px circle at var(--glow-x) var(--glow-y),
          rgba(165,180,252, calc(var(--glow-intensity) * 0.5)) 0%,
          rgba(139,92,246, calc(var(--glow-intensity) * 0.25)) 40%,
          transparent 70%);
        -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
        -webkit-mask-composite: xor; mask-composite: exclude;
        pointer-events: none; z-index: 1;
      }
      .bento-card:hover {
        transform: translateY(-1px);
        border-color: rgba(196,181,253,0.25);
        box-shadow: 0 6px 28px rgba(99,102,241,0.08), 0 0 50px rgba(139,92,246,0.04);
      }
      /* Navigation — 柔和毛玻璃 */
      .nav-ai {
        background: rgba(16, 18, 30, 0.92);
        backdrop-filter: blur(24px);
        -webkit-backdrop-filter: blur(24px);
        border-bottom: 1px solid rgba(165,180,252,0.15);
        box-shadow: 0 4px 24px rgba(0,0,0,0.3);
      }
      .nav-link {
        transition: all 0.3s ease;
        border-radius: 14px;
        font-weight: 500;
      }
      .nav-link.active {
        background: rgba(129,140,248,0.2);
        color: #eee9ff;
        border: 1px solid rgba(165,180,252,0.3);
        box-shadow: 0 0 12px rgba(129,140,248,0.1);
      }
      .nav-link:not(.active) {
        background: rgba(255,255,255,0.04);
        color: rgba(255,255,255,0.55);
        border: 1px solid transparent;
      }
      .nav-link:not(.active):hover {
        background: rgba(129,140,248,0.1);
        color: rgba(255,255,255,0.85);
        border-color: rgba(165,180,252,0.15);
      }
      /* Text — 明亮柔和 */
      .text-ai-primary { color: rgba(240,240,250,0.9); }
      .text-ai-secondary { color: rgba(215,212,240,0.55); }
      .text-ai-dim { color: rgba(200,198,225,0.5); }
      /* Gradient accents */
      .gradient-ai {
        background: linear-gradient(135deg, #a5b4fc, #c4b5fd, #ddd6fe);
        -webkit-background-clip: text; -webkit-text-fill-color: transparent;
        background-clip: text;
      }
      .page-content { position: relative; z-index: 1; }
      .footer-ai { border-top: 1px solid rgba(165,180,252,0.06); }
      /* KPI pulse */
      @keyframes kpiPulse {
        0%,100% { box-shadow: 0 0 14px rgba(129,140,248,0.04); }
        50% { box-shadow: 0 0 26px rgba(165,180,252,0.08); }
      }
      .kpi-card { animation: kpiPulse 5.5s ease-in-out infinite; }
      /* Scrollbar */
      ::-webkit-scrollbar { width: 5px; }
      ::-webkit-scrollbar-track { background: transparent; }
      ::-webkit-scrollbar-thumb { background: rgba(165,180,252,0.18); border-radius: 3px; }
      ::-webkit-scrollbar-thumb:hover { background: rgba(165,180,252,0.3); }
    </style>
    @stack('head')
</head>
<body class="min-h-screen text-white" style="background:#0d0e1a;">

    {{-- 背景层1: Grainient 流体渐变 --}}
    <div id="grainient-container"></div>
    {{-- 背景层2: FloatingLines 光线叠加 (screen blend) --}}
    <div id="floating-lines-container"></div>

    {{-- Page Content --}}
    <div class="page-content">

        {{-- Navigation --}}
        <nav class="nav-ai sticky top-0 z-50">
            <div class="max-w-7xl mx-auto px-4 py-3 flex justify-between items-center">
                <div class="flex items-center space-x-3">
                    @if ($workspace->logo_url)
                    <img src="{{ $workspace->logo_url }}" class="h-8 w-8 rounded-lg ring-1 ring-white/10" alt="logo">
                    @else
                    <div class="h-8 w-8 rounded-lg flex items-center justify-center text-sm font-bold"
                         style="background:linear-gradient(135deg,#6366f1,#8b5cf6);">Q</div>
                    @endif
                    <div>
                        <span class="font-bold text-base" style="color:#f0ecff">{{ $workspace->name }}</span>
                        <span class="text-xs ml-2" style="color:rgba(210,200,240,0.6)">AI 内容运营中心</span>
                    </div>
                </div>
                <div class="text-xs" style="color:rgba(200,195,225,0.5)">
                    Powered by <span class="gradient-ai font-semibold">Qonhub AI</span>
                </div>
            </div>
        </nav>

        {{-- 客户端导航 — 四步核心工作流 --}}
        <div style="background:rgba(13,14,26,0.85); backdrop-filter:blur(12px); border-bottom:1px solid rgba(165,180,252,0.08);">
        <div class="max-w-7xl mx-auto px-4 pt-4 pb-3">
            <div class="flex items-center space-x-1 flex-wrap gap-y-2">
                <a href="{{ route('client.dashboard') }}"
                   class="nav-link px-3 py-2 rounded-xl text-xs font-medium {{ request()->routeIs('client.dashboard') ? 'active' : '' }}">
                    🏠 总览
                </a>
                <a href="{{ route('client.content-publish.certify') }}"
                   class="nav-link px-3 py-2 rounded-xl text-xs font-medium {{ request()->routeIs('client.content-publish.certify*') ? 'active' : '' }}">
                    🏢 入驻
                </a>
                <a href="{{ route('client.content-publish.index') }}"
                   class="nav-link px-3 py-2 rounded-xl text-xs font-medium {{ request()->routeIs('client.content-publish.*') ? 'active' : '' }}">
                    🚀 发布
                </a>
                <a href="{{ route('client.ai-visibility') }}"
                   class="nav-link px-3 py-2 rounded-xl text-xs font-medium {{ request()->routeIs('client.ai-visibility*') ? 'active' : '' }}">
                    🔍 检测
                </a>
                <span class="flex-1"></span>
                <a href="{{ route('client.articles') }}"
                   class="nav-link px-3 py-2 rounded-xl text-xs font-medium {{ request()->routeIs('client.articles') ? 'active' : '' }}">
                    📝 文章
                </a>
                <form method="POST" action="{{ route('client.logout') }}" class="inline">
                    @csrf
                    <button class="nav-link px-3 py-2 rounded-xl text-xs font-medium text-red-400/70 hover:text-red-300">
                        退出
                    </button>
                </form>
            </div>
        </div>
        </div>

        {{-- Main Content --}}
        <div class="max-w-7xl mx-auto px-4 pb-6">
            @yield('content')
        </div>

        {{-- Footer --}}
        <div class="footer-ai text-center text-ai-dim text-xs py-6">
            &copy; {{ date('Y') }} Qonhub AI · AI 搜索营销数据实时更新
        </div>
    </div>

    {{-- JS: Galaxy BG + ClickSpark + BentoGlow --}}
    <script src="{{ asset('js/grainient-bg.js') }}"></script>
    <script src="{{ asset('js/floating-lines.js') }}"></script>
    <script src="{{ asset('js/qonhub-fx.js') }}"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
      // 层1: Grainient 流体渐变
      new GrainientBackground(document.getElementById('grainient-container'), {
        color1: '#ddd6fe', color2: '#818cf8', color3: '#1e1b4b',
        timeSpeed: 0.12, warpStrength: 0.6, warpFrequency: 3.0,
        warpSpeed: 1.0, warpAmplitude: 80, blendSoftness: 0.14,
        rotationAmount: 250, noiseScale: 1.8, grainAmount: 0.03,
        contrast: 1.15, saturation: 1.0, zoom: 1.08
      });

      // 层2: FloatingLines 光线叠加 (screen blend, 半透明)
      new FloatingLines(document.getElementById('floating-lines-container'), {
        lineCount: [6, 10, 14],
        lineDistance: [0.07, 0.05, 0.035],
        enabledWaves: ['top', 'middle', 'bottom'],
        animationSpeed: 0.5,
        interactive: true,
        bendRadius: 7.0,
        bendStrength: -0.3,
        mouseDamping: 0.06,
        parallax: true,
        parallaxStrength: 0.12,
        linesGradient: ['#818cf8', '#a78bfa', '#c4b5fd'],
        mixBlendMode: 'screen'
      });

      // Click sparkle
      new QonhubFX.ClickSpark({
        sparkColor: '#c4b5fd', sparkSize: 6, sparkRadius: 14,
        sparkCount: 8, duration: 450
      });

      // Bento card glow
      new QonhubFX.BentoGlow('.bento-card', {
        glowColor: '165, 180, 252', spotlightRadius: 300
      });

      // Magnet: 单一监听器，卡片鼠标磁吸
      const magnetEls = document.querySelectorAll('.bento-card, .nav-link');
      document.addEventListener('mousemove', (e) => {
        magnetEls.forEach(el => {
          const r = el.getBoundingClientRect();
          const cx = r.left + r.width/2, cy = r.top + r.height/2;
          const dx = e.clientX - cx, dy = e.clientY - cy;
          const dist = Math.hypot(dx, dy);
          const range = Math.max(r.width, r.height) * 0.7;
          if (dist < range) {
            el.style.transform = `translate3d(${dx/20}px,${dy/20}px,0)`;
            el.style.transition = 'transform 0.25s ease-out';
          } else {
            el.style.transform = 'translate3d(0,0,0)';
            el.style.transition = 'transform 0.5s ease-in-out';
          }
        });
      });
    });
    </script>
    @stack('scripts')
</body>
</html>


{{-- v2.6.0 快照凭证弹窗 --}}
<div id="snapshot-modal" style="display:none; position:fixed; inset:0; z-index:9999; background:rgba(0,0,0,0.7); backdrop-filter:blur(4px);" onclick="document.getElementById('snapshot-modal').style.display='none'">
    <div style="position:relative; max-width:600px; margin:8% auto; background:rgba(22,24,40,0.96); border:1px solid rgba(165,180,252,0.2); border-radius:16px; padding:24px;" onclick="event.stopPropagation()">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-white" id="snap-title"></h3>
            <button onclick="document.getElementById('snapshot-modal').style.display='none'" class="text-ai-dim hover:text-white text-xl">&times;</button>
        </div>
        <div id="snap-body" class="text-sm space-y-3"></div>
        <div class="mt-4 pt-3 border-t flex justify-end gap-2" style="border-color:rgba(165,180,252,0.1)">
            <a id="snap-verify-link" href="#" target="_blank" class="text-xs text-indigo-400 hover:underline">在平台验证</a>
            <button onclick="document.getElementById('snapshot-modal').style.display='none'" class="text-xs px-3 py-1 rounded-lg border text-ai-dim hover:text-white" style="border-color:rgba(165,180,252,0.2)">关闭</button>
        </div>
    </div>
</div>
<script>
function showSnapshot(platform, icon, color, query, time, mentioned, snippet, verifyUrl) {
    document.getElementById('snap-title').innerHTML = icon + ' ' + platform + ' - 检测快照';
    document.getElementById('snap-body').innerHTML =
        '<div style="color:#9ca3af;font-size:12px">搜索词</div>' +
        '<div style="color:#e0e0e0;background:rgba(255,255,255,0.04);padding:8px 12px;border-radius:8px">' + (query||'') + '</div>' +
        '<div class="flex gap-4"><div><div style="color:#9ca3af;font-size:12px">时间</div><div style="color:#e0e0e0">' + (time||'') + '</div></div><div><div style="color:#9ca3af;font-size:12px">结果</div><div style="color:' + (mentioned ? '#86efac' : '#9ca3af') + '">' + (mentioned ? '已收录' : '未提及') + '</div></div></div>' +
        '<div style="color:#9ca3af;font-size:12px">AI回复快照</div>' +
        '<div style="color:#d0d0d0;background:rgba(255,255,255,0.04);padding:10px 12px;border-radius:8px;max-height:200px;overflow-y:auto;font-size:13px;line-height:1.6">' + (snippet || '暂无') + '</div>';
    var vlink = document.getElementById('snap-verify-link');
    if(verifyUrl) { vlink.href = verifyUrl; vlink.style.display = ''; }
    else { vlink.style.display = 'none'; }
    document.getElementById('snapshot-modal').style.display = 'block';
}
</script>
</body>
</html>
