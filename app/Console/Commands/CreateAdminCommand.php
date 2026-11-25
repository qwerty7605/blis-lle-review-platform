<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * Makes an administrator account from the server's console.
 *
 * The website cannot make one: sign-up only ever makes students, and only an
 * administrator can make another administrator on the Users page. So the
 * first one (or a new one after every administrator is locked out) is made
 * here, by someone who can sign in to the server. The password is typed at
 * the prompt, hidden, and never taken as an option, so it is not left in the
 * shell's history or the process list.
 */
class CreateAdminCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'blis:create-admin
        {--name= : Full name (asked for when left out)}
        {--email= : Sign-in email address (asked for when left out)}';

    /**
     * @var string
     */
    protected $description = 'Create an administrator account; the password is asked for and hidden';

    public function handle(AuditLogService $audit): int
    {
        $name = trim((string) ($this->option('name') ?? $this->ask('Full name')));
        $email = strtolower(trim((string) ($this->option('email') ?? $this->ask('Email address'))));

        $details = Validator::make(
            ['name' => $name, 'email' => $email],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            ],
            ['email.unique' => 'An account with this email address already exists.'],
        );

        if ($details->fails()) {
            return $this->refuse($details->errors()->all());
        }

        $password = (string) $this->secret('Password (hidden)');

        $checked = Validator::make(
            ['password' => $password],
            ['password' => ['required', 'string', PasswordRule::defaults()]],
        );

        if ($checked->fails()) {
            return $this->refuse([
                ...$checked->errors()->all(),
                'Use at least 8 characters with upper and lower case letters, a number and a symbol.',
            ]);
        }

        if ((string) $this->secret('Type the password again') !== $password) {
            return $this->refuse(['The two passwords do not match.']);
        }

        $user = new User([
            'name' => $name,
            'email' => $email,
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
            'password' => $password,
        ]);
        // Typed in by someone with server access: there is no stranger whose
        // address needs proving, and sign-in refuses unverified accounts.
        $user->email_verified_at = now();
        $user->save();

        $audit->record(
            null,
            'admin.user.create',
            'user',
            $user->id,
            'Administrator account created from the server console',
            ['email' => $user->email, 'role' => $user->role, 'via' => 'console'],
        );

        $this->info("Administrator created: {$user->email} (account #{$user->id}). Sign in at ".config('app.url').'.');

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $problems
     */
    private function refuse(array $problems): int
    {
        foreach ($problems as $problem) {
            $this->error($problem);
        }

        $this->line('Nothing was created.');

        return self::FAILURE;
    }
}
