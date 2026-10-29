<?php

namespace Tests\Unit\Support;

use App\Support\LleTaxonomy;
use Tests\TestCase;

/**
 * The LLE Table of Specifications as the app reads it (docs/lle_tos.json):
 * the six subjects under their official names, their topics and sub-topics
 * with TOS codes, an "Other / Unclassified" topic per subject, and the older
 * spellings of each subject.
 */
class LleTaxonomyTest extends TestCase
{
    public function test_the_table_has_six_subjects_29_topics_and_154_subtopics(): void
    {
        $this->assertSame(['cataloging', 'indexing', 'it', 'management', 'reference', 'selection'], LleTaxonomy::keys());
        $this->assertSame([
            'Cataloging and Classification',
            'Indexing and Abstracting',
            'Information Technology',
            'Library Organization and Management',
            'Reference, Bibliography and User Services',
            'Selection and Acquisition',
        ], LleTaxonomy::subjectNames());

        $topics = 0;
        foreach (LleTaxonomy::keys() as $key) {
            $subjectTopics = LleTaxonomy::topics($key);
            // Every subject ends with its "Other / Unclassified", which has no sub-topics.
            $this->assertSame(LleTaxonomy::OTHER_TOPIC, array_key_last($subjectTopics), $key);
            $this->assertSame([], end($subjectTopics)['subtopics']);
            $this->assertTrue(end($subjectTopics)['other']);
            $topics += count($subjectTopics) - 1;
        }

        $this->assertSame(29, $topics);
        $this->assertSame(154, LleTaxonomy::subtopicCount());
    }

    public function test_topics_and_subtopics_are_named_by_code_and_name(): void
    {
        $topic = LleTaxonomy::topic('Cataloging and Classification', '2');

        $this->assertSame('2. Descriptive Cataloging', $topic['label']);
        $this->assertSame('Descriptive Cataloging', $topic['name']);
        $this->assertSame(20, $topic['tos_items']);
        $this->assertSame(2, $topic['sort_order']);
        $this->assertSame($topic, LleTaxonomy::topic('Cataloging', 'Descriptive Cataloging'));
        $this->assertSame($topic, LleTaxonomy::topic('cataloguing & classification', '2. Descriptive Cataloging'));

        $subtopic = LleTaxonomy::subtopic('Cataloging and Classification', '2', '2.4');
        $this->assertSame('2.4 Apply the rules of descriptive cataloging as exemplified by the Anglo American Cataloging Rules 2nd ed. 2004 ed. for all types of materials', $subtopic['label']);
        $this->assertSame(5, $subtopic['tos_items']);

        // Children with counts of their own are the sub-topics (Indexing 2.10.x).
        $this->assertSame(1, LleTaxonomy::subtopic('Indexing and Abstracting', '2', '2.10.4')['tos_items']);
        $this->assertNull(LleTaxonomy::subtopic('Indexing and Abstracting', '2', '2.10'));

        $this->assertNull(LleTaxonomy::topic('Cataloging and Classification', 'Not a topic'));
        $this->assertNull(LleTaxonomy::topic('General', '1'));
        $this->assertNull(LleTaxonomy::subtopic('Cataloging and Classification', '2', '3.1'));
    }

    public function test_listed_items_under_one_count_are_keyword_hints(): void
    {
        $this->assertSame(
            ['Digital resources', 'Safety and security controls', 'Licensing', 'Open journal access'],
            LleTaxonomy::subtopic('Information Technology', '6', '6.2')['keyword_hints'],
        );
        $this->assertSame([], LleTaxonomy::subtopic('Information Technology', '6', '6.1')['keyword_hints']);
    }

    public function test_older_spellings_of_a_subject_are_read_as_its_official_name(): void
    {
        $cases = [
            'Reference Services' => 'Reference, Bibliography and User Services',
            'reference' => 'Reference, Bibliography and User Services',
            'Reference, Bibliography & User Services' => 'Reference, Bibliography and User Services',
            'Library Management' => 'Library Organization and Management',
            'Library Organisation and Management' => 'Library Organization and Management',
            'IT' => 'Information Technology',
            'Cataloguing & Classification' => 'Cataloging and Classification',
            'acquisition/selection' => 'Selection and Acquisition',
            'Indexing and Abstracting' => 'Indexing and Abstracting',
            // A several-word spelling inside a longer title.
            'Intro to Library Management 2' => 'Library Organization and Management',
        ];

        foreach ($cases as $raw => $official) {
            $this->assertSame($official, LleTaxonomy::canonicalSubject($raw), $raw);
        }

        foreach (['', '  ', 'General', 'Multiple Subjects', 'Archival Studies', 'Politics', 'Time management tips'] as $raw) {
            $this->assertNull(LleTaxonomy::canonicalSubject($raw), $raw);
        }

        $this->assertSame('reference', LleTaxonomy::subjectKey('Reference Services'));
        $this->assertSame('IT', LleTaxonomy::shortName('it'));
    }

    public function test_other_is_recognised_by_name(): void
    {
        $this->assertTrue(LleTaxonomy::isOther('Other / Unclassified'));
        $this->assertFalse(LleTaxonomy::isOther('1. Basic Concepts'));
        $this->assertFalse(LleTaxonomy::isOther(null));
    }
}
