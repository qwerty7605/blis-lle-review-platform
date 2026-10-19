<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuthTokenCookieService;
use App\Support\DeviceLabel;
use Illuminate\Http\Request;

class AccountSessionController extends Controller
{
    public function __construct(
        private readonly AuthTokenCookieService $authTokenCookieService,
    ) {
    }

    public function index(Request $request)
    {
        $currentId = $request->user()->currentAccessToken()?->id;

        return response()->json([
            'sessions' => $this->liveSignIns($request)
                ->sortByDesc(fn ($token) => [$token->id === $currentId, $token->last_used_at ?? $token->created_at])
                ->values()
                ->map(fn ($token) => [
                    'id' => $token->id,
                    'device' => DeviceLabel::from($token->user_agent),
                    'ip_address' => $token->ip_address,
                    'signed_in_at' => $token->created_at,
                    'last_used_at' => $token->last_used_at ?? $token->created_at,
                    'remembered' => $this->authTokenCookieService->idleMinutes($token) === null,
                    'current' => $token->id === $currentId,
                ]),
        ]);
    }

    public function destroy(Request $request, int $tokenId)
    {
        $user = $request->user();

        if ($tokenId === (int) $user->currentAccessToken()?->id) {
            return response()->json(['message' => 'To sign out here, use Log out.'], 422);
        }

        // Only ever one of their own: someone else's id finds nothing.
        $removed = $user->tokens()->where('id', $tokenId)->delete();

        if ($removed === 0) {
            return response()->json(['message' => 'That sign-in has already ended.'], 404);
        }

        $this->recordAudit($user, 'account.sign-out-device', 'user', $user->id, 'Signed out one of their other devices', [], $request);

        return response()->json(['message' => 'That device has been signed out.']);
    }

    public function destroyOthers(Request $request)
    {
        $user = $request->user();
        $removed = $user->tokens()->where('id', '!=', $user->currentAccessToken()?->id)->delete();

        $this->recordAudit($user, 'account.sign-out-others', 'user', $user->id, 'Signed out all of their other devices', ['signed_out' => $removed], $request);

        return response()->json([
            'message' => $removed > 0
                ? 'Every other device has been signed out.'
                : 'You are not signed in anywhere else.',
            'signed_out' => $removed,
        ]);
    }

    private function liveSignIns(Request $request)
    {
        $longest = (int) config('sanctum.expiration');

        return $request->user()->tokens()
            ->when($longest > 0, fn ($query) => $query->where('created_at', '>', now()->subMinutes($longest)))
            ->get()
            ->reject(fn ($token) => $this->authTokenCookieService->hasGoneIdle($token));
    }
}
