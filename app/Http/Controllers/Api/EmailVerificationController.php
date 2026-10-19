<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

class EmailVerificationController extends Controller
{
    /**
     * Target of the link in the verification email. Laravel's VerifyEmail
     * notification builds that link with URL::temporarySignedRoute(), so this
     * route's name must stay exactly "verification.verify" or the notification
     * cannot resolve it.
     *
     * Failures redirect instead of returning JSON: someone clicking a link in
     * their inbox arrives in a browser, where a raw 403 payload is a dead end.
     */
    public function verify(Request $request, string $id, string $hash)
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        // Covers tampering and expiry in one check.
        if (!$request->hasValidSignature()) {
            return redirect($frontend.'/login?verified=expired');
        }

        $user = User::find($id);

        if (!$user || !hash_equals($hash, sha1($user->getEmailForVerification()))) {
            return redirect($frontend.'/login?verified=invalid');
        }

        if (!$user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        return redirect($frontend.'/login?verified=1');
    }

    /**
     * Resend a verification link. The reply is identical whether the address is
     * unknown, already verified, or genuinely pending -- an unauthenticated caller
     * should not be able to probe which addresses are registered here.
     */
    public function resend(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if ($user && !$user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return response()->json([
            'message' => 'If that address still needs verifying, a new link is on its way.',
        ]);
    }
}
