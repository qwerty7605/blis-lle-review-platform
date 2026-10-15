<?php

namespace Tests\Unit\Support;

use App\Models\User;
use App\Support\Search;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Search::whereAnyContains matches a term inside any of several columns, with
 * % and _ taken literally rather than as SQL wildcards.
 */
class SearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_wildcard_characters_are_escaped(): void
    {
        $this->assertSame('50\\% off', Search::escapeLike('50% off'));
        $this->assertSame('a\\_b', Search::escapeLike('a_b'));
        $this->assertSame('back\\\\slash', Search::escapeLike('back\\slash'));
        $this->assertSame('plain', Search::escapeLike('plain'));
    }

    public function test_a_term_matches_in_any_of_the_columns(): void
    {
        $byName = User::factory()->create(['name' => 'Ana Cruz', 'email' => 'one@lnu.edu.ph']);
        $byEmail = User::factory()->create(['name' => 'Someone', 'email' => 'cruz.b@lnu.edu.ph']);
        User::factory()->create(['name' => 'Nobody', 'email' => 'none@lnu.edu.ph']);

        $ids = Search::whereAnyContains(User::query(), ['name', 'email'], 'cruz')->pluck('id')->sort()->values()->all();

        $this->assertSame([$byName->id, $byEmail->id], $ids);
    }

    public function test_a_percent_sign_only_matches_a_real_percent_sign(): void
    {
        $literal = User::factory()->create(['name' => '100% Ready']);
        User::factory()->create(['name' => '100 Ready']);

        $ids = Search::whereAnyContains(User::query(), ['name'], '100%')->pluck('id')->all();

        $this->assertSame([$literal->id], $ids);
    }
}
