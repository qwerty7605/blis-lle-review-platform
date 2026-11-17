<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\LlePassingRate;
use App\Models\LleResult;
use App\Models\User;
use App\Services\LlePassingRateTrend;
use App\Services\LleResultRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Licensure page's passing-rate trend: LNU's rate counted from the
 * results recorded in BLIS (or typed in for a year before it), beside the
 * national rate an administrator types in.
 */
class LlePassingRateTest extends TestCase
{
    use RefreshDatabase;

    public function test_lnu_s_rate_is_counted_from_every_sitting_recorded_beside_the_national_one(): void
    {
        [$ana, $ben, $cara, $dan] = [$this->student(), $this->student(), $this->student(), $this->student()];

        LleResult::create(['user_id' => $ana->id, 'exam_year' => 2025, 'result' => LleResult::RESULT_PASS]);
        LleResult::create(['user_id' => $ben->id, 'exam_year' => 2025, 'result' => LleResult::RESULT_FAIL]);
        // No sitting, so not a taker.
        LleResult::create(['user_id' => $cara->id, 'exam_year' => 2025, 'result' => LleResult::RESULT_DID_NOT_TAKE]);
        // A retake counts in its own year, as the PRC counts repeaters.
        LleResult::create(['user_id' => $ben->id, 'exam_year' => 2026, 'result' => LleResult::RESULT_PASS]);
        LleResult::create(['user_id' => $dan->id, 'exam_year' => 2026, 'result' => LleResult::RESULT_FAIL]);
        LleResult::create(['user_id' => $cara->id, 'exam_year' => 2026, 'result' => LleResult::RESULT_FAIL]);

        Sanctum::actingAs($this->admin());

        $this->postJson('/api/lle-results/passing-rates', [
            'scope' => LlePassingRate::SCOPE_NATIONAL,
            'exam_year' => 2025,
            'passers' => 527,
            'takers' => 1169,
            // Worked out from the two counts, whatever is sent.
            'passing_rate' => 99,
            'source' => 'PRC, 2025 LLE results',
        ])
            ->assertCreated()
            ->assertJsonPath('message', 'Saved the national figures for 2025.');

        // Only a percentage known.
        $this->postJson('/api/lle-results/passing-rates', ['scope' => LlePassingRate::SCOPE_NATIONAL, 'exam_year' => 2026, 'passing_rate' => 41.5])
            ->assertCreated();

        $years = $this->getJson('/api/lle-results/passing-rates')->assertOk()->json('years');

        $this->assertSame([2025, 2026], array_column($years, 'exam_year'));

        // JSON has no 50.0: a whole rate comes back as 50.
        $this->assertSame(['passers' => 1, 'takers' => 2, 'passing_rate' => 50, 'from' => 'recorded'], $years[0]['lnu']);
        $this->assertSame(['passers' => 1, 'takers' => 2, 'passing_rate' => 50], $years[0]['recorded']);
        $this->assertSame(527, $years[0]['national']['passers']);
        $this->assertSame(1169, $years[0]['national']['takers']);
        $this->assertSame(45.08, $years[0]['national']['passing_rate']);
        $this->assertSame('PRC, 2025 LLE results', $years[0]['national']['source']);
        $this->assertSame(4.92, $years[0]['difference']);

        $this->assertSame(33.33, $years[1]['lnu']['passing_rate']);
        $this->assertNull($years[1]['national']['passers']);
        $this->assertSame(41.5, $years[1]['national']['passing_rate']);
        $this->assertSame(-8.17, $years[1]['difference']);
    }

    public function test_lnu_s_figures_typed_in_are_used_in_place_of_the_records_for_their_year(): void
    {
        $ana = $this->student();
        LleResult::create(['user_id' => $ana->id, 'exam_year' => 2025, 'result' => LleResult::RESULT_FAIL]);

        Sanctum::actingAs($this->teacher());

        // A year before BLIS.
        $this->postJson('/api/lle-results/passing-rates', ['scope' => LlePassingRate::SCOPE_SCHOOL, 'exam_year' => 2019, 'passers' => 8, 'takers' => 12])
            ->assertCreated()
            ->assertJsonPath('message', "Saved LNU's figures for 2019.");
        // A year BLIS holds only some results for.
        $this->postJson('/api/lle-results/passing-rates', ['scope' => LlePassingRate::SCOPE_SCHOOL, 'exam_year' => 2025, 'passers' => 9, 'takers' => 12])
            ->assertCreated();

        $years = collect($this->getJson('/api/lle-results/passing-rates')->assertOk()->json('years'))->keyBy('exam_year');

        $this->assertSame([2019, 2025], $years->keys()->all());
        $this->assertSame('entered', $years[2019]['lnu']['from']);
        $this->assertSame(66.67, $years[2019]['lnu']['passing_rate']);
        $this->assertNull($years[2019]['recorded']);
        $this->assertNull($years[2019]['national']);
        $this->assertNull($years[2019]['difference']);

        $this->assertSame('entered', $years[2025]['lnu']['from']);
        $this->assertSame(75, $years[2025]['lnu']['passing_rate']);
        // What BLIS has for the year still comes back.
        $this->assertSame(['passers' => 0, 'takers' => 1, 'passing_rate' => 0], $years[2025]['recorded']);
    }

