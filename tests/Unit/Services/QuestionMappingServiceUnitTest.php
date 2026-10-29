<?php

namespace Tests\Unit\Services;

use App\Models\QuestionBank;
use App\Models\QuestionBankQuestion;
use App\Services\QuestionClassification\TopicClassification;
use App\Services\QuestionMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\BuildsBlisData;
use Tests\TestCase;

/**
 * QuestionMappingService, the parts tests/Feature/QuestionMappingServiceTest
 * leaves out: not queueing a set twice, refusing an unknown status, a
 * teacher's own tags, making topics and subtopics from typed names, working
 * out a question's answer from its choices, and keeping a guessed topic to
 * the curated list.
 */
class QuestionMappingServiceUnitTest extends TestCase
{
    use BuildsBlisData;
    use RefreshDatabase;

    private function service(): QuestionMappingService
    {
        return app(QuestionMappingService::class);
    }

    public function test_a_set_already_queued_or_running_is_not_queued_again(): void
    {
        Queue::fake();
        $bank = $this->makeBank($this->makeTeacher(), 2);

        $bank->update(['mapping_status' => QuestionBank::MAPPING_STATUS_QUEUED]);
        $this->assertSame('Item mapping is already queued for this set.', $this->service()->queueBankMapping($bank, 'auto')['message']);

        $bank->update(['mapping_status' => QuestionBank::MAPPING_STATUS_PROCESSING]);
        $result = $this->service()->queueBankMapping($bank, 'auto');
        $this->assertSame('Item mapping is already running for this set.', $result['message']);
        $this->assertSame(0, $result['queued']);

        Queue::assertNothingPushed();
    }

    public function test_queueing_can_be_limited_to_given_questions(): void
    {
        Queue::fake();
        $bank = $this->makeBank($this->makeTeacher(), 3);

        $result = $this->service()->queueBankMapping($bank, 'not-a-mode', $bank->questions->take(1));

        $this->assertSame(1, $result['queued']);
    }

    public function test_an_unknown_mapping_or_tag_status_is_refused(): void
    {
        $bank = $this->makeBank($this->makeTeacher(), 1);

        try {
            $this->service()->markBankMappingStatus($bank, 'sideways');
            $this->fail('An unknown mapping status was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Invalid mapping status.', $exception->getMessage());
        }

        $this->expectExceptionMessage('Invalid tag status.');
        $this->service()->updateTagStatus($bank->questions->first(), 'maybe');
    }

    public function test_only_untagged_exam_questions_are_classified(): void
    {
        $bank = $this->makeBank($this->makeTeacher(), 1, [], ['topic_id' => $this->makeTopic()->id]);

        $this->assertSame(['updated' => 0, 'errors' => []], $this->service()->classifyForAssessmentQuestions($bank->questions, 'auto'));
    }

    public function test_a_teacher_s_own_tags_are_saved_and_confirmed(): void
    {
        $teacher = $this->makeTeacher();
        $question = $this->makeBank($teacher, 1)->questions->first();

        // A topic may be named by its code and name, its plain name or its code.
        $tagged = $this->service()->applyManualTags($question, 'Reference and Information Sources', 'hard', 'analysis', $teacher->id, '2.2');

        $this->assertSame('2. Reference and Information Sources', $tagged->topic->name);
        $this->assertSame('2', $tagged->topic->code);
        $this->assertSame(30, $tagged->topic->tos_items);
        $this->assertSame('2.2 Identify / classify the different types of reference sources', $tagged->subtopic->name);
        $this->assertSame('2.2', $tagged->subtopic->code);
        $this->assertSame(10, $tagged->subtopic->tos_items);
        $this->assertSame('hard', $tagged->difficulty);
        $this->assertSame('analysis', $tagged->cognitive_level);
        $this->assertSame(QuestionBankQuestion::TAG_STATUS_CONFIRMED, $tagged->tag_status);
        $this->assertSame(QuestionBankQuestion::TAG_SOURCE_FACULTY, $tagged->tag_source);

        // A subtopic alone goes under the topic the question already has;
        // an unknown difficulty or level is ignored.
        $retagged = $this->service()->applyManualTags($tagged, null, 'impossible', 'telepathy', $teacher->id, '2.4 Utilize appropriate reference sources to various information needs');
        $this->assertSame('2.4 Utilize appropriate reference sources to various information needs', $retagged->subtopic->name);
        $this->assertSame('hard', $retagged->difficulty);
        $this->assertSame('analysis', $retagged->cognitive_level);
    }

