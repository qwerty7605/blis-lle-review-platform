<?php

namespace Tests\Feature\ReviewBot;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsBlisData;
use Tests\TestCase;

/**
 * The teacher's Review Bot page is told which exams use a file and which of
 * them keep practice closed (Hidden results): publishing alone does not put a
 * question in front of a student.
 */
class ReviewBotReachTest extends TestCase
{
    use BuildsBlisData;
    use RefreshDatabase;

    public function test_the_page_learns_which_exams_using_the_file_keep_practice_closed(): void
    {
        $teacher = $this->makeTeacher();
        $bank = $this->makeBank($teacher, 4);
        $this->makeExam($teacher, $bank, [], ['title' => 'Hidden Midterm', 'results_visibility_mode' => 'hidden']);
        $this->makeExam($teacher, $bank, [], ['title' => 'Visible Quiz', 'results_visibility_mode' => 'visible']);
        $unused = $this->makeBank($teacher, 4);

        Sanctum::actingAs($teacher);

        $this->getJson("/api/library/review-bot/questions?source_bank_id={$bank->id}")
            ->assertOk()
            ->assertJsonPath('reach.exams', 2)
            ->assertJsonPath('reach.hidden', ['Hidden Midterm']);

        $this->getJson("/api/library/review-bot/questions?source_bank_id={$unused->id}")
            ->assertOk()
            ->assertJsonPath('reach.exams', 0)
            ->assertJsonPath('reach.hidden', []);
    }
}
