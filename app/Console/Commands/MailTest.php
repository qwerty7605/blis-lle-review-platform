<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Proves the current mail configuration actually delivers.
 *
 * Registration verification and password reset both depend on mail silently.
 * Without something like this, the first sign it is misconfigured is a student
 * reporting that no email arrived -- at which point you are debugging SMTP
 * through somebody else's inbox.
 */
class MailTest extends Command
{
    protected $signature = 'mail:test {recipient : Where to send the test message}';

    protected $description = 'Send a test email to confirm the mail configuration works';

    public function handle(): int
    {
        $recipient = (string) $this->argument('recipient');
        $mailer = (string) config('mail.default');

        $this->newLine();
        $this->line('  mailer : '.$mailer);
        $this->line('  host   : '.(config('mail.mailers.smtp.host') ?: 'n/a'));
        $this->line('  from   : '.config('mail.from.address'));
        $this->line('  to     : '.$recipient);
        $this->newLine();

        if ($mailer === 'log') {
            $this->warn('  MAIL_MAILER is "log": this writes to storage/logs/laravel.log');
            $this->warn('  and delivers nothing. Set real credentials before trusting it.');
            $this->newLine();
        }

        try {
            Mail::raw(
                'This is a test from '.config('app.name').".\n\n"
                ."If it reached a real inbox, the registration verification and\n"
                .'password reset emails will reach one too.',
                static fn ($message) => $message
                    ->to($recipient)
                    ->subject(config('app.name').' mail test'),
            );
        } catch (Throwable $e) {
            $this->error('  Sending failed: '.$e->getMessage());
            $this->newLine();
            $this->line('  Usual causes, roughly in order:');
            $this->line('   - Gmail: using the account password instead of an App Password');
            $this->line('   - MAIL_FROM_ADDRESS not matching the sending account');
            $this->line('   - mismatched port and scheme (587 with smtp, 465 with smtps;');
            $this->line("     MAIL_SCHEME only accepts 'smtp' or 'smtps', never 'tls')");
            $this->line('   - outbound port 587 blocked by the host; many providers do');
            $this->line('   - stale config cache -- run: php artisan config:clear');
            $this->newLine();

            return self::FAILURE;
        }

        $this->info('  Accepted by the mailer without error.');

        if ($mailer === 'log') {
            $this->line('  Look for it in storage/logs/laravel.log.');
        } else {
            $this->line('  Check the inbox, then the spam folder -- a new sending domain');
            $this->line('  usually lands there until it earns some reputation.');
        }

        $this->newLine();

        return self::SUCCESS;
    }
}
