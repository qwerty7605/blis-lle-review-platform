<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * What each audit action is called on the Audit page and in its export, and
 * which group it is filtered under. The action strings themselves are what
 * the code writes and never change; only these names do.
 */
final class AuditEvents
{
    /** Filter groups, in the order the page lists them, by action prefix. */
    public const CATEGORIES = [
        'access' => ['label' => 'Sign-in and accounts', 'prefixes' => ['auth.', 'account.']],
        'users' => ['label' => 'User management', 'prefixes' => ['admin.user.', 'admin.year_end.']],
        'exams' => ['label' => 'Exams', 'prefixes' => ['exam.create', 'exam.update', 'exam.delete', 'exam.archive', 'exam.type.', 'exam.series.', 'room.exam.']],
        'attempts' => ['label' => 'Exam attempts', 'prefixes' => ['exam.attempt.']],
        'rooms' => ['label' => 'Rooms', 'prefixes' => ['room.create', 'room.update', 'room.delete', 'room.archive', 'room.restore', 'room.join', 'room.joins.', 'room.leave', 'room.member.']],
        'library' => ['label' => 'Library', 'prefixes' => ['library.']],
        'review_bot' => ['label' => 'Review Bot and practice', 'prefixes' => ['review-bot.', 'practice.']],
        'reports' => ['label' => 'Reports and exports', 'prefixes' => ['report.', 'room.export.', 'audit.export']],
        'licensure' => ['label' => 'Licensure and predictions', 'prefixes' => ['lle-result.', 'prediction.']],
        'settings' => ['label' => 'Settings', 'prefixes' => ['settings.']],
        'feedback' => ['label' => 'Feedback', 'prefixes' => ['feedback.']],
    ];

