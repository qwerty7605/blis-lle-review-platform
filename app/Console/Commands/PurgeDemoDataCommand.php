<?php

namespace App\Console\Commands;

use App\Services\Demo\DemoDataPurger;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;

class PurgeDemoDataCommand extends Command
{
    use ConfirmableTrait;

    /**
     * @var string
     */
    protected $signature = 'demo:purge
        {--dry-run : List what would be removed without removing anything}
        {--force : Skip the confirmation prompt in production}';

    /**
     * @var string
     */
    protected $description = 'Remove everything the demo seeders created, leaving real accounts and records untouched';

    public function handle(): int
    {
        $purger = new DemoDataPurger();
        $plan = $purger->plan();

        if ($plan['accounts'] === 0) {
            $this->info('No demo data found.');

            return self::SUCCESS;
        }

        $this->table(['Demo data', 'Count'], [
            ['Accounts', "{$plan['accounts']} ({$plan['students']} students)"],
            ['Rooms', $plan['rooms']],
            ['Exams', $plan['exams']],
            ['Library files and Review Bot banks', $plan['library_files']],
            ['Questions', $plan['questions']],
            ['Exam attempts', $plan['exam_attempts']],
            ['Review Bot sessions', $plan['review_sessions']],
            ['Licensure results', $plan['licensure_results']],
            ['Audit log entries', $plan['audit_entries']],
        ]);

        $realActivity = $plan['real_account_attempts'] + $plan['real_account_sessions'] + $plan['real_room_memberships'];

        if ($realActivity > 0) {
            $this->warn(sprintf(
                'Real accounts used the demo content: %d exam attempt(s), %d Review Bot session(s) and %d room membership(s) go with it.',
                $plan['real_account_attempts'],
                $plan['real_account_sessions'],
                $plan['real_room_memberships'],
            ));
        }

        foreach ($plan['adopted_files'] as $title) {
            $this->line("Kept: \"{$title}\" -- a real exam uses it, so it passes to that exam's author.");
        }

        foreach ($plan['shared_review_banks'] as $title) {
            $this->line("Kept: \"{$title}\" -- it also holds real teachers' questions; only the demo ones are removed.");
        }

        if ($plan['blocking'] !== []) {
            $this->error('Nothing was removed, because real records would go with the demo rooms:');

            foreach ($plan['blocking'] as $problem) {
                $this->line($problem);
            }

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing was removed.');

            return self::SUCCESS;
        }

        if (!$this->confirmToProceed('This permanently deletes the demo data listed above.')) {
            return self::FAILURE;
        }

        $removed = $purger->purge();

        $this->info(sprintf(
            'Removed %d demo account(s), %d exam attempt(s), %d room(s), %d exam(s) and %d Library file(s).',
            $removed['accounts'],
            $removed['exam_attempts'],
            $removed['rooms'],
            $removed['exams'],
            $removed['library_files'],
        ));

        return self::SUCCESS;
    }
}
