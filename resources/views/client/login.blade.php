<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>客户登录 - Qonhub AI</title>
    <script src="{{ asset('js/tailwindcss.js') }}"></script>
    <style>
      body { background: #080812; }
      .login-card {
        background: rgba(22, 24, 42, 0.94);
        backdrop-filter: blur(24px);
        -webkit-backdrop-filter: blur(24px);
        border: 1px solid rgba(165,180,252,0.25);
        box-shadow: 0 0 40px rgba(99,102,241,0.12), 0 20px 60px rgba(0,0,0,0.4);
      }
      .input-field {
        background: rgba(30, 32, 50, 0.9);
        border: 1px solid rgba(165,180,252,0.25);
        color: #e8e4ff;
      }
      .input-field:focus {
        border-color: rgba(165,180,252,0.6);
        box-shadow: 0 0 0 3px rgba(99,102,241,0.15);
        outline: none;
      }
      .input-field::placeholder { color: rgba(200,195,225,0.35); }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center">
    <div id="lightfall-bg"></div>
    <div class="w-full max-w-md px-4" style="position:relative;z-index:1">
        <div class="text-center mb-8">
            <div style="background:linear-gradient(135deg,#a5b4fc,#c4b5fd);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;font-weight:800;font-size:2rem;">Qonhub AI</div>
            <p style="color:rgba(210,200,235,0.5);font-size:.875rem;">AI 搜索营销 · 客户运营看板</p>
        </div>

        <div class="login-card rounded-2xl p-8">
            @if (isset($errors) && $errors->any())
            <div class="rounded-lg p-3 mb-4 text-sm" style="background:rgba(248,113,113,0.15);border:1px solid rgba(248,113,113,0.3);color:#fca5a5">
                {{ $errors->first() }}
            </div>
            @endif

            <form method="POST" action="{{ route('client.login.attempt') }}">
                @csrf
                <div class="mb-4">
                    <label class="block text-sm font-medium mb-1.5" style="color:rgba(220,215,245,0.7)">账号</label>
                    <input type="text" name="username" value="{{ old('username') }}" required
                           class="input-field w-full rounded-xl px-4 py-3 text-sm transition"
                           placeholder="输入账号">
                </div>
                <div class="mb-6">
                    <label class="block text-sm font-medium mb-1.5" style="color:rgba(220,215,245,0.7)">密码</label>
                    <input type="password" name="password" required
                           class="input-field w-full rounded-xl px-4 py-3 text-sm transition"
                           placeholder="输入密码">
                </div>
                <button type="submit"
                        class="w-full text-white rounded-xl py-3 font-semibold text-sm transition hover:opacity-90"
                        style="background:linear-gradient(135deg,#6366f1,#8b5cf6);box-shadow:0 4px 20px rgba(99,102,241,0.3)">
                    登录
                </button>
            </form>
        </div>

        <p class="text-center text-xs mt-6" style="color:rgba(210,200,235,0.35)">
            首次使用？请联系您的运营团队获取账号
        </p>
    </div>
    <script src="{{ asset('js/lightfall-bg.js') }}"></script>
    <script>
    new LightfallBackground(document.getElementById('lightfall-bg'),{
      colors:['#c4b5fd','#818cf8','#6366f1'],backgroundColor:'#080812',
      speed:.35,streakCount:2,streakWidth:.6,streakLength:1,glow:.8,
      density:.4,twinkle:.6,zoom:4,backgroundGlow:.2,opacity:.5,
      mouseInteraction:true,mouseStrength:.25,mouseRadius:1.5,mouseDampening:.15
    });
    </script>
</body>
</html>
