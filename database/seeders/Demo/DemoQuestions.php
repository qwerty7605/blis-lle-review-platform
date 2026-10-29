<?php

namespace Database\Seeders\Demo;

/**
 * Exam items for the demo Library, each tagged with the topic and sub-topic
 * of the LLE Table of Specifications it tests (docs/lle_tos.json, by TOS
 * code), so every topic- and subtopic-level view has real evidence behind it.
 *
 * Written for this demo in the style of the Librarians' Licensure Examination.
 * For multiple choice, the correct answer is always options[0]; the seeder
 * shuffles the options when it writes them, so the key is spread across A-D.
 * True/false items carry their answer instead.
 */
final class DemoQuestions
{
    /**
     * @return list<array{
     *   topic: string,
     *   subtopic: string,
     *   difficulty: string,
     *   question: string,
     *   options?: list<string>,
     *   type?: string,
     *   answer?: bool
     * }>
     */
    public static function forSubject(string $subject): array
    {
        return self::ITEMS[$subject] ?? [];
    }

    /**
     * Items for a file uploaded under a subject outside the curated list, so
     * the Library has a genuinely unmapped upload to show.
     *
     * @return list<array{difficulty: string, question: string, options: list<string>}>
     */
    public static function archivalStudies(): array
    {
        return [
            [
                'difficulty' => 'medium',
                'question' => 'The principle that the records of one creator must not be intermixed with those of another is called',
                'options' => ['provenance (respect des fonds)', 'original order', 'appraisal', 'accessioning'],
            ],
            [
                'difficulty' => 'medium',
                'question' => 'Keeping records in the arrangement their creator gave them is the principle of',
                'options' => ['original order', 'provenance', 'weeding', 'classification'],
            ],
            [
                'difficulty' => 'easy',
                'question' => 'Deciding whether records have enough lasting value to be kept in an archive is called',
                'options' => ['appraisal', 'arrangement', 'description', 'accessioning'],
            ],
            [
                'difficulty' => 'hard',
                'question' => 'In archival description, the whole body of records created and accumulated by one person or organization is a',
                'options' => ['fonds', 'series', 'file', 'item'],
            ],
        ];
    }

