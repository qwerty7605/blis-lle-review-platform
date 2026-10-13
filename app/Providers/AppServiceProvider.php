<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use App\Services\AuthTokenCookieService;
use App\Support\SystemSettings;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped, not a singleton: the maintenance lookup is memoised for the
        // life of one request and thrown away after it, so a persistent worker
        // never serves the next request yesterday's answer.
        $this->app->scoped(SystemSettings::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
        $this->configureAccountMails();
        $this->configurePasswordPolicy();
        $this->configureHealthCheck();
        $this->configureIdleSignOut();
    }

    /**
     * A sign-in made without "Remember me" stops working once it has sat
     * unused for too long. Sanctum asks this before it records the request as
     * a use of the token, so the time compared is the previous use.
     */
    private function configureIdleSignOut(): void
    {
        Sanctum::authenticateAccessTokensUsing(
            static fn (PersonalAccessToken $token, bool $isValid): bool => $isValid
                && !app(AuthTokenCookieService::class)->hasGoneIdle($token)
        );
    }

    /**
     * Make /up prove the database is reachable, not just that PHP answered.
     *
     * Laravel's health route returns 200 as long as the framework boots, so
     * out of the box it reports healthy while MySQL is down. That is worse
     * than having no check: a load balancer believes it and keeps routing
     * traffic to an instance that cannot serve a single request.
     *
     * Throwing here is the documented way to fail the check -- the exception
     * propagates and /up answers 500.
     */
    private function configureHealthCheck(): void
    {
        Event::listen(static function (DiagnosingHealth $event): void {
            DB::connection()->select('select 1');
        });
    }

    /**
     * One definition of what counts as an acceptable password.
     *
     * Registered here rather than repeated at each call site: the rule is
     * applied in six places (registration, reset, and four admin paths), and
     * a policy copy-pasted six times is a policy that drifts. Every one of
     * those now asks for PasswordRule::defaults().
     *
     * Existing passwords are untouched -- this governs what may be *set*, so
     * nobody is locked out of an account they already have.
     */
    private function configurePasswordPolicy(): void
    {
        PasswordRule::defaults(static fn (): PasswordRule => PasswordRule::min(8)
            ->mixedCase()
            ->numbers()
            ->symbols());
    }

    /**
     * Shapes the two account emails Laravel sends on our behalf.
     *
     * Both ship as generic framework boilerplate otherwise -- a student
     * receiving "Verify Email Address" from an unfamiliar domain has no way
     * to tell it apart from phishing, which is exactly the instinct we want
     * them to keep.
     */
    private function configureAccountMails(): void
    {
        VerifyEmail::toMailUsing(function (object $notifiable, string $url): MailMessage {
            $hours = (int) round(((int) config('auth.verification.expire', 60)) / 60);

            return (new MailMessage())
                ->subject('Verify your '.config('app.name').' account')
                ->greeting('Welcome to '.config('app.name').'.')
                ->line('An account was created with this address. Confirm it is yours to finish signing up.')
                ->action('Verify my account', $url)
                ->line("This link stops working in {$hours} hours. You can request a new one from the sign-in page.")
                ->line('If you did not create this account, ignore this email -- nothing was activated.');
        });

        // Unlike verification, a reset link has to land on the SPA rather than
        // the API: the person still needs a form to type a new password into.
        //
        // Note the asymmetry in Laravel's own callbacks -- VerifyEmail hands
        // toMailUsing a finished URL, while ResetPassword hands it the raw
        // token. Building the URL here keeps both paths using one definition.
        $resetUrl = static function (object $notifiable, string $token): string {
            $frontend = rtrim((string) config('app.frontend_url'), '/');

            return $frontend.'/reset-password?token='.$token
                .'&email='.urlencode($notifiable->getEmailForPasswordReset());
        };

        ResetPassword::createUrlUsing($resetUrl);

        ResetPassword::toMailUsing(function (object $notifiable, string $token) use ($resetUrl): MailMessage {
            $minutes = (int) config('auth.passwords.users.expire', 60);

            return (new MailMessage())
                ->subject('Reset your '.config('app.name').' password')
                ->greeting('Password reset requested.')
                ->line('Use the button below to choose a new password.')
                ->action('Choose a new password', $resetUrl($notifiable, $token))
                ->line("This link stops working in {$minutes} minutes and can only be used once.")
                ->line('If you did not ask for this, ignore this email -- your password has not changed.');
        });
    }

    /**
     * Named rate limiters used by the API.
     *
     * The login limiter is deliberately two-tier. Accounts here are reachable
     * by student_id as well as email (see the /auth/login route), and student
     * IDs run in predictable yearly sequences -- so an attacker never has to
     * guess *who* exists, only what their password is. A single per-account
     * limit would still let someone spray one password across a whole cohort
     * from one machine, so the second tier caps how many distinct accounts a
     * single IP may attempt per minute.
     *
     * The wider 'api' limiter keys on the authenticated user first and only
     * falls back to the IP for guests. That matters on a campus: a computer
     * lab behind one NAT address would otherwise share a single bucket, and
     * one busy student could lock out the room.
     *
     * Every per-IP number below is sized for that same campus. A lab, or the
     * school Wi-Fi, reaches this server through one address, so "one IP" can
     * be a couple of hundred students doing the same thing in the same minute
     * (planning figure: 230). The per-IP tiers are therefore ceilings for a
     * whole room. What singles out an abuser is the tier keyed on the thing
     * being attacked -- the account, or the mailbox -- and those stay tight.
     * Tighten the per-IP tiers again if this is ever opened to the public
     * internet rather than campus.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request): array {
            $identifier = Str::lower(trim((string) $request->input('identifier')));

            return [
                // One account, one origin: slow down password guessing.
                Limit::perMinute(5)->by($identifier.'|'.$request->ip()),
                // One origin, many accounts: slow down password spraying.
                // 120 lets the whole campus sign in within two minutes. It is
                // not higher because each attempt is a bcrypt check, about a
                // quarter of a second of CPU on the current server.
                Limit::perMinute(120)->by($request->ip()),
            ];
        });

        // Public self-registration is on by default (see the
        // allow_public_registration setting), creates a student account and
        // sends a verification email -- so an unmetered endpoint here is an
        // open invitation to mass-create accounts and to spend the mail
        // quota. 30 a minute lets a lab of 45 finish in two minutes and 150
        // an hour fits three labs back to back, while still making scripted
        // signup impractical; tighten them if registration is ever opened to
        // the public internet rather than campus.
        RateLimiter::for('register', function (Request $request): array {
            return [
                Limit::perMinute(30)->by($request->ip()),
                Limit::perHour(150)->by($request->ip()),
            ];
        });

        // Resending verification mail costs real money, and an open resend
        // endpoint is a convenient way to use somebody else's inbox as a mail
        // bomb. Capped far harder than ordinary traffic, per address as well
        // as per origin. The mailbox tier is the one that stops a mail bomb,
        // wherever it is sent from; the origin tier only stops one machine
        // working through many mailboxes, and has to leave room for a lab
        // where a few dozen students never got their first email.
        RateLimiter::for('verification', function (Request $request): array {
            $email = Str::lower(trim((string) $request->input('email')));

            return [
                Limit::perHour(30)->by($request->ip()),
                Limit::perHour(3)->by($email !== '' ? $email : $request->ip()),
            ];
        });

        // Asking for a reset link sends mail, so it is capped hard, per address
        // as well as per origin. Kept separate from 'verification' so burning
        // through one does not lock a student out of the other. Same two
        // tiers, sized the same way.
        RateLimiter::for('password-reset-request', function (Request $request): array {
            $email = Str::lower(trim((string) $request->input('email')));

            return [
                Limit::perHour(30)->by($request->ip()),
                Limit::perHour(3)->by($email !== '' ? $email : $request->ip()),
            ];
        });

        // Spending the token sends nothing, and the token is 60 random
        // characters, so brute force is not the threat here -- a mistyped
        // confirmation is. Loose enough that fumbling the form a few times
        // never costs someone an hour's lockout, and kept at twice the links
        // one origin can ask for in an hour so this step never runs out
        // before the one above.
        RateLimiter::for('password-reset-confirm', function (Request $request): Limit {
            return Limit::perHour(60)->by($request->ip());
        });

        RateLimiter::for('api', function (Request $request): Limit {
            // The default guard is 'web' (session), which never resolves for
            // token traffic -- ask the sanctum guard explicitly instead.
            $userId = $request->user('sanctum')?->id;

            if ($userId) {
                return Limit::perMinute(120)->by($userId);
            }

            // Signed-out requests can only be told apart by address, and a
            // campus shares one. Every sign-in, registration and reset from
            // that address lands in this bucket as well as in its own
            // limiter, so it has to sit well above those or it becomes the
            // real ceiling instead of them.
            return Limit::perMinute(600)->by($request->ip());
        });
    }
}