    public function test_only_an_administrator_enters_the_national_figures(): void
    {
        $teacher = $this->teacher();
        $national = LlePassingRate::create(['scope' => LlePassingRate::SCOPE_NATIONAL, 'exam_year' => 2024, 'passing_rate' => 40]);
        $school = LlePassingRate::create(['scope' => LlePassingRate::SCOPE_SCHOOL, 'exam_year' => 2024, 'passers' => 5, 'takers' => 10, 'passing_rate' => 50]);

        Sanctum::actingAs($teacher);

        $this->getJson('/api/lle-results/passing-rates')->assertOk()->assertJsonCount(1, 'years');
        $this->postJson('/api/lle-results/passing-rates', ['scope' => LlePassingRate::SCOPE_NATIONAL, 'exam_year' => 2025, 'passing_rate' => 40])
            ->assertForbidden()
            ->assertJsonPath('message', 'Only an administrator enters the national figures.');
        $this->deleteJson("/api/lle-results/passing-rates/{$national->id}")->assertForbidden();
        $this->deleteJson("/api/lle-results/passing-rates/{$school->id}")
            ->assertOk()
            ->assertJsonPath('message', "Removed LNU's figures for 2024.");

        Sanctum::actingAs($this->student());

        $this->getJson('/api/lle-results/passing-rates')->assertForbidden();
        $this->postJson('/api/lle-results/passing-rates', ['scope' => LlePassingRate::SCOPE_SCHOOL, 'exam_year' => 2025, 'passing_rate' => 40])->assertForbidden();

        Sanctum::actingAs($this->admin());

        $this->deleteJson("/api/lle-results/passing-rates/{$national->id}")->assertOk();
        $this->assertDatabaseCount('lle_passing_rates', 0);
    }

    public function test_figures_that_cannot_be_right_are_refused_under_their_field(): void
    {
        Sanctum::actingAs($this->admin());

        $post = fn (array $figures) => $this->postJson('/api/lle-results/passing-rates', ['scope' => LlePassingRate::SCOPE_NATIONAL, 'exam_year' => 2025, ...$figures]);

        $post(['passers' => 10])->assertStatus(422)->assertJsonPath('errors.takers.0', LlePassingRateTrend::BOTH_COUNTS_TAKERS);
        $post(['takers' => 10])->assertStatus(422)->assertJsonPath('errors.passers.0', LlePassingRateTrend::BOTH_COUNTS_PASSERS);
        $post(['passers' => 11, 'takers' => 10])->assertStatus(422)->assertJsonPath('errors.passers.0', LlePassingRateTrend::MORE_PASSERS_THAN_TAKERS);
        $post([])->assertStatus(422)->assertJsonPath('errors.passing_rate.0', LlePassingRateTrend::RATE_NEEDED);
        $post(['passing_rate' => 120])->assertStatus(422)->assertJsonValidationErrors(['passing_rate']);
        $post(['passers' => 0, 'takers' => 0])->assertStatus(422)->assertJsonPath('errors.takers.0', 'At least one person must have taken it.');

        $nextYear = LleResultRules::today()->year + 1;
        $this->postJson('/api/lle-results/passing-rates', ['scope' => LlePassingRate::SCOPE_NATIONAL, 'exam_year' => $nextYear, 'passing_rate' => 40])
            ->assertStatus(422)
            ->assertJsonPath('errors.exam_year.0', sprintf('The exam year cannot be later than this year (%d).', $nextYear - 1));

        $this->postJson('/api/lle-results/passing-rates', ['scope' => 'regional', 'exam_year' => 2025, 'passing_rate' => 40])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['scope']);

        // None passed is a real result.
        $post(['passers' => 0, 'takers' => 3])->assertCreated();
        $this->assertSame(0.0, LlePassingRate::query()->first()->passing_rate);
    }

    public function test_saving_a_year_again_replaces_its_figures_and_both_are_logged(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $this->postJson('/api/lle-results/passing-rates', ['scope' => LlePassingRate::SCOPE_NATIONAL, 'exam_year' => 2023, 'passing_rate' => 38.2])
            ->assertCreated();
        $this->postJson('/api/lle-results/passing-rates', ['scope' => LlePassingRate::SCOPE_NATIONAL, 'exam_year' => 2023, 'passers' => 400, 'takers' => 1000])
            ->assertOk()
            ->assertJsonPath('years.0.national.passing_rate', 40)
            ->assertJsonPath('years.0.national.recorded_by', $admin->name);

        $this->assertDatabaseCount('lle_passing_rates', 1);

        $logged = AuditLog::query()->where('action', 'lle-result.rate.record')->orderBy('id')->get();
        $this->assertCount(2, $logged);
        $this->assertNull($logged[0]->metadata['replaced']);
        // Equals, not Same: MySQL's JSON type re-sorts an object's keys.
        $this->assertEquals(['passers' => null, 'takers' => null, 'passing_rate' => 38.2], $logged[1]->metadata['replaced']);
        $this->assertSame('Entered the national LLE passing rate for 2023', $logged[1]->description);

        $figure = LlePassingRate::query()->first();
        $this->deleteJson("/api/lle-results/passing-rates/{$figure->id}")
            ->assertOk()
            ->assertJsonPath('years', []);

        $removed = AuditLog::query()->where('action', 'lle-result.rate.delete')->first();
        $this->assertSame(400, $removed->metadata['passers']);
        $this->assertSame('Removed the national LLE passing rate for 2023', $removed->description);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function teacher(): User
    {
        return User::factory()->create(['role' => User::ROLE_STAFF_MASTER_EXAMINER]);
    }

    private function student(): User
    {
        return User::factory()->create(['role' => User::ROLE_STUDENT]);
    }
}
