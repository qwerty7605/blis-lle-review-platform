<?php

namespace App\Support;

use Illuminate\Support\Str;
use RuntimeException;

/**
 * The LLE Table of Specifications (`docs/lle_tos.json`): the six board
 * subjects, their topics and sub-topics, each with its TOS code and number of
 * board questions. It is the only source of subjects, topics and sub-topics
 * for tagging; nothing else in the app keeps its own list.
 *
 * Names are the official ones. A topic is shown and stored as its code and
 * name ("2. Descriptive Cataloging"), a sub-topic likewise ("2.4 Apply the
 * rules of descriptive cataloging..."), so every screen that names one shows
 * the code before it. The plain code is kept in its own column as well.
 *
 * Every subject also has an "Other / Unclassified" topic with no sub-topics,
 * for a question the keywords cannot place. It counts toward the subject's
 * score but never toward topic or sub-topic mastery.
 */
final class LleTaxonomy
{
    public const PATH = 'docs/lle_tos.json';

    public const OTHER_TOPIC = 'Other / Unclassified';

    /** Where "Other / Unclassified" sorts: after every TOS topic. */
    public const OTHER_SORT_ORDER = 99;

    /**
     * Older or looser spellings of each subject, written the way normalize()
     * leaves them. Uploaded files and older text still use them.
     */
    private const ALIASES = [
        'cataloging' => [
            'cataloging and classification', 'cataloguing and classification', 'cataloging classification',
            'classification and cataloging', 'cataloging', 'cataloguing',
        ],
        'indexing' => [
            'indexing and abstracting', 'indexing abstracting', 'abstracting and indexing', 'indexing',
        ],
        'it' => [
            'information technology', 'it', 'information and communication technology', 'ict',
        ],
        'management' => [
            'library organization and management', 'library organisation and management', 'library management',
            'organization and management', 'management',
        ],
        'reference' => [
            'reference bibliography and user services', 'reference bibliography and information services',
            'reference and bibliography', 'reference services', 'reference service', 'reference',
        ],
        'selection' => [
            'selection and acquisition', 'selection and acquisition of library materials', 'selection acquisition',
            'acquisition and selection', 'acquisition selection', 'selection',
        ],
    ];

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $subjects = null;

    /** @var array<string, string>|null alias => subject key */
    private static ?array $aliasIndex = null;

