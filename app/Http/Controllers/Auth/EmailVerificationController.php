<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmailVerificationController extends Controller
{
    public function notice(Request $request): Response|RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended(route('dashboard', absolute: false));
        }

        return Inertia::render('Auth/VerifyEmail', [
            'status' => session('status'),
            'email' => $request->user()->email,
        ]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless((string) $request->route('id') === (string) $user->getKey(), 403);
        abort_unless(hash_equals(sha1($user->getEmailForVerification()), (string) $request->route('hash')), 403);

        if ($user->hasVerifiedEmail()) {
            return redirect()->intended(route('dashboard', absolute: false));
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return redirect()->intended(route('dashboard', absolute: false))
            ->with('status', 'email-verified')
            ->with('success', 'تم تأكيد البريد الإلكتروني بنجاح.');
    }

    public function resend(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended(route('dashboard', absolute: false));
        }

        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', 'verification-link-sent');
    }
}
