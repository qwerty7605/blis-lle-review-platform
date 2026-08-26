<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/auth/me', function (Request $request) use ($ensureActive) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    return response()->json($request->user());
});

Route::post('/auth/logout', function (Request $request) use ($ensureActive, $authTokenCookieService) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $request->user()->currentAccessToken()?->delete();

    return $authTokenCookieService->clearTokenCookie(
        response()->json(['message' => 'Logged out'])
    );
});