    public function test_only_the_table_of_specifications_gives_topics_and_subtopics(): void
    {
        $service = $this->service();
        $topic = $service->resolveTopic('Reference Services', '2. Reference and Information Sources');

        // Saved under the official subject name, once.
        $this->assertSame('Reference, Bibliography and User Services', $topic->subject);
        $this->assertSame($topic->id, $service->resolveTopic('Reference, Bibliography and User Services', '2')->id);

        $this->assertNull($service->resolveTopic('', '2. Reference and Information Sources'));
        $this->assertNull($service->resolveTopic('Reference, Bibliography and User Services', '  '));
        $this->assertNull($service->resolveTopic('Reference, Bibliography and User Services', 'Ready Reference'));
        $this->assertNull($service->resolveTopic('General', 'Basic Concepts'));
        $this->assertNull($service->resolveSubtopic($topic, ''));
        // A sub-topic of another topic is not one of this topic's.
        $this->assertNull($service->resolveSubtopic($topic, '3.3 Identify the various methods and techniques involved in a reference interview'));

        // Other / Unclassified is a topic of every subject, with no sub-topics.
        $other = $service->resolveTopic('Cataloging and Classification', 'Other / Unclassified');
        $this->assertNull($other->code);
        $this->assertSame(99, $other->sort_order);
        $this->assertNull($service->resolveSubtopic($other, '1.1'));

        $this->assertNull($service->tagProblem('Reference Services', '2', '2.3'));
        $this->assertNull($service->tagProblem('Reference Services', null, null));
        $this->assertStringContainsString('is not a topic', $service->tagProblem('Reference Services', 'Ready Reference', null));
        $this->assertStringContainsString('is not a sub-topic', $service->tagProblem('Reference Services', '2', '3.3'));
        $this->assertStringContainsString('six board subjects', $service->tagProblem('General', '2', null));
    }

    public function test_the_answer_is_worked_out_from_the_choices(): void
    {
        $service = $this->service();

        $marked = $service->resolveQuestionContent([
            ['text' => 'Almanac', 'is_correct' => false],
            ['text' => 'Atlas', 'is_correct' => true],
        ], null, null);
        $this->assertSame('B', $marked['answer_label']);
        $this->assertSame('Atlas', $marked['answer_text']);

        $unmarked = $service->resolveQuestionContent([['text' => 'Almanac'], ['text' => 'Atlas']], null, null);
        $this->assertNull($unmarked['answer_label']);

        $written = $service->resolveQuestionContent([['text' => '  ']], null, null, 'A list of holdings');
        $this->assertSame(QuestionBankQuestion::TYPE_OPEN_ENDED, $written['question_type']);
        $this->assertSame('A list of holdings', $written['answer_text']);
    }

    public function test_a_guessed_topic_outside_the_curated_list_is_dropped(): void
    {
        $exposed = new class extends QuestionMappingService {
            public function sanitize(TopicClassification $topic, array $allowed): TopicClassification
            {
                return $this->sanitizeLocalTopic($topic, $allowed);
            }
        };
        $allowed = ['Ready Reference' => ['Almanacs']];

        $this->assertNull($exposed->sanitize(new TopicClassification('Invented', null, 0.9), $allowed)->topic);
        $this->assertNull($exposed->sanitize(new TopicClassification(null, null, 0.9), $allowed)->topic);

        $kept = $exposed->sanitize(new TopicClassification('Ready Reference', 'Invented', 0.9), $allowed);
        $this->assertSame('Ready Reference', $kept->topic);
        $this->assertNull($kept->subtopic);
        $this->assertSame(0.9, $kept->confidence);
    }
}
