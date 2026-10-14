<?php

namespace Tests\Unit\QuestionGeneration;

use App\Models\QuestionBankQuestion;
use App\Services\QuestionGeneration\DistractorPool;
use App\Services\QuestionGeneration\LocalReviewQuestionGenerator;
use App\Services\QuestionGeneration\ScenarioTemplateDictionary;
use App\Services\QuestionMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Support\BuildsBlisData;
use Tests\TestCase;

/**
 * Template-based question generation for the Review Bot: DistractorPool picks
 * wrong choices from real answers in other questions, in an order fixed by an
 * MD5 hash so a run can be repeated exactly; ScenarioTemplateDictionary holds
 * the scenario sentences; LocalReviewQuestionGenerator builds up to one
 * variant per Bloom's level from each exam item.
 */
class QuestionGenerationTest extends TestCase
{
    use BuildsBlisData;
    use RefreshDatabase;

    private const SUBJECT = 'Reference Services';

    public function test_every_curated_subject_has_scenario_sentences_with_a_place_for_the_concept(): void
    {
        $subjects = array_keys(app(QuestionMappingService::class)->curatedTopics());

        $this->assertSame($subjects, array_keys(ScenarioTemplateDictionary::TEMPLATES));
        foreach (ScenarioTemplateDictionary::TEMPLATES as $subject => $templates) {
            $this->assertNotEmpty($templates, $subject);
            foreach ($templates as $template) {
                $this->assertStringContainsString('{concept}', $template, $subject);
            }
        }
    }

    public function test_the_same_seed_always_picks_the_same_choices_and_a_new_seed_reorders_them(): void
    {
        $pool = new DistractorPool();
        $candidates = collect(['Almanac', 'Atlas', 'Thesaurus', 'Gazetteer', 'Yearbook', 'Directory']);

        $first = $pool->pick($candidates, 3, 42);

        $this->assertCount(3, $first);
        $this->assertSame($first, $pool->pick($candidates, 3, 42));
        $this->assertNotSame($pool->pick($candidates, 6, 42), $pool->pick($candidates, 6, 43));
        $this->assertCount(2, $pool->pick(collect(['A', 'B']), 3, 1));
    }

    public function test_candidates_come_from_the_topic_and_widen_to_the_subject_when_thin(): void
    {
        $teacher = $this->makeTeacher();
        $topic = $this->makeTopic(self::SUBJECT, 'Ready Reference', $teacher);
        $inTopic = $this->makeBank($teacher, 1, [], ['topic_id' => $topic->id]);
        $this->makeBank($teacher, 2);
        $self = $inTopic->questions->first();

        $pool = new DistractorPool();

        // With the item itself left out the topic has nothing, so the whole
        // subject is used: without the item's own answer, and no repeats.
        $widened = $pool->candidatesForTopic(self::SUBJECT, $topic->id, $self->id, 'Correct answer 1');
        $this->assertContains('Wrong answer 2b', $widened->all());
        $this->assertNotContains('Correct answer 1', $widened->all());
        $this->assertSame($widened->count(), $widened->unique()->count());

        // A topic with enough of its own stays within the topic.
        $own = $pool->candidatesForTopic(self::SUBJECT, $topic->id, 0, 'nothing');
        $this->assertSame(['Correct answer 1', 'Wrong answer 1b', 'Wrong answer 1c', 'Wrong answer 1d'], $own->sort()->values()->all());
    }

    public function test_a_tagged_item_with_enough_neighbours_yields_a_variant_per_level(): void
    {
        $teacher = $this->makeTeacher();
        $topic = $this->makeTopic(self::SUBJECT, 'Ready Reference', $teacher);
        $subtopic = $this->makeSubtopic($topic, 'Quick-Fact Sources', $teacher);
        $bank = $this->makeBank($teacher, 4, [], ['topic_id' => $topic->id, 'subtopic_id' => $subtopic->id, 'explanation' => 'Because.']);

        $generated = (new LocalReviewQuestionGenerator())->generate($bank->questions->take(2)->values(), self::SUBJECT);

        $levels = array_unique(array_column($generated, 'cognitive_level'));
        $this->assertContains('recall', $levels);
        $this->assertGreaterThanOrEqual(2, count($levels));
        foreach ($generated as $item) {
            $this->assertContains($item['source_index'], [0, 1]);
            $this->assertCount(1, array_filter($item['options'], fn ($option) => $option['id'] === $item['correct_option_id']));
        }
    }

    public function test_an_item_with_no_wording_or_no_answer_yields_nothing(): void
    {
        $blank = new QuestionBankQuestion(['question_text' => '  ', 'answer_text' => 'x']);
        $blank->setRelation('options', new \Illuminate\Database\Eloquent\Collection());
        $noAnswer = new QuestionBankQuestion(['question_text' => 'What is MARC?', 'answer_text' => null]);
        $noAnswer->setRelation('options', new \Illuminate\Database\Eloquent\Collection());

        $this->assertSame([], (new LocalReviewQuestionGenerator())->generate(new Collection([$blank, $noAnswer]), self::SUBJECT));
    }

    public function test_an_untagged_item_with_no_neighbours_yields_nothing_it_cannot_support(): void
    {
        $teacher = $this->makeTeacher();
        $lonely = $this->makeBank($teacher, 1, ['subject' => 'Information Technology']);

        $generated = (new LocalReviewQuestionGenerator())->generate($lonely->questions, 'Information Technology');

        // Not enough other answers for three wrong choices, and no topic for
        // the comprehension and analysis templates.
        $this->assertSame([], $generated);
    }
}
