<?php

namespace Tests\Unit\MachineLearning;

use App\Services\MachineLearning\JavaRandom;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * JavaRandom has to deal cross-validation folds exactly as Weka does, which
 * means drawing exactly what Java's java.util.Random draws. The expected
 * numbers were produced by java.util.Random on Temurin JDK 17.
 */
class JavaRandomTest extends TestCase
{
    /** @return array<string, array{int, int, list<int>}> */
    public static function javaSequences(): array
    {
        return [
            'seed 1, bound 10' => [1, 10, [5, 8, 7, 3, 4, 4, 4, 6]],
            'seed 1, bound 16 (a power of two)' => [1, 16, [11, 1, 6, 6, 3, 0, 5, 10]],
            'seed 42, bound 100' => [42, 100, [30, 63, 48, 84, 70, 25, 5, 18]],
            'seed 7, bound 3' => [7, 3, [1, 2, 0, 1, 1, 1, 1, 2]],
            'seed 123456789, bound 1000' => [123456789, 1000, [965, 600, 483, 344, 290, 554, 533, 807]],
            'seed 0, bound 7' => [0, 7, [5, 2, 4, 2, 4, 0, 2, 1]],
        ];
    }

    /** @param  list<int>  $expected */
    #[DataProvider('javaSequences')]
    public function test_it_draws_the_same_numbers_as_java(int $seed, int $bound, array $expected): void
    {
        $random = new JavaRandom($seed);

        $drawn = [];
        for ($i = 0; $i < count($expected); $i++) {
            $drawn[] = $random->nextInt($bound);
        }

        $this->assertSame($expected, $drawn);
    }

    public function test_every_draw_stays_inside_the_bound(): void
    {
        $random = new JavaRandom(99);

        for ($i = 0; $i < 1000; $i++) {
            $value = $random->nextInt(13);
            $this->assertGreaterThanOrEqual(0, $value);
            $this->assertLessThan(13, $value);
        }
    }
}
