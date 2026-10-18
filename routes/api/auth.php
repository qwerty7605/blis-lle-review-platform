<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/auth/me', function (Request $request) use ($ensureActive, $authTokenCookieService) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    return response()->json(array_merge($request->user()->toArray(), [
        'session_idle_minutes' => $authTokenCookieService->idleMinutes($request->user()->currentAccessToken()),
    ]));
});

Route::post('/auth/logout', function (Request $request) use ($ensureActive, $authTokenCookieService, $recordAudit) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $recordAudit($request->user(), 'auth.logout', 'user', $request->user()->id, 'Signed out', [], $request);

    $request->user()->currentAccessToken()?->delete();

    return $authTokenCookieService->clearTokenCookie(
        response()->json(['message' => 'Logged out'])
    );
});
