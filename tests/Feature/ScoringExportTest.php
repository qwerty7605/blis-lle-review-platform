<?php

namespace Tests\Feature;

use App\Models\ExamAttemptAnswer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsBlisData;
use Tests\TestCase;

/**
 * scoring:export (ExportScoringCheckCommand): the sheets the team re-checks
 * scoring from by hand.
 */
class ScoringExportTest extends TestCase
{
    use BuildsBlisData;
    use RefreshDatabase;

    public function test_it_exports_each_dealt_item_with_the_key_the_answer_and_the_stored_score(): void
    {
        Storage::fake('local');
        $teacher = $this->makeTeacher();
        $student = $this->makeStudent(['student_id' => '2301555']);
        $room = $this->makeRoom($teacher, [$student]);
        $exam = $this->makeExam($teacher, $this->makeBank($teacher, 4), [$room]);
        $attempt = $this->makeSubmittedAttempt($exam, $room, $student, [true, false, null, true]);

        $this->artisan('scoring:export', ['--count' => 30])->assertSuccessful();

        [$attempts, $items] = $this->sheets();

        $this->assertCount(1, $attempts);
        $this->assertSame((string) $attempt->id, $attempts[0]['attempt_id']);
        $this->assertSame('2301555', $attempts[0]['student_id']);
        $this->assertSame('50.00', $attempts[0]['stored_score_percent']);
        $this->assertSame('2', $attempts[0]['correct_by_key']);
        $this->assertSame('50.00', $attempts[0]['score_by_key']);
        $this->assertSame('yes', $attempts[0]['agrees']);

        $this->assertCount(4, $items);
        $this->assertSame(['right', 'wrong', 'blank', 'right'], array_column($items, 'stored_result'));
        $this->assertSame(['right', 'wrong', 'wrong', 'right'], array_column($items, 'result_by_key'));
        $this->assertSame('A. Correct answer 2', $items[1]['answer_key']);
        $this->assertSame('B. Wrong answer 2b', $items[1]['student_answer']);
        $this->assertSame('(blank)', $items[2]['student_answer']);
        $this->assertSame(['yes', 'yes', 'yes', 'yes'], array_column($items, 'agrees'));
    }

    public function test_a_stored_result_that_disagrees_with_the_key_is_marked(): void
    {
        Storage::fake('local');
        $teacher = $this->makeTeacher();
        $student = $this->makeStudent();
        $room = $this->makeRoom($teacher, [$student]);
        $exam = $this->makeExam($teacher, $this->makeBank($teacher, 2), [$room]);
        $attempt = $this->makeSubmittedAttempt($exam, $room, $student, [false, true]);

        // A wrong answer stored as right.
        ExamAttemptAnswer::where('exam_attempt_id', $attempt->id)->where('is_correct', false)->update(['is_correct' => true]);

        $this->artisan('scoring:export')->assertSuccessful();

        [$attempts, $items] = $this->sheets();

        $this->assertSame('NO', $attempts[0]['agrees']);
        $this->assertSame(['NO', 'yes'], array_column($items, 'agrees'));
    }

    public function test_the_same_seed_picks_the_same_attempts(): void
    {
        Storage::fake('local');
        $teacher = $this->makeTeacher();
        $room = $this->makeRoom($teacher);
        $exam = $this->makeExam($teacher, $this->makeBank($teacher, 1), [$room]);

        for ($i = 0; $i < 8; $i++) {
            $this->makeSubmittedAttempt($exam, $room, $this->makeStudent(), [true]);
        }

        $picked = function (int $seed): array {
            Storage::fake('local');
            $this->artisan('scoring:export', ['--count' => 3, '--seed' => $seed])->assertSuccessful();

            return array_column($this->sheets()[0], 'attempt_id');
        };

        $this->assertCount(3, $picked(5));
        $this->assertSame($picked(5), $picked(5));
    }

    /**
     * @return array{0: list<array<string, string>>, 1: list<array<string, string>>}
     */
    private function sheets(): array
    {
        $files = collect(Storage::disk('local')->files('scoring-check'));
        $read = function (string $suffix) use ($files): array {
            $content = Storage::disk('local')->get($files->first(fn (string $file): bool => str_ends_with($file, $suffix)));
            $lines = array_map('str_getcsv', preg_split('/\R/', trim(substr($content, 3))));
            $header = array_shift($lines);

            return array_map(fn (array $line): array => array_combine($header, $line), $lines);
        };

        return [$read('-attempts.csv'), $read('-items.csv')];
    }
}