    private const ITEMS = [
        'Cataloging and Classification' => [
            [
                'topic' => '2',
                'subtopic' => '2.4',
                'difficulty' => 'medium',
                'question' => 'Which of the following is a core element in RDA for describing a manifestation?',
                'options' => ['Title proper', 'Summary of content', 'Illustrative content', 'Colour content'],
            ],
            [
                'topic' => '2',
                'subtopic' => '2.2',
                'difficulty' => 'medium',
                'question' => "In the FRBR model, an English translation of Rizal's Noli Me Tangere is an example of which Group 1 entity?",
                'options' => ['Expression', 'Work', 'Manifestation', 'Item'],
            ],
            [
                'topic' => '2',
                'subtopic' => '2.4',
                'difficulty' => 'hard',
                'question' => 'Which RDA element records the general type of device needed to view, play or run a resource, such as "unmediated", "computer" or "video"?',
                'options' => ['Media type', 'Content type', 'Carrier type', 'Mode of issuance'],
            ],
            [
                'topic' => '4',
                'subtopic' => '4.3',
                'difficulty' => 'easy',
                'question' => 'In the Dewey Decimal Classification, the 300s are assigned to which main class?',
                'options' => ['Social sciences', 'Language', 'Technology', 'History and geography'],
            ],
            [
                'topic' => '4',
                'subtopic' => '4.6',
                'difficulty' => 'medium',
                'question' => 'Which Library of Congress Classification main class covers bibliography, library science and general information resources?',
                'options' => ['Class Z', 'Class A', 'Class L', 'Class P'],
            ],
            [
                'topic' => '4',
                'subtopic' => '4.1',
                'difficulty' => 'medium',
                'question' => 'Which classification scheme uses auxiliary signs such as the colon (:) to show relationships between subjects?',
                'options' => [
                    'Universal Decimal Classification',
                    'Dewey Decimal Classification',
                    'Library of Congress Classification',
                    'Bliss Bibliographic Classification',
                ],
            ],
            [
                'topic' => '3',
                'subtopic' => '3.2',
                'difficulty' => 'easy',
                'question' => 'In the subject heading "Libraries--Philippines", what kind of subdivision is "Philippines"?',
                'options' => ['Geographic subdivision', 'Topical subdivision', 'Chronological subdivision', 'Form subdivision'],
            ],
            [
                'topic' => '2',
                'subtopic' => '2.7',
                'difficulty' => 'medium',
                'question' => 'In an authority file, a "see" reference leads the catalog user',
                'options' => [
                    'from an unused form of a heading to the authorized heading',
                    'from one authorized heading to a related authorized heading',
                    'from a broader heading to its narrower headings',
                    'from a heading to its scope note',
                ],
            ],
            [
                'topic' => '2',
                'subtopic' => '2.6',
                'difficulty' => 'easy',
                'question' => 'What is the main purpose of name authority control in a library catalog?',
                'options' => [
                    'To bring together the works of a person or body under one consistent, authorized form of the name',
                    'To limit the number of authors recorded for each title',
                    "To translate authors' names into English",
                    "To arrange books on the shelf by the author's surname",
                ],
            ],
            [
                'topic' => '4',
                'subtopic' => '4.4',
                'difficulty' => 'easy',
                'question' => 'Cutter-Sanborn tables are used to construct which part of a call number?',
                'options' => ['The author (book) number', 'The class number', 'The accession number', 'The volume number'],
            ],
            [
                'topic' => '5',
                'subtopic' => '5.1',
                'difficulty' => 'medium',
                'question' => 'Shelflisting is done mainly to',
                'options' => [
                    'give each item a unique call number and record its place in the shelf order',
                    'count the books borrowed each day',
                    'identify materials that should be weeded',
                    'prepare purchase orders for new titles',
                ],
            ],
            [
                'topic' => '5',
                'subtopic' => '5.3',
                'difficulty' => 'medium',
                'question' => "Shelving fiction alphabetically by the author's surname instead of by class number is an example of",
                'options' => ['an alternative shelving arrangement', 'shelflisting', 'fixed location shelving', 'closed-stack access'],
            ],
            [
                'topic' => '6',
                'subtopic' => '6.2',
                'difficulty' => 'easy',
                'question' => 'In a MARC 21 bibliographic record, which field holds the title statement?',
                'options' => ['245', '100', '260', '650'],
            ],
            [
                'topic' => '6',
                'subtopic' => '6.2',
                'difficulty' => 'medium',
                'question' => 'The MARC 21 008 field is best described as a',
                'options' => [
                    'fixed-length data elements field',
                    'variable data field for subject access',
                    'linking entry field',
                    'local holdings field',
                ],
            ],
            [
                'topic' => '2',
                'subtopic' => '2.6',
                'difficulty' => 'medium',
                'question' => 'Which MARC 21 format records the authorized forms of names, subjects and uniform titles together with their cross-references?',
                'options' => [
                    'MARC 21 Format for Authority Data',
                    'MARC 21 Format for Bibliographic Data',
                    'MARC 21 Format for Holdings Data',
                    'MARC 21 Format for Classification Data',
                ],
            ],
            [
                'topic' => '2',
                'subtopic' => '2.3',
                'difficulty' => 'easy',
                'question' => 'In a catalog record, the statement "2nd ed." belongs to which area of the description?',
                'options' => [
                    'Edition area',
                    'Title and statement of responsibility area',
                    'Publication, distribution, etc. area',
                    'Series area',
                ],
            ],
            [
                'topic' => '2',
                'subtopic' => '2.3',
                'difficulty' => 'easy',
                'question' => 'The statement "xii, 356 pages : illustrations ; 24 cm" is recorded in which part of a catalog record?',
                'options' => ['Physical description', 'Note area', 'Publication statement', 'Series statement'],
            ],
            [
                'topic' => '2',
                'subtopic' => '2.3',
                'difficulty' => 'easy',
                'type' => 'true_false',
                'answer' => false,
                'question' => 'A series statement such as "(Philippine studies series ; no. 5)" is recorded in the physical description area of a catalog record.',
            ],
        ],

        'Indexing and Abstracting' => [
            [
                'topic' => '2',
                'subtopic' => '2.13',
                'difficulty' => 'easy',
                'question' => 'In a thesaurus, the instruction USE leads the indexer',
                'options' => [
                    'from a non-preferred term to the preferred term',
                    'from a broader term to a narrower term',
                    'from a term to its related terms',
                    'from a preferred term to its scope note',
                ],
            ],
            [
                'topic' => '2',
                'subtopic' => '2.13',
                'difficulty' => 'easy',
                'question' => 'In the thesaurus entry for "Academic libraries", the line "BT Libraries" means that "Libraries" is',
                'options' => ['a broader term', 'a narrower term', 'a related term', 'a non-preferred term'],
            ],
            [
                'topic' => '2',
                'subtopic' => '2.12',
                'difficulty' => 'hard',
                'question' => 'Which standard gives guidelines for constructing and managing monolingual controlled vocabularies such as thesauri?',
                'options' => ['ANSI/NISO Z39.19', 'ANSI/NISO Z39.50', 'ISO 2709', 'ISO 690'],
            ],
            [
                'topic' => '1',
                'subtopic' => '1.5',
                'difficulty' => 'easy',
                'question' => "An abstract that reports a study's purpose, methods, results and conclusions, so that it can sometimes stand in for the full text, is",
                'options' => ['an informative abstract', 'an indicative abstract', 'a critical abstract', 'an annotation'],
            ],
            [
                'topic' => '1',
                'subtopic' => '1.5',
                'difficulty' => 'easy',
                'type' => 'true_false',
                'answer' => false,
                'question' => 'An indicative abstract gives the specific findings and conclusions of the document it describes.',
            ],
            [
                'topic' => '1',
                'subtopic' => '1.5',
                'difficulty' => 'medium',
                'question' => "An abstract that includes the abstractor's judgment of a document's quality, validity or significance is",
                'options' => ['a critical abstract', 'an indicative abstract', 'an informative abstract', 'a structured abstract'],
            ],
            [
                'topic' => '2',
                'subtopic' => '2.11',
                'difficulty' => 'hard',
                'question' => 'A search retrieves 40 documents, 30 of which are relevant. The collection holds 60 relevant documents in all. What is the precision of the search?',
                'options' => ['75%', '50%', '67%', '40%'],
            ],
            [
                'topic' => '4',
                'subtopic' => '4.1',
                'difficulty' => 'hard',
                'question' => "In relevance-ranked retrieval, a term's TF-IDF weight in a document is highest when the term",
                'options' => [
                    'occurs often in that document but rarely in the rest of the collection',
                    'occurs in nearly every document in the collection',
                    'is a stop word such as "the" or "of"',
                    'has been truncated with a wildcard',
                ],
            ],
            [
                'topic' => '2',
                'subtopic' => '2.11',
                'difficulty' => 'medium',
                'question' => 'Recall measures the proportion of',
                'options' => [
                    'relevant documents in the collection that the search retrieved',
                    'retrieved documents that are relevant',
                    'irrelevant documents that the search left out',
                    'documents in the collection that were searched',
                ],
            ],
            [
                'topic' => '2',
                'subtopic' => '2.10.5',
                'difficulty' => 'medium',
                'question' => 'Who developed the Science Citation Index, the foundation of modern citation indexing?',
                'options' => ['Eugene Garfield', 'S. R. Ranganathan', 'Hans Peter Luhn', 'Calvin Mooers'],
            ],
            [
                'topic' => '2',
                'subtopic' => '2.10.5',
                'difficulty' => 'hard',
                'question' => 'Two papers that both cite the same earlier paper are linked by',
                'options' => ['bibliographic coupling', 'co-citation', 'self-citation', 'citation chaining'],
            ],
            [
                'topic' => '2',
                'subtopic' => '2.10.5',
                'difficulty' => 'hard',
                'question' => 'Co-citation occurs when',
                'options' => [
                    'two documents are cited together by a later document',
                    'two documents cite the same earlier document',
                    'an author cites his or her own earlier work',
                    'a journal cites articles it published itself',
                ],
            ],
            [
                'topic' => '2',
                'subtopic' => '2.8',
                'difficulty' => 'medium',
                'question' => "Assigning enough index terms to represent the minor as well as the major topics of a document increases the indexing's",
                'options' => ['exhaustivity', 'specificity', 'consistency', 'precision'],
            ],
            [
                'topic' => '2',
                'subtopic' => '2.8',
                'difficulty' => 'medium',
                'question' => 'Indexing an article about the Philippine eagle under "Philippine eagle" rather than "Birds" applies the principle of',
                'options' => ['specificity', 'exhaustivity', 'consistency', 'pre-coordination'],
            ],
            [
                'topic' => '2',
                'subtopic' => '2.8',
                'difficulty' => 'medium',
                'question' => 'The degree to which different indexers assign the same terms to the same document is called',
                'options' => ['inter-indexer consistency', 'exhaustivity', 'specificity', 'recall'],
            ],
        ],

        'Information Technology' => [
            [
                'topic' => '3',
                'subtopic' => '3.1.3',
                'difficulty' => 'easy',
                'question' => 'Which OPAC feature lets a user narrow a result list by format, language or publication year after the first search?',
                'options' => ['Faceted navigation', 'Authority control', 'Stemming', 'Relevance ranking'],
            ],
            [
                'topic' => '3',
                'subtopic' => '3.1.3',
                'difficulty' => 'medium',
                'question' => 'A discovery layer such as EBSCO Discovery Service, Primo or VuFind is best described as',
                'options' => [
                    'a single search interface over the catalog and licensed e-resources, usually backed by a central index',
                    'the cataloging module of an integrated library system',
                    'software for printing spine labels',
                    'a protocol for exchanging MARC records between libraries',
                ],
            ],
            [
                'topic' => '1',
                'subtopic' => '1.3',
                'difficulty' => 'medium',
                'question' => 'Federated search differs from a central-index discovery service because it',
                'options' => [
                    'sends the query to several databases at search time and merges what they return',
                    "searches only the library's own catalog",
                    'harvests all metadata into one local index in advance',
                    'supports only the Boolean AND operator',
                ],
            ],
            [
                'topic' => '1',
                'subtopic' => '1.3',
                'difficulty' => 'easy',
                'question' => 'Which search statement retrieves the largest number of records?',
                'options' => ['libraries OR archives', 'libraries AND archives', 'libraries NOT archives', '"libraries and archives"'],
            ],
            [
                'topic' => '1',
                'subtopic' => '1.3',
                'difficulty' => 'easy',
                'question' => 'Searching catalog* to retrieve catalog, catalogs, cataloging and cataloger is an example of',
                'options' => ['truncation', 'nesting', 'proximity searching', 'field searching'],
            ],
            [
                'topic' => '1',
                'subtopic' => '1.3',
                'difficulty' => 'medium',
                'question' => 'In the query (school OR academic) AND libraries, the parentheses',
                'options' => [
                    'make the OR operation run before the AND',
                    'search the enclosed words as an exact phrase',
                    'exclude records containing those words',
                    'truncate the enclosed words',
                ],
            ],
            [
                'topic' => '3',
                'subtopic' => '3.1.3',
                'difficulty' => 'easy',
                'question' => 'How many elements make up the Dublin Core Metadata Element Set (Simple Dublin Core)?',
                'options' => ['15', '10', '12', '25'],
            ],
            [
                'topic' => '3',
                'subtopic' => '3.1.3',
                'difficulty' => 'medium',
                'question' => 'In a MARC 21 to Dublin Core crosswalk, the 245 (Title Statement) field maps to which Dublin Core element?',
                'options' => ['Title', 'Creator', 'Description', 'Identifier'],
            ],
            [
                'topic' => '3',
                'subtopic' => '3.1.3',
                'difficulty' => 'medium',
                'question' => 'Z39.50 is a client-server protocol used mainly for',
                'options' => [
                    'searching and retrieving records from remote library catalogs',
                    'encrypting patron data',
                    'programming RFID tags',
                    'processing interlibrary loan payments',
                ],
            ],
            [
                'topic' => '3',
                'subtopic' => '3.1.1',
                'difficulty' => 'easy',
                'question' => 'Which ILS module handles ordering, receiving and invoicing of library materials?',
                'options' => ['Acquisitions', 'Circulation', 'OPAC', 'Cataloging'],
            ],
            [
                'topic' => '3',
                'subtopic' => '3.1.2',
                'difficulty' => 'medium',
                'question' => 'In an integrated library system, a newly cataloged item can be lent out right away because the cataloging and circulation modules',
                'options' => [
                    'share one central database',
                    'use separate barcode ranges',
                    'run on separate servers',
                    'exchange data through a daily manual export',
                ],
            ],
            [
                'topic' => '3',
                'subtopic' => '3.1.3',
                'difficulty' => 'easy',
                'question' => 'Which of the following is an open-source integrated library system widely used by Philippine libraries?',
                'options' => ['Koha', 'Microsoft Access', 'Zotero', 'Adobe Acrobat'],
            ],
            [
                'topic' => '6',
                'subtopic' => '6.2',
                'difficulty' => 'easy',
                'question' => "Asking for a password and then a one-time code sent to the user's phone before granting access is an example of",
                'options' => ['multi-factor authentication', 'single sign-on', 'encryption at rest', 'role-based access control'],
            ],
            [
                'topic' => '6',
                'subtopic' => '6.2',
                'difficulty' => 'easy',
                'question' => 'A staff member gets an email that appears to come from the ILS vendor, asking them to confirm their admin password through a link. This is most likely',
                'options' => ['a phishing attempt', 'a routine software update', 'a denial-of-service attack', 'a firewall alert'],
            ],
            [
                'topic' => '6',
                'subtopic' => '6.2',
                'difficulty' => 'medium',
                'question' => 'The 3-2-1 backup rule recommends keeping',
                'options' => [
                    'three copies of the data, on two different media, with one copy off-site',
                    'three backups a day for two weeks, deleting them after one month',
                    'three servers, two of them online and one offline',
                    'three passwords, changed every two months, with one of them shared',
                ],
            ],
            [
                'topic' => '3',
                'subtopic' => '3.1.2',
                'difficulty' => 'easy',
                'type' => 'true_false',
                'answer' => false,
                'question' => "Like barcodes, RFID tags must be in the reader's direct line of sight to be read.",
            ],
            [
                'topic' => '3',
                'subtopic' => '3.1.2',
                'difficulty' => 'hard',
                'question' => 'Which protocol do self-check kiosks commonly use to communicate with the ILS for checkouts and returns?',
                'options' => ['SIP2', 'OAI-PMH', 'Z39.50', 'FTP'],
            ],
            [
                'topic' => '3',
                'subtopic' => '3.1.5',
                'difficulty' => 'easy',
                'question' => 'Scanning the shelves with a handheld RFID reader and comparing the results with the ILS holdings is done mainly for',
                'options' => ['inventory', 'cataloging', 'acquisitions', 'reference service'],
            ],
        ],

        'Reference, Bibliography and User Services' => [
            [
                'topic' => '3',
                'subtopic' => '3.3',
                'difficulty' => 'easy',
                'question' => 'Which of these is an open question a librarian might ask during a reference interview?',
                'options' => [
                    '"What would you like to find out about climate change?"',
                    '"Do you need books or journal articles?"',
                    '"Is this for a class assignment?"',
                    '"Do you want sources in English?"',
                ],
            ],
            [
                'topic' => '3',
                'subtopic' => '3.3',
                'difficulty' => 'easy',
                'question' => 'A student asks for "something on Rizal." What should the librarian do next?',
                'options' => [
                    'Ask about the aspect, purpose and level of information the student needs',
                    'Hand over the first Rizal biography on the shelf',
                    'Refer the student to the circulation desk',
                    'Tell the student to search the internet',
                ],
            ],
            [
                'topic' => '3',
                'subtopic' => '3.3',
                'difficulty' => 'medium',
                'question' => 'Under the RUSA guidelines for reference service, which behavior best shows approachability?',
                'options' => [
                    'Making eye contact and acknowledging the patron promptly',
                    'Continuing to type while the patron waits',
                    'Staying away from the service desk',
                    'Accepting only questions submitted in writing',
                ],
            ],
            [
                'topic' => '2',
                'subtopic' => '2.4',
                'difficulty' => 'easy',
                'question' => 'Which source is best for quickly finding the population of a Philippine province?',
                'options' => ['A statistical yearbook or almanac', 'A thesaurus', 'A biographical dictionary', 'A concordance'],
            ],
            [
                'topic' => '3',
                'subtopic' => '3.4',
                'difficulty' => 'medium',
                'question' => 'Directing a patron to another library, agency or expert that can better answer the question is called',
                'options' => ['referral service', 'interlibrary loan', "reader's advisory", 'bibliographic instruction'],
            ],
            [
                'topic' => '3',
                'subtopic' => '3.4',
                'difficulty' => 'medium',
                'question' => 'A patron asks a legal question that calls for professional advice. What is the most appropriate response?',
                'options' => [
                    'Provide authoritative legal sources and refer the patron to a lawyer or legal aid office',
                    'Give the patron a personal legal opinion',
                    'Decline to help with the question',
                    'Tell the patron to search online',
                ],
            ],
            [
                'topic' => '2',
                'subtopic' => '2.3',
                'difficulty' => 'easy',
                'question' => 'In the CRAAP test, the criterion that asks when the information was published or last updated is',
                'options' => ['Currency', 'Relevance', 'Authority', 'Purpose'],
            ],
            [
                'topic' => '1',
                'subtopic' => '1.3',
                'difficulty' => 'medium',
                'question' => 'Teaching students to break a topic into key concepts and list synonyms before searching mainly builds their',
                'options' => ['search strategy skills', 'cataloging skills', 'circulation skills', 'book selection skills'],
            ],
            [
                'topic' => '1',
                'subtopic' => '1.3',
                'difficulty' => 'easy',
                'question' => 'Which citation style do research papers in psychology and education most commonly follow?',
                'options' => ['APA', 'MLA', 'IEEE', 'Chicago notes and bibliography'],
            ],
            [
                'topic' => '3',
                'subtopic' => '3.5',
                'difficulty' => 'medium',
                'question' => 'Selective Dissemination of Information (SDI) is a service that',
                'options' => [
                    "regularly sends each user new information matching that user's interest profile",
                    'lends materials to other libraries',
                    'removes outdated materials from the collection',
                    'answers quick-fact questions at the desk',
                ],
            ],
            [
                'topic' => '3',
                'subtopic' => '3.5',
                'difficulty' => 'easy',
                'question' => 'A monthly list of newly acquired books sent to the faculty is an example of',
                'options' => ['a current awareness service', 'retrospective searching', "reader's advisory", 'interlibrary loan'],
            ],
            [
                'topic' => '3',
                'subtopic' => '3.5',
                'difficulty' => 'medium',
                'question' => 'A researcher wants to be notified automatically whenever a new issue of a journal comes out. What should the librarian recommend?',
                'options' => [
                    "Subscribing to the journal's table-of-contents (TOC) or RSS alert",
                    'Checking a union catalog every week',
                    'Consulting a subject heading list',
                    'Browsing the classification schedules',
                ],
            ],
            [
                'topic' => '1',
                'subtopic' => '1.3',
                'difficulty' => 'medium',
                'question' => "In reader's advisory, qualities such as pacing, tone, characterization and storyline are called",
                'options' => ['appeal factors', 'access points', 'subject headings', 'call number elements'],
            ],
            [
                'topic' => '1',
                'subtopic' => '1.3',
                'difficulty' => 'easy',
                'question' => 'A patron who enjoyed a novel asks for "another book just like it." Suggesting titles with similar appeal is called',
                'options' => ['recommending read-alikes', 'writing a book review', 'bibliographic instruction', 'interlibrary loan'],
            ],
            [
                'topic' => '1',
                'subtopic' => '1.3',
                'difficulty' => 'easy',
                'type' => 'true_false',
                'answer' => false,
                'question' => 'A book talk should reveal how the story ends so listeners can decide whether to read it.',
            ],
        ],

        'Library Organization and Management' => [
            [
                'topic' => '1',
                'subtopic' => '1.3',
                'difficulty' => 'easy',
                'question' => "Which document sets a library's long-term direction, usually over three to five years, with its goals and strategies?",
                'options' => ['Strategic plan', 'Annual accomplishment report', 'Daily work schedule', 'Accession record'],
            ],
            [
                'topic' => '3',
                'subtopic' => '3.5',
                'difficulty' => 'easy',
                'question' => 'A written collection development policy mainly serves to',
                'options' => [
                    'guide consistent decisions on selecting, acquiring and weeding materials',
                    'set the salaries of library staff',
                    "describe the library's floor plan",
                    'list the fines for overdue books',
                ],
            ],
            [
                'topic' => '1',
                'subtopic' => '1.3',
                'difficulty' => 'easy',
                'type' => 'true_false',
                'answer' => true,
                'question' => 'A vision statement describes what the library hopes to become, while a mission statement describes its present purpose.',
            ],
            [
                'topic' => '3',
                'subtopic' => '3.2',
                'difficulty' => 'easy',
                'question' => 'A budget that lists expenditures by category, such as salaries, books, supplies and equipment, is a',
                'options' => ['line-item budget', 'program budget', 'zero-based budget', 'performance budget'],
            ],
            [
                'topic' => '3',
                'subtopic' => '3.2',
                'difficulty' => 'medium',
                'question' => 'Before subscribing to a new database, the library weighs its subscription fee against its expected use and value to users. This is',
                'options' => ['a cost-benefit analysis', 'zero-based budgeting', 'a SWOT analysis', 'an inventory'],
            ],
            [
                'topic' => '3',
                'subtopic' => '3.2',
                'difficulty' => 'easy',
                'question' => 'A library that submits a project proposal to an outside funding agency to support a digitization project is engaged in',
                'options' => ['grant seeking', 'line-item budgeting', 'encumbrance', 'depreciation'],
            ],
            [
                'topic' => '3',
                'subtopic' => '3.1',
                'difficulty' => 'easy',
                'question' => 'Orientation for new employees and in-service training for current staff are forms of',
                'options' => ['staff development', 'collection development', 'performance appraisal', 'recruitment'],
            ],
            [
                'topic' => '3',
                'subtopic' => '3.1',
                'difficulty' => 'easy',
                'question' => "The systematic evaluation of an employee's work against set standards is called",
                'options' => ['performance appraisal', 'job analysis', 'delegation', 'orientation'],
            ],
            [
                'topic' => '1',
                'subtopic' => '1.4',
                'difficulty' => 'hard',
                'question' => "In Herzberg's two-factor theory, which of the following is a motivator rather than a hygiene factor?",
                'options' => ['Recognition for achievement', 'Salary', 'Working conditions', 'Company policy'],
            ],
            [
                'topic' => '3',
                'subtopic' => '3.7',
                'difficulty' => 'medium',
                'question' => 'Which of the following is an output measure of library performance?',
                'options' => [
                    'Number of items circulated in a year',
                    "Size of the library's budget",
                    'Number of staff employed',
                    'Floor area of the library',
                ],
            ],
            [
                'topic' => '1',
                'subtopic' => '1.3',
                'difficulty' => 'medium',
                'question' => 'In a SWOT analysis, a new government grant program that the library could apply for is',
                'options' => ['an opportunity', 'a strength', 'a weakness', 'a threat'],
            ],
            [
                'topic' => '3',
                'subtopic' => '3.7',
                'difficulty' => 'medium',
                'question' => 'LibQUAL+ is a tool libraries use to',
                'options' => [
                    "measure users' perceptions of service quality",
                    'catalog electronic resources',
                    'manage RFID inventory',
                    'compute acquisition budgets',
                ],
            ],
            [
                'topic' => '3',
                'subtopic' => '3.5',
                'difficulty' => 'easy',
                'question' => 'During an emergency such as a fire or an earthquake, the first priority of a library disaster plan is',
                'options' => ['the safety of people', 'saving the rare books', 'protecting the computers', 'securing the circulation records'],
            ],
            [
                'topic' => '3',
                'subtopic' => '3.5',
                'difficulty' => 'medium',
                'question' => 'After a flood, wet books that cannot be dried within 48 hours should be',
                'options' => [
                    'frozen to stop mold growth until they can be dried properly',
                    'laid out in direct sunlight',
                    'packed tightly in closed boxes',
                    'shelved at once to free up space',
                ],
            ],
            [
                'topic' => '3',
                'subtopic' => '3.5',
                'difficulty' => 'medium',
                'question' => 'A plan that keeps essential services running, such as moving services online while the building is closed after a typhoon, is a',
                'options' => ['business continuity plan', 'collection development policy', 'weeding plan', 'strategic budget'],
            ],
        ],

        'Selection and Acquisition' => [
            [
                'topic' => '1',
                'subtopic' => '1.1',
                'difficulty' => 'easy',
                'question' => "Before building the collection of a new branch library, the librarian studies the community's demographics, interests and information needs. This is",
                'options' => ['a community analysis', 'weeding', 'bibliographic verification', 'encumbrance'],
            ],
            [
                'topic' => '1',
                'subtopic' => '1.2',
                'difficulty' => 'medium',
                'question' => 'Which of the following is a selection aid librarians use to evaluate new books?',
                'options' => [
                    'Choice: Current Reviews for Academic Libraries',
                    'MARC 21 Format for Holdings Data',
                    'Cutter-Sanborn Three-Figure Author Table',
                    'Sears List of Subject Headings',
                ],
            ],
            [
                'topic' => '3',
                'subtopic' => '3.2',
                'difficulty' => 'hard',
                'question' => 'In the CREW method of weeding, MUSTIE stands for Misleading, Ugly, Superseded, Trivial, Irrelevant and',
                'options' => ['obtainable Elsewhere', 'Expensive', 'Expired', 'Excessive'],
            ],
            [
                'topic' => '2',
                'subtopic' => '2.1',
                'difficulty' => 'easy',
                'question' => 'Two universities regularly send each other their institutional journals without payment. They are acquiring materials through',
                'options' => ['exchange', 'purchase', 'an approval plan', 'a standing order'],
            ],
            [
                'topic' => '2',
                'subtopic' => '2.1',
                'difficulty' => 'medium',
                'type' => 'true_false',
                'answer' => true,
                'question' => 'Under a standing order, the vendor automatically supplies each new volume of a series as it is published.',
            ],
            [
                'topic' => '2',
                'subtopic' => '2.1',
                'difficulty' => 'medium',
                'question' => 'Under an approval plan, the vendor',
                'options' => [
                    "sends new titles that match the library's profile, and the library may return those it does not want",
                    'supplies only the titles the library ordered one by one',
                    'donates books to the library free of charge',
                    'supplies back issues of periodicals only',
                ],
            ],
            [
                'topic' => '2',
                'subtopic' => '2.2',
                'difficulty' => 'easy',
                'question' => "Which factors matter most when evaluating a book vendor's performance?",
                'options' => [
                    'Fulfillment rate, delivery speed and order accuracy',
                    "The location of the vendor's office",
                    "The design of the vendor's catalogs",
                    'The number of sales agents',
                ],
            ],
            [
                'topic' => '2',
                'subtopic' => '2.2',
                'difficulty' => 'medium',
                'question' => 'Checking the catalog and the on-order file before placing an order is done mainly to',
                'options' => [
                    'avoid duplicate orders and confirm the correct bibliographic details',
                    'assign subject headings',
                    'compute overdue fines',
                    'identify materials for weeding',
                ],
            ],
            [
                'topic' => '2',
                'subtopic' => '2.2',
                'difficulty' => 'easy',
                'question' => 'Asking several suppliers to quote prices for the same list of titles before buying is called',
                'options' => ['canvassing', 'encumbrance', 'weeding', 'accessioning'],
            ],
            [
                'topic' => '1',
                'subtopic' => '1.3',
                'difficulty' => 'easy',
                'question' => 'Donated materials should be added to the collection only if they',
                'options' => [
                    'meet the same selection criteria as purchased materials',
                    'come from a well-known donor',
                    'arrive in large quantities',
                    'are hardbound',
                ],
            ],
            [
                'topic' => '2',
                'subtopic' => '2.1',
                'difficulty' => 'medium',
                'question' => 'The document that transfers ownership of donated materials to the library, including any conditions, is a',
                'options' => ['deed of gift', 'purchase order', 'memorandum receipt', 'accession record'],
            ],
            [
                'topic' => '2',
                'subtopic' => '2.1',
                'difficulty' => 'medium',
                'question' => "Gift items that do not meet the library's selection criteria should be",
                'options' => [
                    'disposed of as the gift policy provides, for example passed on to other libraries or sold',
                    'added to the collection anyway',
                    'returned to the donor without notice',
                    'kept in storage indefinitely',
                ],
            ],
            [
                'topic' => '2',
                'subtopic' => '2.2',
                'difficulty' => 'medium',
                'question' => 'Setting aside funds for items that have been ordered but not yet received and paid for is called',
                'options' => ['encumbrance', 'depreciation', 'liquidation', 'allocation'],
            ],
            [
                'topic' => '2',
                'subtopic' => '2.3',
                'difficulty' => 'medium',
                'question' => 'Dividing the book budget among departments or subjects using a formula based on enrollment, costs and use is called',
                'options' => ['fund allocation', 'encumbrance', 'canvassing', 'weeding'],
            ],
            [
                'topic' => '2',
                'subtopic' => '2.2',
                'difficulty' => 'easy',
                'question' => 'Which document does the library send to a supplier to authorize buying specific items at agreed prices?',
                'options' => ['Purchase order', 'Deed of gift', 'Invoice', 'Delivery receipt'],
            ],
        ],
    ];
}
