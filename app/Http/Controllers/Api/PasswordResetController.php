<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class PasswordResetController extends Controller
{
    /**
     * Step one of a password reset: mail a single-use link. The reply is
     * identical whether or not the address is on file -- anything else turns
     * this endpoint into a way to test which addresses have accounts here.
     */
    public function sendResetLink(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        // Demo accounts keep the password the seeder gave them, and the demo's
        // staff sit on real-domain addresses that someone else could own -- a
        // reset link there would hand them a staff login. Same reply either way.
        $isDemoAccount = User::where('email', $validated['email'])->where('is_demo', true)->exists();

        if (!$isDemoAccount) {
            Password::sendResetLink(['email' => $validated['email']]);
        }

        return response()->json([
            'message' => 'If that address has an account, a reset link is on its way.',
        ]);
    }

    /**
     * Step two: spend the token and set the new password.
     *
     * Every existing API token is destroyed in the same transaction. People
     * reset passwords precisely because they think somebody else got in, and
     * leaving that person's session alive would defeat the entire exercise --
     * this is the part that matters far more than how long the link lives.
     */
    public function reset(Request $request)
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $reset = null;

        $status = Password::reset($validated, function (User $user, string $password) use (&$reset): void {
            $user->password = $password;
            $user->setRememberToken(Str::random(60));
            $user->save();

            // Sign every device out, including whoever prompted the reset.
            $user->tokens()->delete();

            $reset = $user;

            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'That reset link is invalid or has already been used. Request a new one.',
            ], 422);
        }

        $this->recordAudit(
            $reset,
            'auth.password_reset',
            'user',
            $reset->id,
            'Password reset through emailed link; all sessions revoked',
            ['email' => $reset->email],
            $request,
        );

        return response()->json(['message' => 'Password updated. You can sign in now.']);
    }
}
