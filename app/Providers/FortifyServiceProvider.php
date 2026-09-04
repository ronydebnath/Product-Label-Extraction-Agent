<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);

        // Stage 4 swaps these two closures for Inertia pages. They exist now because the guest
        // middleware redirects to route('login'), so the route has to resolve to something real.
        Fortify::loginView(fn () => view('auth.login'));
        Fortify::registerView(fn () => view('auth.register'));

        // Keyed on email plus IP rather than IP alone: a shared NAT should not lock out a whole
        // office, and a password spray against one account should still stop after five guesses.
        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });
    }
}
