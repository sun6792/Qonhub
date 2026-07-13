<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('admin.login.title') }} — {{ $adminSiteName }}</title>
    <script src="{{ asset('js/tailwindcss.play-cdn.js') }}"></script>
    <script src="{{ asset('js/lucide.min.js') }}"></script>
    <style>
        body { background: #080812; min-height: 100vh; }
        .login-form {
            background: rgba(22, 24, 42, 0.94);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border: 1px solid rgba(165,180,252,0.25);
            box-shadow: 0 0 40px rgba(99,102,241,0.12), 0 20px 60px rgba(0,0,0,0.4);
        }
        .login-badge { background: linear-gradient(135deg, #6366f1, #8b5cf6); }
        .initial-admin-hint { background: rgba(99,102,241,0.1); border-color: rgba(165,180,252,0.2); }
        .input-field { background: rgba(30,32,50,0.9); border: 1px solid rgba(165,180,252,0.25); color: #e8e4ff; }
        .input-field:focus { border-color: rgba(165,180,252,0.6); box-shadow: 0 0 0 3px rgba(99,102,241,0.15); outline: none; }
        .input-field::placeholder { color: rgba(200,195,225,0.35); }
    </style>
</head>
<body class="overflow-hidden">
<div id="lightfall-bg"></div>
<div class="fixed right-4 top-4 z-50">
    <select onchange="window.location.href=this.value" class="rounded-md border border-gray-300 bg-white px-2 py-1 text-xs text-gray-600 shadow-sm">
        @foreach (\App\Support\AdminWeb::supportedLocales() as $localeCode => $localeLabel)
            <option value="{{ route('admin.locale.switch', ['locale' => $localeCode]) }}" @selected(app()->getLocale() === $localeCode)>
                {{ $localeLabel }}
            </option>
        @endforeach
    </select>
</div>
<div class="fixed top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-full max-w-md px-4">
    <div class="rounded-2xl p-8 login-form">
        <div class="text-center mb-8">
            <div class="login-badge w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4">
                <i data-lucide="shield-check" class="w-8 h-8 text-white"></i>
            </div>
            <h1 class="text-2xl font-bold mb-2" style="color:#e8e4ff">{{ __('admin.login.title') }}</h1>
            <p style="color:rgba(210,200,235,0.55)">{{ __('admin.login.subtitle', ['site_name' => $adminSiteName]) }}</p>
        </div>
        @if (session('message'))
            <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg text-green-700 text-sm">
                {{ session('message') }}
            </div>
        @endif
        @if (isset($errors) && $errors->any())
            <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg text-red-700 text-sm">
                {{ $errors->first() }}
            </div>
        @endif
        @if (!empty($initialAdminHint['enabled']))
            <div id="initial-admin-hint" class="initial-admin-hint mb-6 hidden rounded-xl border border-blue-200 p-4 text-sm text-gray-700 shadow-sm" data-storage-key="{{ $initialAdminHint['storage_key'] ?? 'geoflow.initial-admin-hint' }}">
                <div class="flex items-start gap-3">
                    <div class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-blue-600 text-white">
                        <i data-lucide="key-round" class="h-5 w-5"></i>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="font-semibold text-gray-900">{{ __('admin.login.first_login_hint_title') }}</p>
                                <p class="mt-1 leading-6 text-gray-600">{{ __('admin.login.first_login_hint_intro') }}</p>
                            </div>
                            <button type="button" data-dismiss-initial-admin-hint class="rounded-md p-1 text-gray-400 hover:bg-white hover:text-gray-600" aria-label="{{ __('admin.login.first_login_dismiss') }}">
                                <i data-lucide="x" class="h-4 w-4"></i>
                            </button>
                        </div>
                        <div class="mt-3 grid gap-2 rounded-lg border border-blue-100 bg-white/80 p-3">
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-gray-500">{{ __('admin.login.first_login_username') }}</span>
                                <code class="rounded bg-gray-100 px-2 py-1 font-mono text-xs text-gray-900">{{ $initialAdminHint['username'] ?? '' }}</code>
                            </div>
                            @if (($initialAdminHint['mode'] ?? '') === 'known')
                                <div class="flex items-center justify-between gap-3">
                                    <span class="text-gray-500">{{ __('admin.login.first_login_password') }}</span>
                                    <code class="rounded bg-gray-100 px-2 py-1 font-mono text-xs text-gray-900">{{ $initialAdminHint['password'] ?? '' }}</code>
                                </div>
                            @else
                                <div class="rounded-lg bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-800">
                                    {{ __('admin.login.first_login_password_from_log') }}
                                </div>
                            @endif
                        </div>
                        <p class="mt-3 text-xs leading-5 text-red-600">{{ __('admin.login.first_login_security') }}</p>
                    </div>
                </div>
            </div>
        @endif
        <form method="POST" action="{{ route('admin.login.attempt') }}" class="space-y-6">
            @csrf
            <div>
                <label for="username" class="block text-sm font-medium mb-2" style="color:rgba(220,215,245,0.7)">{{ __('admin.login.username') }}</label>
                <input type="text" id="username" name="username" required value="{{ old('username') }}"
                       class="input-field block w-full px-3 py-3 rounded-lg text-sm transition"
                       placeholder="{{ __('admin.login.username_placeholder') }}" autocomplete="username">
            </div>
            <div>
                <label for="password" class="block text-sm font-medium mb-2" style="color:rgba(220,215,245,0.7)">{{ __('admin.login.password') }}</label>
                <input type="password" id="password" name="password" required
                       class="input-field block w-full px-3 py-3 rounded-lg text-sm transition"
                       placeholder="{{ __('admin.login.password_placeholder') }}" autocomplete="current-password">
            </div>
            <input type="hidden" name="remember" value="0">
            <label class="flex items-center justify-between rounded-lg px-3 py-3 text-sm transition"
                   style="background:rgba(14,16,28,0.5); border:1px solid rgba(165,180,252,0.1); color:rgba(210,200,235,0.55)">
                <span class="flex items-center gap-2">
                    <input type="checkbox" name="remember" value="1" checked class="h-4 w-4 rounded border-indigo-400/30 text-indigo-500 focus:ring-indigo-500" style="accent-color:#6366f1">
                    <span>{{ __('admin.login.remember_30_days') }}</span>
                </span>
                <span style="color:rgba(200,195,225,0.45); font-size:.75rem">{{ __('admin.login.remember_30_days_hint') }}</span>
            </label>
            <button type="submit" class="w-full text-white font-medium py-3 px-4 rounded-lg transition" style="background:linear-gradient(135deg,#6366f1,#8b5cf6)">
                {{ __('admin.login.submit') }}
            </button>
        </form>
    </div>
    <div class="text-center mt-6">
        <a href="{{ url('/') }}" style="color:rgba(200,195,225,0.45); font-size:.875rem" class="hover:text-white transition">{{ __('admin.login.back_home') }}</a>
    </div>
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
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const initialHint = document.getElementById('initial-admin-hint');
        if (initialHint) {
            const storageKey = initialHint.getAttribute('data-storage-key') || 'geoflow.initial-admin-hint';
            let shouldShow = true;

            try {
                shouldShow = window.localStorage.getItem(storageKey) !== 'seen';
                if (shouldShow) {
                    window.localStorage.setItem(storageKey, 'seen');
                }
            } catch (error) {
                shouldShow = true;
            }

            if (shouldShow) {
                initialHint.classList.remove('hidden');
            }

            initialHint.querySelector('[data-dismiss-initial-admin-hint]')?.addEventListener('click', function () {
                initialHint.classList.add('hidden');
                try {
                    window.localStorage.setItem(storageKey, 'seen');
                } catch (error) {}
            });
        }

        if (typeof lucide !== 'undefined') lucide.createIcons();
    });
</script>
</body>
</html>
