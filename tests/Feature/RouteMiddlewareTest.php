<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureAccountIsActive;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Every signed-in API route also checks that the account is still active.
 * Routes no longer make that check themselves; it is the `active` middleware
 * on the signed-in group in routes/api.php, so a route added outside that
 * group would let a disabled account keep working.
 */
class RouteMiddlewareTest extends TestCase
{
    public function test_every_signed_in_route_checks_the_account_is_active(): void
    {
        $unchecked = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/'))
            ->filter(fn ($route) => in_array('auth:sanctum', $route->gatherMiddleware(), true))
            ->reject(fn ($route) => collect(Route::gatherRouteMiddleware($route))->contains(EnsureAccountIsActive::class))
            ->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())
            ->values()
            ->all();

        $this->assertSame([], $unchecked);
    }

    public function test_every_api_route_is_a_controller_method(): void
    {
        $closures = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/'))
            ->filter(fn ($route) => $route->getActionName() === 'Closure')
            ->map(fn ($route) => $route->uri())
            ->values()
            ->all();

        $this->assertSame([], $closures);
    }
}