    private const LABELS = [
        'auth.login' => 'Signed in',
        'auth.login_failed' => 'Sign-in failed',
        'auth.logout' => 'Signed out',
        'auth.register' => 'Registered an account',
        'auth.password_reset' => 'Reset password by email',
        'account.update-name' => 'Changed their name',
        'account.update-password' => 'Changed their password',
        'account.request-email-change' => 'Asked to change sign-in email',
        'account.update-email' => 'Changed sign-in email',
        'account.sign-out-device' => 'Signed out a device',
        'account.sign-out-others' => 'Signed out all other devices',
        'admin.user.create' => 'Created a user',
        'admin.user.update' => 'Updated a user',
        'admin.user.recover' => 'Recovered an account',
        'admin.year_end.apply' => 'Closed the school year',
        'admin.year_end.graduate' => 'Graduated a student',
        'admin.year_end.advance' => 'Moved a student up a year',
        'exam.create' => 'Created an exam',
        'exam.update' => 'Updated an exam',
        'exam.delete' => 'Deleted an exam',
        'exam.archive' => 'Archived an exam',
        'exam.type.change' => 'Changed an exam\'s type',
        'exam.series.create' => 'Created an exam series',
        'exam.series.rename' => 'Renamed an exam series',
        'exam.series.delete' => 'Deleted an exam series',
        'exam.attempt.start' => 'Started an exam',
        'exam.attempt.submit' => 'Submitted an exam',
        'exam.attempt.auto_submit' => 'Exam submitted when time ran out',
        'exam.attempt.extra_time' => 'Gave extra time on an exam',
        'room.create' => 'Created a room',
        'room.update' => 'Updated a room',
        'room.delete' => 'Deleted a room',
        'room.archive' => 'Archived a room',
        'room.restore' => 'Restored a room',
        'room.join' => 'Joined a room',
        'room.joins.close' => 'Closed a room to new students',
        'room.joins.open' => 'Opened a room to new students',
        'room.leave' => 'Left a room',
        'room.member.remove' => 'Removed a student from a room',
        'room.exam.archive' => 'Archived an exam in a room',
        'room.exam.restore' => 'Restored an exam to a room',
        'room.export.grades' => 'Exported room grades',
        'library.import.preview' => 'Read a Word file for import',
        'library.bank.create' => 'Created a question set',
        'library.bank.update' => 'Updated a question set',
        'library.bank.delete' => 'Deleted a question set',
        'library.bank.view_questions' => 'Opened a question set',
        'library.question.edit' => 'Edited a question',
        'library.question.regrade' => 'Re-graded answers after a key change',
        'library.mapping.queue' => 'Queued topic tagging',
        'library.mapping.bulk_confirm' => 'Confirmed suggested tags',
        'library.audit-answer-keys' => 'Checked answer keys',
        'library.answer-key.correct' => 'Corrected an answer key',
        'library.answer-key.dismiss-flag' => 'Dismissed an answer-key flag',
        'library.remedial.queue' => 'Queued review questions',
        'library.remedial.refresh' => 'Regenerated a review question',
        'library.remedial.review' => 'Reviewed a review question',
        'library.remedial.bulk_review' => 'Reviewed review questions',
        'review-bot.admin.generate' => 'Generated Review Bot questions',
        'review-bot.admin.publish' => 'Published Review Bot questions',
        'review-bot.admin.unpublish' => 'Unpublished Review Bot questions',
        'review-bot.admin.reject' => 'Rejected a Review Bot question',
        'review-bot.admin.update-question' => 'Edited a Review Bot question',
        'review-bot.admin.delete-question' => 'Deleted a Review Bot question',
        'review-bot.session.start' => 'Started a Review Bot session',
        'review-bot.session.submit' => 'Finished a Review Bot session',
        'practice.attempt.start' => 'Started a practice set',
        'practice.attempt.submit' => 'Finished a practice set',
        'report.export.summary' => 'Exported a results summary',
        'report.export.summary_pdf' => 'Exported a results summary (PDF)',
        'report.export.complete_results_csv' => 'Exported complete results (CSV)',
        'report.export.complete_results_xlsx' => 'Exported complete results (Excel)',
        'report.export.answer_key_pdf' => 'Exported an answer key (PDF)',
        'report.export.individual_student_pdf' => 'Exported a student report (PDF)',
        'report.export.student_reports_zip' => 'Exported student reports (ZIP)',
        'report.email.individual_student_pdf' => 'Emailed a student report',
        'report.email.student_reports_bulk' => 'Sent student reports by email',
        'report.email.failed' => 'A student report email failed',
        'lle-result.record' => 'Recorded a licensure result',
        'lle-result.record-batch' => 'Recorded licensure results for a batch',
        'lle-result.delete' => 'Deleted a licensure result',
        'lle-result.rate.record' => 'Entered an LLE passing rate',
        'lle-result.rate.delete' => 'Removed an LLE passing rate',
        'prediction.let.run' => 'Worked out an Estimated Outcome',
        'prediction.let.retrain.request' => 'Asked for the Estimated Outcome model to be retrained',
        'prediction.let.retrain' => 'Retrained the Estimated Outcome model',
        'prediction.let.activate' => 'Switched the Estimated Outcome model version',
        'settings.update' => 'Changed system settings',
        'feedback.send' => 'Sent feedback',
        'feedback.send_to_developers' => 'Sent feedback to the developers',
        'feedback.answer' => 'Answered feedback',
        'feedback.decline' => 'Closed feedback',
        'feedback.done' => 'Marked feedback done',
        'feedback.reopen' => 'Reopened feedback',
        'audit.export' => 'Exported the audit log',
    ];

    /** Actions that record something refused or failed rather than done. */
    private const FAILURES = ['auth.login_failed'];

    public static function label(string $action): string
    {
        if (isset(self::LABELS[$action])) {
            return self::LABELS[$action];
        }

        // An action added without a name here still reads as words.
        return Str::ucfirst(str_replace(['.', '_', '-'], ' ', $action));
    }

    public static function category(string $action): ?string
    {
        foreach (self::CATEGORIES as $key => $category) {
            foreach ($category['prefixes'] as $prefix) {
                if (str_starts_with($action, $prefix)) {
                    return $key;
                }
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function failureActions(): array
    {
        return self::FAILURES;
    }

    public static function outcome(string $action): string
    {
        return in_array($action, self::FAILURES, true) ? 'failure' : 'success';
    }

    /** @return array<int, array{key: string, label: string}> */
    public static function categoryList(): array
    {
        return collect(self::CATEGORIES)
            ->map(fn (array $category, string $key) => ['key' => $key, 'label' => $category['label']])
            ->values()
            ->all();
    }

    /** @return list<string> */
    public static function prefixesFor(string $category): array
    {
        return self::CATEGORIES[$category]['prefixes'] ?? [];
    }
}