    /**
     * The six subjects in the file's order, keyed by subject key, each with
     * its topics (and "Other / Unclassified" last) keyed by label.
     *
     * @return array<string, array{key: string, name: string, short_name: string, weight_percent: int, topics: array<string, array<string, mixed>>}>
     */
    public static function subjects(): array
    {
        return self::$subjects ??= self::load();
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::subjects());
    }

    /**
     * The official subject names, in the file's order.
     *
     * @return list<string>
     */
    public static function subjectNames(): array
    {
        return array_values(array_map(static fn (array $subject): string => $subject['name'], self::subjects()));
    }

    public static function subjectName(string $key): ?string
    {
        return self::subjects()[$key]['name'] ?? null;
    }

    public static function shortName(string $key): ?string
    {
        return self::subjects()[$key]['short_name'] ?? null;
    }

    /**
     * The subject key for any spelling of a board subject, or null for
     * anything else ("General", "Multiple Subjects", a typo nobody uses).
     */
    public static function subjectKey(?string $raw): ?string
    {
        $normalized = self::normalize($raw);

        if ($normalized === '') {
            return null;
        }

        $index = self::aliasIndex();

        if (isset($index[$normalized])) {
            return $index[$normalized];
        }

        // "Cataloging and Classification (Midterm)", "LLE Reference Services
        // review": a several-word alias found as whole words inside the text.
        // Single words are left out here: "management" or "it" inside a title
        // is not evidence of the subject.
        foreach ($index as $alias => $key) {
            if (str_contains($alias, ' ') && str_contains(' ' . $normalized . ' ', ' ' . $alias . ' ')) {
                return $key;
            }
        }

        return null;
    }

    /** The official name for any spelling of a board subject, or null. */
    public static function canonicalSubject(?string $raw): ?string
    {
        $key = self::subjectKey($raw);

        return $key === null ? null : self::subjectName($key);
    }

    /**
     * Lowercase, "&" read as "and", punctuation and dashes as spaces, runs of
     * spaces as one. Used to compare subject spellings.
     */
    public static function normalize(?string $raw): string
    {
        return Str::of(Str::ascii((string) $raw))
            ->lower()
            ->replace('&', ' and ')
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->toString();
    }

    /**
     * A subject's topics in TOS order, "Other / Unclassified" last, keyed by
     * label. Empty for anything that is not a board subject.
     *
     * @return array<string, array{code: ?string, name: string, label: string, tos_items: ?int, sort_order: int, other: bool, source_note: ?string, subtopics: array<string, array{code: string, name: string, label: string, tos_items: ?int, sort_order: int, keyword_hints: list<string>}>}>
     */
    public static function topics(?string $subject): array
    {
        $key = self::subjectKey($subject);

        return $key === null ? [] : self::subjects()[$key]['topics'];
    }

    /**
     * A topic by its label, its plain name or its code.
     *
     * @return array<string, mixed>|null
     */
    public static function topic(?string $subject, ?string $topic): ?array
    {
        $wanted = trim((string) $topic);

        if ($wanted === '') {
            return null;
        }

        foreach (self::topics($subject) as $label => $entry) {
            if ($wanted === $label || $wanted === $entry['name'] || ($entry['code'] !== null && $wanted === $entry['code'])) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * A sub-topic of a topic, by its label, plain name or code.
     *
     * @return array<string, mixed>|null
     */
    public static function subtopic(?string $subject, ?string $topic, ?string $subtopic): ?array
    {
        $parent = self::topic($subject, $topic);
        $wanted = trim((string) $subtopic);

        if ($parent === null || $wanted === '') {
            return null;
        }

        foreach ($parent['subtopics'] as $label => $entry) {
            if ($wanted === $label || $wanted === $entry['name'] || $wanted === $entry['code']) {
                return $entry;
            }
        }

        return null;
    }

    /** Whether a topic name is a subject's "Other / Unclassified". */
    public static function isOther(?string $topic): bool
    {
        return trim((string) $topic) === self::OTHER_TOPIC;
    }

    /**
     * Subject name => topic label => sub-topic labels: the shape the Library
     * editor and the classifier's guard read.
     *
     * @return array<string, array<string, list<string>>>
     */
    public static function curated(): array
    {
        $curated = [];

        foreach (self::subjects() as $subject) {
            foreach ($subject['topics'] as $label => $topic) {
                $curated[$subject['name']][$label] = array_keys($topic['subtopics']);
            }
        }

        return $curated;
    }

    /** How many TOS sub-topics there are, across the six subjects. */
    public static function subtopicCount(): int
    {
        return array_sum(array_map(
            static fn (array $subject): int => array_sum(array_map(
                static fn (array $topic): int => count($topic['subtopics']),
                $subject['topics'],
            )),
            self::subjects(),
        ));
    }

    public static function topicLabel(string $code, string $name): string
    {
        return $code . '. ' . $name;
    }

    public static function subtopicLabel(string $code, string $name): string
    {
        return $code . ' ' . $name;
    }

    /** Forget the loaded file (tests that swap it). */
    public static function flush(): void
    {
        self::$subjects = null;
        self::$aliasIndex = null;
    }

    /**
     * @return array<string, string>
     */
    private static function aliasIndex(): array
    {
        if (self::$aliasIndex !== null) {
            return self::$aliasIndex;
        }

        $index = [];

        foreach (self::subjects() as $key => $subject) {
            $index[self::normalize($subject['name'])] = $key;

            foreach (self::ALIASES[$key] ?? [] as $alias) {
                $index[self::normalize($alias)] ??= $key;
            }
        }

        // Longest first, so "library organization and management" is tried
        // before "organization and management" in the whole-words pass.
        uksort($index, static fn (string $left, string $right): int => strlen($right) <=> strlen($left) ?: strcmp($left, $right));

        return self::$aliasIndex = $index;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function load(): array
    {
        $path = base_path(self::PATH);
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (!is_array($data) || !is_array($data['subjects'] ?? null)) {
            throw new RuntimeException('The LLE Table of Specifications is missing or unreadable at ' . self::PATH . '.');
        }

        $subjects = [];

        foreach ($data['subjects'] as $subject) {
            $topics = [];

            foreach (array_values($subject['topics'] ?? []) as $topicIndex => $topic) {
                $subtopics = [];

                foreach (array_values($topic['subtopics'] ?? []) as $subtopicIndex => $subtopic) {
                    $label = self::subtopicLabel((string) $subtopic['code'], (string) $subtopic['name']);
                    $subtopics[$label] = [
                        'code' => (string) $subtopic['code'],
                        'name' => (string) $subtopic['name'],
                        'label' => $label,
                        'tos_items' => isset($subtopic['tos_items']) ? (int) $subtopic['tos_items'] : null,
                        'sort_order' => $subtopicIndex + 1,
                        'keyword_hints' => self::hints($subtopic['keyword_hints'] ?? []),
                    ];
                }

                $label = self::topicLabel((string) $topic['code'], (string) $topic['name']);
                $topics[$label] = [
                    'code' => (string) $topic['code'],
                    'name' => (string) $topic['name'],
                    'label' => $label,
                    'tos_items' => isset($topic['tos_items']) ? (int) $topic['tos_items'] : null,
                    'sort_order' => $topicIndex + 1,
                    'other' => false,
                    'source_note' => $topic['source_note'] ?? null,
                    'subtopics' => $subtopics,
                ];
            }

            $topics[self::OTHER_TOPIC] = [
                'code' => null,
                'name' => self::OTHER_TOPIC,
                'label' => self::OTHER_TOPIC,
                'tos_items' => null,
                'sort_order' => self::OTHER_SORT_ORDER,
                'other' => true,
                'source_note' => null,
                'subtopics' => [],
            ];

            $subjects[(string) $subject['key']] = [
                'key' => (string) $subject['key'],
                'name' => (string) $subject['official_name'],
                'short_name' => (string) ($subject['short_name'] ?? $subject['official_name']),
                'weight_percent' => (int) ($subject['weight_percent'] ?? 0),
                'topics' => $topics,
            ];
        }

        return $subjects;
    }

    /**
     * The file writes "no hints" as [null] and a list as [[...]].
     *
     * @return list<string>
     */
    private static function hints(mixed $raw): array
    {
        $flat = [];

        array_walk_recursive($raw, static function ($value) use (&$flat): void {
            if (is_string($value) && trim($value) !== '') {
                $flat[] = trim($value);
            }
        });

        return $flat;
    }
}
