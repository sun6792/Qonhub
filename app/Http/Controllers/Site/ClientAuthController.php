<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\ClientUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class ClientAuthController extends Controller
{
    public function showLoginForm(): View
    {
        return view('client.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $loginId = $request->input('username');

        // 支持用户名或邮箱登录：自动检测
        $field = filter_var($loginId, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        if (Auth::guard('client')->attempt([$field => $loginId, 'password' => $request->input('password')], $request->boolean('remember'))) {
            $request->session()->regenerate();

            /** @var ClientUser $client */
            $client = Auth::guard('client')->user();
            $client->forceFill(['last_login_at' => now()])->save();

            return redirect()->intended(route('client.dashboard'));
        }

        return back()->withErrors([
            'username' => '账号或密码不正确',
        ])->onlyInput('username');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('client')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('client.login');
    }
}
