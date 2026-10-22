<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetLinkController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword', [
            'status' => session('status'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:180'],
        ]);

        // Do not disclose whether the submitted address belongs to an account.
        Password::sendResetLink($validated);

        return back()->with('status', 'لو كان البريد الإلكتروني مسجلًا لدينا، فستصلك رسالة تحتوي على رابط آمن لإعادة تعيين كلمة المرور.');
    }
}
