<?php

namespace App\Services\QuestionClassification;

use App\Support\LleTaxonomy;

/**
 * The keywords that place a question under one of the LLE Table of
 * Specifications' sub-topics (docs/lle_tos.json, App\Support\LleTaxonomy).
 * Written for faculty to read and correct: one block per sub-topic, under
 * its subject and topic, with the TOS wording in a comment above it.
 *
 * Shape: subject key => topic code => sub-topic code =>
 *   'concept'  a short noun phrase for the sub-topic, which the Review Bot
 *              generator puts into its questions in place of the TOS
 *              competency statement ("Identify the basic parts of...");
 *   'keywords' a list of [phrase, weight].
 * A test checks that every subject, topic and sub-topic of the TOS has a
 * block here and that there is nothing here the TOS does not have.
 *
 * A phrase is a concept a question on that competency would contain (an
 * abbreviation, a named scheme, a term of art), never the competency's own
 * verbs ("identify", "apply"). Phrases are matched as whole words after both
 * the phrase and the question are normalized the same way
 * (LocalQuestionClassifier::normalizeSearchText()), so "Z39.50" matches
 * "z39.50" and "work, expression, manifestation" matches the same words with
 * any punctuation between them.
 *
 * Weighting convention:
 *  - full canonical phrase (e.g. "dewey decimal classification")  ~2.5-3.0
 *  - common abbreviation (e.g. "ddc")                              ~2.0-2.5
 *  - looser/partial phrase (e.g. "dewey decimal")                  ~1.5-2.0
 *  - generic adjacent single word, weak tiebreaker                 ~1.0-1.5
 * A question's sub-topic score is the sum of the weights of the phrases it
 * contains, so a long phrase and the shorter ones inside it stack.
 *
 * A question that matches nothing is tagged its subject's
 * "Other / Unclassified" topic.
 */
final class TopicKeywordDictionary
{
    public const KEYWORDS = [
        // ================================================================
        // Cataloging and Classification
        // ================================================================
        'cataloging' => [
            // 1. Basic Concepts
            '1' => [
                // 1.1 Identify the importance of cataloging and classification in information handling
                '1.1' => [
                    'concept' => 'the purpose of cataloging and classification',
                    'keywords' => [
                        ['bibliographic control', 2.5],
                        ['purpose of cataloging', 2.5],
                        ['importance of cataloging', 2.5],
                        ['purpose of classification', 2.5],
                        ['importance of classification', 2.5],
                        ['organization of information', 2.0],
                        ['information organization', 2.0],
                        ['organize the collection', 1.5],
                    ],
                ],
                // 1.2 Describe the different catalogs and their advantages / disadvantages
                '1.2' => [
                    'concept' => 'the physical forms of the catalog',
                    'keywords' => [
                        ['card catalog', 2.5],
                        ['card catalogue', 2.5],
                        ['book catalog', 2.5],
                        ['book catalogue', 2.5],
                        ['sheaf catalog', 3.0],
                        ['sheaf catalogue', 3.0],
                        ['guard book catalog', 3.0],
                        ['microform catalog', 2.5],
                        ['com catalog', 2.5],
                        ['printed catalog', 2.0],
                        ['physical forms of the catalog', 3.0],
                        ['forms of the catalog', 2.5],
                    ],
                ],
                // 1.3 Trace the historical development of cataloging rules
                '1.3' => [
                    'concept' => 'the history of cataloging codes',
                    'keywords' => [
                        ['history of cataloging', 3.0],
                        ['development of cataloging rules', 3.0],
                        ['cataloging code', 2.0],
                        ['cataloguing code', 2.0],
                        ['british museum rules', 3.0],
                        ['91 rules', 2.5],
                        ['ala cataloging rules', 3.0],
                        ['ala rules for author and title entries', 3.0],
                        ['vatican code', 3.0],
                        ['prussian instructions', 3.0],
                        ['paris principles', 3.0],
                        ['aacr1', 2.5],
                        ['1908 code', 2.5],
                    ],
                ],
                // 1.4 List down the personalities involved in the development of cataloging rules
                '1.4' => [
                    'concept' => 'the pioneers of cataloging',
                    'keywords' => [
                        ['antonio panizzi', 3.0],
                        ['panizzi', 2.5],
                        ['charles ammi cutter', 3.0],
                        ['rules for a dictionary catalog', 3.0],
                        ['seymour lubetzky', 3.0],
                        ['lubetzky', 2.5],
                        ['charles coffin jewett', 3.0],
                        ['jewett', 2.5],
                        ['thomas hyde', 2.5],
                        ['eva verona', 3.0],
                        ['classified catalogue code', 2.5],
                        ['father of modern cataloging', 3.0],
                    ],
                ],
                // 1.5 Define operational terms in cataloging and classification
                '1.5' => [
                    'concept' => 'basic cataloging terms',
                    'keywords' => [
                        ['main entry', 2.0],
                        ['added entry', 2.0],
                        ['added entries', 2.0],
                        ['tracing', 1.5],
                        ['accession number', 2.5],
                        ['unit entry', 2.0],
                        ['unit card', 2.0],
                        ['bibliographic record', 1.5],
                        ['catalog entry', 1.5],
                    ],
                ],
                // 1.6 Identify the types of catalog, its parts and functions
                '1.6' => [
                    'concept' => 'the types and functions of the catalog',
                    'keywords' => [
                        ['dictionary catalog', 3.0],
                        ['dictionary catalogue', 3.0],
                        ['divided catalog', 3.0],
                        ['union catalog', 2.5],
                        ['official catalog', 2.5],
                        ['public catalog', 2.0],
                        ['author catalog', 2.5],
                        ['title catalog', 2.5],
                        ['functions of the catalog', 3.0],
                        ['objectives of the catalog', 3.0],
                        ['objects of the catalog', 3.0],
                        ['types of catalog', 3.0],
                    ],
                ],
                // 1.7 Point out the components and functions of the catalog department
                '1.7' => [
                    'concept' => 'the catalog department',
                    'keywords' => [
                        ['catalog department', 3.0],
                        ['cataloging department', 3.0],
                        ['cataloging section', 2.5],
                        ['technical services', 2.0],
                        ['technical processing', 2.0],
                        ['head cataloger', 2.5],
                        ['catalog librarian', 2.0],
                        ['processing section', 2.0],
                    ],
                ],
            ],
            // 2. Descriptive Cataloging
            '2' => [
                // 2.1 Explain the process of descriptive cataloging
                '2.1' => [
                    'concept' => 'the descriptive cataloging process',
                    'keywords' => [
                        ['descriptive cataloging', 2.0],
                        ['descriptive cataloguing', 2.0],
                        ['process of descriptive cataloging', 3.0],
                        ['steps in descriptive cataloging', 3.0],
                        ['chief source of information', 2.5],
                        ['prescribed source', 2.0],
                        ['title page', 1.5],
                        ['transcription', 1.5],
                    ],
                ],
                // 2.2 Illustrate the principles of descriptive cataloging
                '2.2' => [
                    'concept' => 'the principles of descriptive cataloging',
                    'keywords' => [
                        ['principles of descriptive cataloging', 3.0],
                        ['international cataloguing principles', 3.0],
                        ['international cataloging principles', 3.0],
                        ['statement of principles', 2.5],
                        ['principle of representation', 2.5],
                        ['principle of accuracy', 2.5],
                        ['principle of authorship', 2.5],
                        ['convenience of the user', 2.5],
                        ['functional requirements for bibliographic records', 3.0],
                        ['frbr', 2.5],
                        ['library reference model', 3.0],
                        ['work, expression, manifestation', 2.5],
                        ['lrm', 2.0],
                    ],
                ],
                // 2.3 Identify the basic parts of the International Standard Bibliographic Description
                '2.3' => [
                    'concept' => 'the ISBD areas of description',
                    'keywords' => [
                        ['international standard bibliographic description', 3.0],
                        ['isbd', 3.0],
                        ['areas of description', 2.5],
                        ['area of description', 2.5],
                        ['prescribed punctuation', 3.0],
                        ['title and statement of responsibility area', 3.0],
                        ['edition area', 2.5],
                        ['physical description area', 2.5],
                        ['series area', 2.5],
                        ['note area', 2.5],
                        ['standard number area', 2.5],
                        ['material specific details area', 2.5],
                        ['publication distribution', 2.0],
                    ],
                ],
                // 2.4 Apply the rules of descriptive cataloging as exemplified by the Anglo American Cataloging Rules 2nd ed. 2004 ed. for all types of materials
                '2.4' => [
                    'concept' => 'the AACR2 rules of description',
                    'keywords' => [
                        ['anglo-american cataloguing rules', 3.0],
                        ['anglo-american cataloging rules', 3.0],
                        ['anglo american cataloging rules', 3.0],
                        ['aacr2', 3.0],
                        ['aacr', 2.5],
                        ['resource description and access', 2.5],
                        ['rda', 2.0],
                        ['levels of description', 2.5],
                        ['general material designation', 3.0],
                        ['gmd', 2.5],
                        ['title proper', 2.0],
                        ['statement of responsibility', 2.0],
                        ['other title information', 2.5],
                        ['parallel title', 2.5],
                        ['extent of item', 2.5],
                    ],
                ],
                // 2.5 Name the appropriate access points for personal, corporate, geographic, titles and uniform titles
                '2.5' => [
                    'concept' => 'the choice and form of access points',
                    'keywords' => [
                        ['access point', 2.0],
                        ['choice of access points', 3.0],
                        ['personal name heading', 3.0],
                        ['corporate body', 2.5],
                        ['corporate bodies', 2.5],
                        ['corporate heading', 2.5],
                        ['geographic name', 2.5],
                        ['uniform title', 3.0],
                        ['form of heading', 2.5],
                        ['entry under title', 2.5],
                        ['shared responsibility', 2.5],
                        ['mixed responsibility', 2.5],
                        ['joint authorship', 2.0],
                        ['pseudonym', 2.0],
                    ],
                ],
                // 2.6 Design a name authority file
                '2.6' => [
                    'concept' => 'the name authority file',
                    'keywords' => [
                        ['name authority file', 3.0],
                        ['name authority', 2.5],
                        ['authorized form of name', 3.0],
                        ['authority control', 2.0],
                        ['authority record', 2.0],
                        ['authorized heading', 2.0],
                        ['naco', 2.5],
                        ['virtual international authority file', 3.0],
                        ['viaf', 2.5],
                    ],
                ],
                // 2.7 Provide references for descriptive cataloging
                '2.7' => [
                    'concept' => 'cross-references in the catalog',
                    'keywords' => [
                        ['see reference', 2.5],
                        ['see also reference', 2.5],
                        ['cross-reference', 2.5],
                        ['cross reference', 2.5],
                        ['explanatory reference', 3.0],
                        ['name-title reference', 2.5],
                    ],
                ],
            ],
            // 3. Subject Cataloging
            '3' => [
                // 3.1 Relate the principles of subject cataloging
                '3.1' => [
                    'concept' => 'the principles of subject cataloging',
                    'keywords' => [
                        ['subject cataloging', 2.0],
                        ['principles of subject cataloging', 3.0],
                        ['specific entry', 2.5],
                        ['principle of specific entry', 3.0],
                        ['direct entry', 2.0],
                        ['literary warrant', 2.5],
                        ['unique heading', 2.0],
                        ['subject analysis', 2.0],
                    ],
                ],
                // 3.2 Exemplify the rules of subject cataloging
                '3.2' => [
                    'concept' => 'the rules for subject headings and subdivisions',
                    'keywords' => [
                        ['rules of subject cataloging', 3.0],
                        ['form subdivision', 2.5],
                        ['geographic subdivision', 2.5],
                        ['chronological subdivision', 2.5],
                        ['topical subdivision', 2.5],
                        ['free-floating subdivision', 3.0],
                        ['free floating subdivision', 3.0],
                        ['inverted heading', 2.5],
                        ['subdivision', 1.5],
                    ],
                ],
                // 3.3 Identify the forms of subject catalogs
                '3.3' => [
                    'concept' => 'the forms of the subject catalog',
                    'keywords' => [
                        ['classified catalog', 3.0],
                        ['classified catalogue', 3.0],
                        ['alphabetico-classed catalog', 3.0],
                        ['alphabetical subject catalog', 3.0],
                        ['subject catalog', 2.0],
                        ['subject catalogue', 2.0],
                        ['forms of subject catalog', 3.0],
                    ],
                ],
                // 3.4 Use standard lists of subject headings such as the Sears, LCSH, MCSH and others
                '3.4' => [
                    'concept' => 'standard lists of subject headings',
                    'keywords' => [
                        ['sears list of subject headings', 3.0],
                        ['sears list', 2.5],
                        ['sears', 2.0],
                        ['library of congress subject headings', 3.0],
                        ['lcsh', 2.5],
                        ['medical subject headings', 3.0],
                        ['mesh', 2.5],
                        ['mcsh', 2.5],
                        ['subject heading list', 2.0],
                        ['list of subject headings', 2.0],
                    ],
                ],
                // 3.5 Assign appropriate subject headings
                '3.5' => [
                    'concept' => 'assigning subject headings',
                    'keywords' => [
                        ['assign subject headings', 3.0],
                        ['assigning subject headings', 3.0],
                        ['appropriate subject heading', 3.0],
                        ['most specific heading', 2.5],
                        ['subject heading', 1.5],
                    ],
                ],
                // 3.6 Consult subject authority file
                '3.6' => [
                    'concept' => 'the subject authority file',
                    'keywords' => [
                        ['subject authority file', 3.0],
                        ['subject authority', 2.5],
                        ['subject authority record', 3.0],
                        ['authority file', 1.5],
                    ],
                ],
            ],
            // 4. Classification
            '4' => [
                // 4.1 Recite the overview of the different classification systems
                '4.1' => [
                    'concept' => 'library classification systems',
                    'keywords' => [
                        ['classification system', 2.0],
                        ['classification scheme', 1.5],
                        ['colon classification', 3.0],
                        ['ranganathan', 2.5],
                        ['universal decimal classification', 3.0],
                        ['udc', 2.5],
                        ['bliss bibliographic classification', 3.0],
                        ['bliss classification', 3.0],
                        ['expansive classification', 3.0],
                        ['brown s subject classification', 3.0],
                    ],
                ],
                // 4.2 Apply the principles of classification
                '4.2' => [
                    'concept' => 'the principles of book classification',
                    'keywords' => [
                        ['principles of classification', 3.0],
                        ['canons of classification', 3.0],
                        ['enumerative classification', 2.5],
                        ['faceted classification', 2.5],
                        ['facet analysis', 2.5],
                        ['hierarchical classification', 2.5],
                        ['close classification', 2.5],
                        ['broad classification', 2.5],
                        ['pmest', 3.0],
                        ['mnemonics', 2.0],
                        ['hospitality', 2.0],
                        ['notation', 1.5],
                    ],
                ],
                // 4.3 Summarize the historical background and general characteristics of DDC
                '4.3' => [
                    'concept' => 'the Dewey Decimal Classification',
                    'keywords' => [
                        ['dewey decimal classification', 3.0],
                        ['melvil dewey', 3.0],
                        ['ddc', 2.0],
                        ['dewey', 1.5],
                        ['ten main classes', 2.5],
                        ['hundred divisions', 2.5],
                        ['thousand sections', 2.5],
                        ['main classes', 1.5],
                        ['decimal notation', 2.0],
                        ['relative index', 2.0],
                        ['abridged edition', 2.5],
                        ['webdewey', 2.5],
                    ],
                ],
                // 4.4 Use schedules, tables, and cutter table
                '4.4' => [
                    'concept' => 'the DDC tables and the Cutter table',
                    'keywords' => [
                        ['standard subdivision', 3.0],
                        ['auxiliary tables', 2.5],
                        ['area table', 2.5],
                        ['table 1', 2.0],
                        ['table 2', 2.0],
                        ['add to base number', 2.5],
                        ['cutter-sanborn', 3.0],
                        ['cutter sanborn', 3.0],
                        ['cutter table', 2.5],
                        ['cutter number', 2.0],
                        ['author number', 2.5],
                        ['book number', 2.0],
                        ['schedules', 1.5],
                    ],
                ],
                // 4.5 Build the DDC call number
                '4.5' => [
                    'concept' => 'building a DDC call number',
                    'keywords' => [
                        ['ddc call number', 3.0],
                        ['dewey call number', 3.0],
                        ['build the call number', 2.5],
                        ['number building', 2.5],
                        ['call number', 1.5],
                        ['class number', 1.5],
                        ['work mark', 2.5],
                        ['volume number', 1.5],
                        ['copy number', 1.5],
                        ['location symbol', 2.0],
                    ],
                ],
                // 4.6 Paraphrase the historical background and general characteristics of LCC Scheme
                '4.6' => [
                    'concept' => 'the Library of Congress Classification',
                    'keywords' => [
                        ['library of congress classification', 3.0],
                        ['lc classification', 2.5],
                        ['lcc', 2.5],
                        ['herbert putnam', 3.0],
                        ['charles martel', 3.0],
                        ['hanson', 2.0],
                        ['21 main classes', 2.5],
                        ['twenty-one main classes', 2.5],
                        ['mixed notation', 2.0],
                        ['alphanumeric notation', 2.0],
                    ],
                ],
                // 4.7 Use schedules, tables and internal cutter table
                '4.7' => [
                    'concept' => 'the LC schedules and the LC Cutter table',
                    'keywords' => [
                        ['lc schedules', 2.5],
                        ['lcc schedules', 2.5],
                        ['lc cutter table', 3.0],
                        ['internal cutter table', 3.0],
                        ['lc cutter', 2.5],
                        ['lc tables', 2.0],
                        ['double cutter', 2.5],
                        ['classification web', 2.5],
                    ],
                ],
                // 4.8 Build the LCC call number
                '4.8' => [
                    'concept' => 'building an LC call number',
                    'keywords' => [
                        ['lc call number', 3.0],
                        ['lcc call number', 3.0],
                        ['library of congress call number', 3.0],
                        ['lc class number', 2.5],
                        ['cuttering', 2.0],
                    ],
                ],
            ],
            // 5. Shelflisting and Filing Catalog Entries
            '5' => [
                // 5.1 Modify shelf list
                '5.1' => [
                    'concept' => 'the shelf list',
                    'keywords' => [
                        ['shelf list', 3.0],
                        ['shelflist', 3.0],
                        ['shelflisting', 3.0],
                        ['shelf listing', 3.0],
                        ['shelf list card', 3.0],
                        ['inventory record', 1.5],
                    ],
                ],
                // 5.2 File catalog entries using the ALA rules of filing
                '5.2' => [
                    'concept' => 'the ALA filing rules',
                    'keywords' => [
                        ['ala filing rules', 3.0],
                        ['ala rules for filing', 3.0],
                        ['filing rules', 2.5],
                        ['filing order', 2.5],
                        ['word by word', 2.5],
                        ['letter by letter', 2.5],
                        ['nothing before something', 3.0],
                        ['alphabetical arrangement', 2.0],
                        ['alphabetization', 2.0],
                        ['filing', 1.5],
                    ],
                ],
                // 5.3 Shelve books using the DDC or LC Scheme
                '5.3' => [
                    'concept' => 'shelving books in call number order',
                    'keywords' => [
                        ['shelf arrangement', 2.5],
                        ['shelf order', 2.5],
                        ['shelving order', 2.5],
                        ['call number order', 2.5],
                        ['shelf reading', 3.0],
                        ['shelving', 1.5],
                        ['shelve', 1.5],
                    ],
                ],
            ],
            // 6. Trends in Cataloging
            '6' => [
                // 6.1 Operate a computer assisted catalog
                '6.1' => [
                    'concept' => 'the online public access catalog',
                    'keywords' => [
                        ['online public access catalog', 3.0],
                        ['online public access catalogue', 3.0],
                        ['opac', 2.5],
                        ['computer-assisted cataloging', 3.0],
                        ['computer assisted cataloging', 3.0],
                        ['automated catalog', 2.5],
                        ['online catalog', 2.5],
                        ['integrated library system', 1.5],
                    ],
                ],
                // 6.2 Identify the different fields of the MARC records
                '6.2' => [
                    'concept' => 'the fields of a MARC record',
                    'keywords' => [
                        ['machine-readable cataloging', 3.0],
                        ['machine readable cataloging', 3.0],
                        ['marc 21', 2.5],
                        ['marc field', 2.5],
                        ['marc', 2.0],
                        ['subfield', 2.5],
                        ['subfield code', 2.5],
                        ['indicators', 2.0],
                        ['fixed field', 2.5],
                        ['variable field', 2.5],
                        ['leader', 1.5],
                        ['245', 2.0],
                    ],
                ],
                // 6.3 Match the catalog records with the MARC
                '6.3' => [
                    'concept' => 'tagging catalog records in MARC',
                    'keywords' => [
                        ['marc record', 2.5],
                        ['marc tagging', 3.0],
                        ['marc tag', 2.5],
                        ['marc coding', 2.5],
                        ['content designation', 2.5],
                        ['corresponding marc field', 3.0],
                        ['equivalent marc tag', 3.0],
                    ],
                ],
                // 6.4 Describe online cataloging
                '6.4' => [
                    'concept' => 'online and copy cataloging',
                    'keywords' => [
                        ['online cataloging', 3.0],
                        ['copy cataloging', 3.0],
                        ['original cataloging', 2.5],
                        ['shared cataloging', 3.0],
                        ['cooperative cataloging', 3.0],
                        ['cataloging in publication', 3.0],
                        ['cip', 2.0],
                        ['oclc', 2.5],
                        ['worldcat', 2.5],
                    ],
                ],
                // 6.5 Define Dublin Core
                '6.5' => [
                    'concept' => 'Dublin Core',
                    'keywords' => [
                        ['dublin core', 3.0],
                        ['dublin core metadata element set', 3.0],
                        ['qualified dublin core', 3.0],
                        ['dcmi', 2.5],
                        ['15 elements', 2.0],
                        ['fifteen elements', 2.0],
                    ],
                ],
                // 6.6 Define metadata
                '6.6' => [
                    'concept' => 'metadata',
                    'keywords' => [
                        ['metadata', 2.0],
                        ['data about data', 3.0],
                        ['metadata schema', 2.5],
                        ['metadata standards', 2.5],
                        ['descriptive metadata', 2.5],
                        ['structural metadata', 2.5],
                        ['administrative metadata', 2.5],
                        ['mods', 2.5],
                        ['mets', 2.5],
                        ['bibframe', 2.0],
                    ],
                ],
                // 6.7 Illustrate data mining process
                '6.7' => [
                    'concept' => 'data mining',
                    'keywords' => [
                        ['data mining', 3.0],
                        ['knowledge discovery', 2.5],
                        ['data warehouse', 2.0],
                        ['pattern discovery', 2.0],
                        ['association rules', 2.0],
                        ['clustering', 1.5],
                    ],
                ],
                // 6.8 Undertake conversion and migration of records
                '6.8' => [
                    'concept' => 'the conversion and migration of records',
                    'keywords' => [
                        ['retrospective conversion', 3.0],
                        ['recon', 2.5],
                        ['data migration', 3.0],
                        ['record migration', 3.0],
                        ['conversion of records', 3.0],
                        ['data conversion', 2.5],
                        ['metadata crosswalk', 3.0],
                        ['crosswalk', 2.5],
                        ['migration', 1.5],
                        ['conversion', 1.5],
                    ],
                ],
            ],
        ],

        // ================================================================
        // Indexing and Abstracting
        // ================================================================
        'indexing' => [
            // 1. Abstracting
            '1' => [
                // 1.1 Define abstracting
                '1.1' => [
                    'concept' => 'abstracting',
                    'keywords' => [
                        ['definition of an abstract', 3.0],
                        ['define abstracting', 3.0],
                        ['an abstract is', 2.5],
                        ['abstract is defined', 2.5],
                        ['meaning of abstracting', 2.5],
                        ['abstracting', 1.5],
                        ['abstract', 1.0],
                    ],
                ],
                // 1.2 Trace the development of abstracts and abstracting process
                '1.2' => [
                    'concept' => 'the history of abstracting',
                    'keywords' => [
                        ['history of abstracting', 3.0],
                        ['development of abstracts', 3.0],
                        ['development of abstracting', 3.0],
                        ['origin of abstracting', 3.0],
                        ['first abstracting', 2.5],
                        ['journal des scavans', 3.0],
                        ['journal des savants', 3.0],
                    ],
                ],
                // 1.3 Identify the various types of document surrogates
                '1.3' => [
                    'concept' => 'document surrogates',
                    'keywords' => [
                        ['document surrogate', 3.0],
                        ['surrogate', 2.0],
                        ['annotation', 2.5],
                        ['synopsis', 2.5],
                        ['digest', 2.5],
                        ['extract', 2.0],
                        ['summary', 1.5],
                    ],
                ],
                // 1.4 Familiar with the uses of abstracts
                '1.4' => [
                    'concept' => 'the uses of abstracts',
                    'keywords' => [
                        ['uses of abstracts', 3.0],
                        ['use of abstracts', 3.0],
                        ['purpose of an abstract', 3.0],
                        ['purpose of abstracts', 3.0],
                        ['functions of an abstract', 2.5],
                        ['function of abstracts', 2.5],
                        ['save time', 1.5],
                    ],
                ],
                // 1.5 Differentiate the types of abstracts
                '1.5' => [
                    'concept' => 'the types of abstracts',
                    'keywords' => [
                        ['types of abstracts', 3.0],
                        ['informative abstract', 3.0],
                        ['indicative abstract', 3.0],
                        ['indicative-informative', 3.0],
                        ['critical abstract', 3.0],
                        ['evaluative abstract', 3.0],
                        ['structured abstract', 3.0],
                        ['modular abstract', 3.0],
                        ['highlight abstract', 3.0],
                        ['telegraphic abstract', 3.0],
                        ['slanted abstract', 3.0],
                        ['statistical abstract', 2.5],
                        ['mini abstract', 2.5],
                        ['author abstract', 2.5],
                    ],
                ],
                // 1.6 Apply the principles and concepts of abstracting
                '1.6' => [
                    'concept' => 'the principles of abstracting',
                    'keywords' => [
                        ['principles of abstracting', 3.0],
                        ['abstracting concepts', 2.5],
                        ['brevity', 2.0],
                        ['objectivity', 2.0],
                        ['conciseness', 2.0],
                        ['clarity', 1.5],
                    ],
                ],
                // 1.7 Distinguish the parts of an abstract, e.g. reference, body, signature
                '1.7' => [
                    'concept' => 'the parts of an abstract',
                    'keywords' => [
                        ['parts of an abstract', 3.0],
                        ['parts of the abstract', 3.0],
                        ['body of the abstract', 3.0],
                        ['heading of the abstract', 2.5],
                        ['reference section', 2.0],
                        ['bibliographic reference', 2.0],
                        ['signature', 2.0],
                    ],
                ],
                // 1.8 Demonstrate the proper citation formats in abstract, e.g. books, periodicals, electronic
                '1.8' => [
                    'concept' => 'citation formats in abstracts',
                    'keywords' => [
                        ['citation format', 3.0],
                        ['citation style', 2.5],
                        ['bibliographic citation', 2.0],
                        ['apa', 2.0],
                        ['mla', 2.0],
                        ['chicago style', 2.0],
                        ['citing', 1.5],
                    ],
                ],
                // 1.9 Compose an abstract
                '1.9' => [
                    'concept' => 'writing an abstract',
                    'keywords' => [
                        ['compose an abstract', 3.0],
                        ['write an abstract', 3.0],
                        ['writing an abstract', 3.0],
                        ['abstract writing', 3.0],
                        ['abstractor', 2.0],
                        ['abstracter', 2.0],
                        ['topic sentence', 2.0],
                        ['third person', 1.5],
                        ['active voice', 1.5],
                    ],
                ],
                // 1.10 Apply the abstracting principles
                '1.10' => [
                    'concept' => 'the abstracting process',
                    'keywords' => [
                        ['abstracting process', 3.0],
                        ['steps in abstracting', 3.0],
                        ['abstracting procedure', 2.5],
                        ['content analysis', 2.0],
                        ['skimming', 2.0],
                        ['reading the document', 2.0],
                    ],
                ],
                // 1.11 Prepare abstracting policies, procedures and guidelines
                '1.11' => [
                    'concept' => 'abstracting policies and standards',
                    'keywords' => [
                        ['abstracting policy', 3.0],
                        ['abstracting policies', 3.0],
                        ['abstracting guidelines', 3.0],
                        ['abstracting manual', 3.0],
                        ['abstracting standard', 2.5],
                        ['ansi/niso z39.14', 3.0],
                        ['z39.14', 3.0],
                        ['iso 214', 3.0],
                    ],
                ],
                // 1.12 Produce a good abstract
                '1.12' => [
                    'concept' => 'the qualities of a good abstract',
                    'keywords' => [
                        ['good abstract', 3.0],
                        ['qualities of a good abstract', 3.0],
                        ['characteristics of a good abstract', 3.0],
                        ['well-written abstract', 2.5],
                        ['length of an abstract', 2.5],
                        ['word limit', 2.0],
                        ['number of words', 1.5],
                    ],
                ],
            ],
            // 2. Indexing
            '2' => [
                // 2.1 Define indexing
                '2.1' => [
                    'concept' => 'indexing',
                    'keywords' => [
                        ['definition of indexing', 3.0],
                        ['define indexing', 3.0],
                        ['meaning of indexing', 2.5],
                        ['index is defined', 2.5],
                        ['indexing is', 2.0],
                        ['indexing', 1.0],
                    ],
                ],
                // 2.2 Trace the development of indexes and indexing process
                '2.2' => [
                    'concept' => 'the history of indexing',
                    'keywords' => [
                        ['history of indexing', 3.0],
                        ['development of indexes', 3.0],
                        ['development of indexing', 3.0],
                        ['origin of indexing', 3.0],
                        ['earliest index', 2.5],
                        ['concordance', 2.5],
                        ['first index', 2.0],
                    ],
                ],
                // 2.3 Recite the role of indexing in information retrieval
                '2.3' => [
                    'concept' => 'the role of indexing in information retrieval',
                    'keywords' => [
                        ['role of indexing', 3.0],
                        ['indexing and retrieval', 2.5],
                        ['subject access', 2.0],
                        ['access to information', 1.5],
                        ['findability', 2.0],
                    ],
                ],
                // 2.4 Describe information retrieval system
                '2.4' => [
                    'concept' => 'information retrieval systems',
                    'keywords' => [
                        ['information retrieval system', 3.0],
                        ['ir system', 2.5],
                        ['retrieval model', 2.5],
                        ['vector space model', 3.0],
                        ['probabilistic model', 2.5],
                        ['document representation', 2.0],
                        ['information retrieval', 1.5],
                    ],
                ],
                // 2.5 State the purposes and uses of indexing
                '2.5' => [
                    'concept' => 'the purposes of indexing',
                    'keywords' => [
                        ['purpose of indexing', 3.0],
                        ['purposes of indexing', 3.0],
                        ['uses of indexing', 3.0],
                        ['functions of an index', 3.0],
                        ['function of indexing', 2.5],
                        ['purpose of an index', 3.0],
                    ],
                ],
                // 2.6 Identify the type of indexes
                '2.6' => [
                    'concept' => 'the types of indexes',
                    'keywords' => [
                        ['types of indexes', 3.0],
                        ['types of index', 3.0],
                        ['author index', 2.5],
                        ['subject index', 2.5],
                        ['title index', 2.5],
                        ['name index', 2.0],
                        ['cumulative index', 2.5],
                        ['keyword index', 2.0],
                    ],
                ],
                // 2.7 Produce a book, newspaper and periodical index
                '2.7' => [
                    'concept' => 'book, newspaper and periodical indexes',
                    'keywords' => [
                        ['book index', 3.0],
                        ['back-of-the-book index', 3.0],
                        ['back of the book index', 3.0],
                        ['newspaper index', 3.0],
                        ['periodical index', 3.0],
                        ['locator', 2.5],
                        ['page reference', 2.0],
                        ['index entry', 2.0],
                        ['subheading', 2.0],
                        ['main heading', 2.0],
                    ],
                ],
                // 2.8 Use the principles and concepts of indexing such as exhaustivity, specificity and consistency
                '2.8' => [
                    'concept' => 'exhaustivity, specificity and consistency',
                    'keywords' => [
                        ['exhaustivity', 3.0],
                        ['exhaustive indexing', 3.0],
                        ['selective indexing', 2.5],
                        ['specificity', 2.5],
                        ['indexing consistency', 3.0],
                        ['inter-indexer consistency', 3.0],
                        ['depth of indexing', 3.0],
                        ['indexing depth', 3.0],
                        ['consistency', 1.5],
                    ],
                ],
                // 2.9 Explain indexing languages as to purposes, features and types
                '2.9' => [
                    'concept' => 'indexing languages',
                    'keywords' => [
                        ['indexing language', 3.0],
                        ['controlled vocabulary', 2.5],
                        ['uncontrolled vocabulary', 2.5],
                        ['vocabulary control', 2.5],
                        ['natural language', 2.0],
                        ['free text', 2.0],
                        ['artificial language', 2.0],
                    ],
                ],
                // 2.10.1 Indexing systems: Coordinate indexes
                '2.10.1' => [
                    'concept' => 'coordinate indexes',
                    'keywords' => [
                        ['coordinate index', 3.0],
                        ['coordinate indexing', 3.0],
                        ['post-coordinate', 3.0],
                        ['postcoordinate', 3.0],
                        ['pre-coordinate', 2.5],
                        ['precoordinate', 2.5],
                        ['uniterm', 3.0],
                        ['mortimer taube', 3.0],
                        ['optical coincidence', 3.0],
                        ['peek-a-boo', 3.0],
                    ],
                ],
                // 2.10.2 Indexing systems: Classified indexes
                '2.10.2' => [
                    'concept' => 'classified indexes',
                    'keywords' => [
                        ['classified index', 3.0],
                        ['classified indexes', 3.0],
                        ['systematic index', 2.5],
                        ['alphabetico-classed index', 2.5],
                    ],
                ],
                // 2.10.3 Indexing systems: Chain indexes
                '2.10.3' => [
                    'concept' => 'chain indexes',
                    'keywords' => [
                        ['chain index', 3.0],
                        ['chain indexing', 3.0],
                        ['chain procedure', 3.0],
                        ['ranganathan', 1.5],
                    ],
                ],
                // 2.10.4 Indexing systems: Permuted indexes
                '2.10.4' => [
                    'concept' => 'permuted (KWIC) indexes',
                    'keywords' => [
                        ['permuted index', 3.0],
                        ['permuted title', 2.5],
                        ['keyword in context', 3.0],
                        ['kwic', 3.0],
                        ['keyword out of context', 3.0],
                        ['kwoc', 3.0],
                        ['kwac', 3.0],
                        ['rotated index', 2.5],
                        ['permutation', 2.0],
                    ],
                ],
                // 2.10.5 Indexing systems: Citation indexes
                '2.10.5' => [
                    'concept' => 'citation indexes',
                    'keywords' => [
                        ['citation index', 3.0],
                        ['citation indexing', 3.0],
                        ['science citation index', 3.0],
                        ['social sciences citation index', 3.0],
                        ['eugene garfield', 3.0],
                        ['garfield', 2.5],
                        ['web of science', 2.5],
                        ['bibliographic coupling', 3.0],
                        ['co-citation', 3.0],
                        ['citation analysis', 2.5],
                        ['impact factor', 2.5],
                    ],
                ],
                // 2.10.6 Indexing systems: String indexes
                '2.10.6' => [
                    'concept' => 'string indexes such as PRECIS',
                    'keywords' => [
                        ['string index', 3.0],
                        ['string indexing', 3.0],
                        ['precis', 3.0],
                        ['preserved context indexing system', 3.0],
                        ['derek austin', 3.0],
                        ['popsi', 3.0],
                        ['postulate-based permuted subject indexing', 3.0],
                        ['role operators', 2.5],
                    ],
                ],
                // 2.11 Apply the measure of effectiveness of the indexing system, e.g. recall and precision
                '2.11' => [
                    'concept' => 'recall and precision',
                    'keywords' => [
                        ['recall and precision', 3.0],
                        ['precision and recall', 3.0],
                        ['recall ratio', 3.0],
                        ['precision ratio', 3.0],
                        ['recall', 2.0],
                        ['precision', 2.0],
                        ['relevant documents', 2.0],
                        ['false drops', 2.5],
                        ['fallout', 2.5],
                        ['cranfield', 3.0],
                        ['measures of effectiveness', 2.5],
                        ['noise', 1.5],
                    ],
                ],
                // 2.12 Design a thesaurus
                '2.12' => [
                    'concept' => 'thesaurus construction',
                    'keywords' => [
                        ['thesaurus construction', 3.0],
                        ['construct a thesaurus', 3.0],
                        ['constructing a thesaurus', 3.0],
                        ['thesaurus design', 3.0],
                        ['designing a thesaurus', 3.0],
                        ['thesaurus development', 2.5],
                        ['iso 25964', 3.0],
                        ['z39.19', 3.0],
                        ['candidate terms', 2.0],
                        ['term selection', 2.0],
                    ],
                ],
                // 2.13 Describe a thesaurus as to differences and similarities, relationships of terms and basic features
                '2.13' => [
                    'concept' => 'thesaurus relationships',
                    'keywords' => [
                        ['thesaurus', 2.0],
                        ['broader term', 3.0],
                        ['narrower term', 3.0],
                        ['related term', 3.0],
                        ['used for', 2.5],
                        ['use for', 2.5],
                        ['scope note', 2.5],
                        ['preferred term', 2.5],
                        ['non-preferred term', 2.5],
                        ['descriptor', 2.0],
                        ['hierarchical relationship', 2.5],
                        ['associative relationship', 3.0],
                        ['equivalence relationship', 3.0],
                        ['thesaurofacet', 3.0],
                        ['bt', 1.5],
                        ['nt', 1.5],
                        ['rt', 1.5],
                        ['uf', 1.5],
                    ],
                ],
                // 2.14 Apply indexing standards
                '2.14' => [
                    'concept' => 'indexing standards',
                    'keywords' => [
                        ['indexing standard', 3.0],
                        ['standard for indexing', 2.5],
                        ['guidelines for indexes', 2.5],
                        ['iso 999', 3.0],
                        ['iso 5963', 3.0],
                        ['bs 3700', 3.0],
                        ['niso tr02', 3.0],
                    ],
                ],
                // 2.15 Prepare indexing policies, procedures and guidelines
                '2.15' => [
                    'concept' => 'indexing policies and procedures',
                    'keywords' => [
                        ['indexing policy', 3.0],
                        ['indexing policies', 3.0],
                        ['indexing procedures', 3.0],
                        ['indexing guidelines', 3.0],
                        ['indexing manual', 3.0],
                        ['indexing workflow', 2.5],
                    ],
                ],
            ],
            // 3. Application of Abstracting and Indexing Data
            '3' => [
                // 3.1 Prepare samples of primary publications for abstracting and indexing services
                '3.1' => [
                    'concept' => 'primary publications for abstracting and indexing services',
                    'keywords' => [
                        ['primary publication', 3.0],
                        ['secondary publication', 2.5],
                        ['abstracting and indexing service', 2.5],
                        ['conference proceedings', 2.0],
                        ['technical reports', 2.0],
                        ['journal articles', 1.5],
                    ],
                ],
                // 3.2 Prepare samples of database products
                '3.2' => [
                    'concept' => 'abstracting and indexing database products',
                    'keywords' => [
                        ['database product', 3.0],
                        ['database producer', 3.0],
                        ['database vendor', 2.5],
                        ['bibliographic database', 2.5],
                        ['full-text database', 2.5],
                        ['full text database', 2.5],
                        ['cd-rom', 2.5],
                        ['library and information science abstracts', 3.0],
                        ['medline', 2.5],
                        ['eric database', 2.5],
                    ],
                ],
                // 3.3 Prepare online searching application
                '3.3' => [
                    'concept' => 'online searching',
                    'keywords' => [
                        ['online searching', 3.0],
                        ['online search', 2.5],
                        ['search strategy', 2.5],
                        ['search statement', 2.5],
                        ['boolean operators', 2.5],
                        ['boolean', 2.0],
                        ['truncation', 2.5],
                        ['proximity operator', 2.5],
                        ['field searching', 2.0],
                        ['search intermediary', 2.5],
                        ['online search service', 3.0],
                    ],
                ],
                // 3.4 Prepare current awareness services
                '3.4' => [
                    'concept' => 'current awareness services',
                    'keywords' => [
                        ['current awareness', 3.0],
                        ['current awareness service', 3.0],
                        ['current contents', 3.0],
                        ['table of contents service', 2.5],
                        ['alerting service', 2.5],
                        ['new acquisitions list', 2.5],
                        ['accessions list', 2.0],
                    ],
                ],
                // 3.5 Prepare Selective Dissemination of Information and other tools
                '3.5' => [
                    'concept' => 'selective dissemination of information',
                    'keywords' => [
                        ['selective dissemination of information', 3.0],
                        ['sdi', 3.0],
                        ['user profile', 2.5],
                        ['interest profile', 2.5],
                        ['profile matching', 2.5],
                    ],
                ],
            ],
            // 4. Automated Abstracting and Indexing and Trends
            '4' => [
                // 4.1 Describe automated indexing
                '4.1' => [
                    'concept' => 'automatic indexing',
                    'keywords' => [
                        ['automatic indexing', 3.0],
                        ['automated indexing', 3.0],
                        ['machine indexing', 3.0],
                        ['computer-assisted indexing', 3.0],
                        ['computer assisted indexing', 3.0],
                        ['term frequency', 2.5],
                        ['inverse document frequency', 3.0],
                        ['tf-idf', 3.0],
                        ['stop list', 2.5],
                        ['stop words', 2.5],
                        ['stemming', 2.5],
                        ['word frequency', 2.0],
                    ],
                ],
                // 4.2 Describe an automated abstracting service
                '4.2' => [
                    'concept' => 'automatic abstracting',
                    'keywords' => [
                        ['automatic abstracting', 3.0],
                        ['automated abstracting', 3.0],
                        ['auto-abstracting', 3.0],
                        ['machine abstracting', 3.0],
                        ['automatic summarization', 3.0],
                        ['text summarization', 3.0],
                        ['sentence extraction', 3.0],
                        ['significant sentences', 2.5],
                        ['luhn', 2.0],
                    ],
                ],
                // 4.3 Identify new developments in indexing
                '4.3' => [
                    'concept' => 'new developments in indexing',
                    'keywords' => [
                        ['new developments in indexing', 3.0],
                        ['trends in indexing', 3.0],
                        ['folksonomy', 3.0],
                        ['social tagging', 3.0],
                        ['semantic web', 2.5],
                        ['linked data', 2.5],
                        ['ontology', 2.5],
                        ['ontologies', 2.5],
                        ['natural language processing', 2.5],
                        ['machine learning', 2.0],
                        ['tagging', 1.5],
                    ],
                ],
                // 4.4 Identify new developments in abstracting services
                '4.4' => [
                    'concept' => 'new developments in abstracting services',
                    'keywords' => [
                        ['new developments in abstracting', 3.0],
                        ['trends in abstracting', 3.0],
                        ['graphical abstract', 3.0],
                        ['video abstract', 3.0],
                        ['online abstracting services', 2.5],
                        ['electronic abstracting services', 2.5],
                        ['artificial intelligence', 2.0],
                    ],
                ],
            ],
        ],

        // ================================================================
        // Information Technology
        // ================================================================
        'it' => [
            // 1. Concepts of Information Storage and Retrieval Systems
            '1' => [
                // 1.1 Identify technologies for information handling: hardware, software, manpower component
                '1.1' => [
                    'concept' => 'hardware, software and peopleware',
                    'keywords' => [
                        ['hardware', 2.0],
                        ['software', 2.0],
                        ['peopleware', 3.0],
                        ['manpower', 2.0],
                        ['central processing unit', 3.0],
                        ['cpu', 2.5],
                        ['input device', 2.5],
                        ['output device', 2.5],
                        ['storage devices', 2.5],
                        ['operating system', 2.5],
                        ['application software', 2.5],
                        ['system software', 2.5],
                        ['ram', 2.0],
                        ['rom', 2.0],
                    ],
                ],
                // 1.2 Understand the nature and need for information and the function and responsibilities of libraries/information centers
                '1.2' => [
                    'concept' => 'the nature of information and information centers',
                    'keywords' => [
                        ['nature of information', 3.0],
                        ['information need', 2.5],
                        ['data information knowledge', 3.0],
                        ['dikw', 3.0],
                        ['information center', 2.0],
                        ['information centre', 2.0],
                        ['functions of libraries', 2.0],
                        ['role of libraries', 2.0],
                    ],
                ],
                // 1.3 Identify/describe principles of information storage and retrieval systems
                '1.3' => [
                    'concept' => 'information storage and retrieval',
                    'keywords' => [
                        ['information storage and retrieval', 3.0],
                        ['storage and retrieval', 2.5],
                        ['isar', 3.0],
                        ['boolean logic', 2.5],
                        ['boolean search', 2.5],
                        ['boolean operator', 2.5],
                        ['boolean', 2.0],
                        ['inverted file', 3.0],
                        ['file organization', 2.5],
                        ['sequential file', 2.5],
                        ['record structure', 2.0],
                        ['retrieval system', 2.0],
                        ['database', 1.5],
                        ['truncation', 2.5],
                        ['wildcard', 2.5],
                        ['federated search', 2.5],
                        ['search statement', 2.0],
                        ['nested search', 2.0],
                    ],
                ],
                // 1.4 Trace the history of computing, computer development and their effects on library and information work
                '1.4' => [
                    'concept' => 'the history of computing',
                    'keywords' => [
                        ['history of computing', 3.0],
                        ['history of computers', 3.0],
                        ['generations of computers', 3.0],
                        ['generation of computers', 3.0],
                        ['first generation', 2.0],
                        ['second generation', 2.0],
                        ['third generation', 2.0],
                        ['vacuum tubes', 3.0],
                        ['transistors', 2.5],
                        ['integrated circuits', 2.5],
                        ['microprocessor', 2.0],
                        ['eniac', 3.0],
                        ['univac', 3.0],
                        ['abacus', 2.5],
                        ['charles babbage', 3.0],
                        ['babbage', 2.5],
                        ['analytical engine', 3.0],
                        ['herman hollerith', 3.0],
                        ['punched card', 2.5],
                        ['ada lovelace', 3.0],
                    ],
                ],
            ],
            // 2. Information Systems and Databases
            '2' => [
                // 2.1 Name national, regional information systems
                '2.1' => [
                    'concept' => 'national and regional information systems',
                    'keywords' => [
                        ['national information system', 3.0],
                        ['regional information system', 3.0],
                        ['philippine elib', 3.0],
                        ['philippine e-lib', 3.0],
                        ['national library of the philippines', 2.5],
                        ['starbooks', 3.0],
                        ['dost-stii', 3.0],
                        ['stii', 2.5],
                        ['seameo', 2.0],
                        ['asean', 2.0],
                    ],
                ],
                // 2.2 Familiarize with international library systems
                '2.2' => [
                    'concept' => 'international library systems',
                    'keywords' => [
                        ['international library system', 3.0],
                        ['oclc', 2.5],
                        ['worldcat', 2.5],
                        ['rlin', 3.0],
                        ['ifla', 2.5],
                        ['unesco', 2.0],
                        ['europeana', 2.5],
                        ['world digital library', 3.0],
                        ['british library', 2.0],
                        ['library of congress', 1.5],
                    ],
                ],
                // 2.3 Identify international information databases
                '2.3' => [
                    'concept' => 'international information databases',
                    'keywords' => [
                        ['international database', 3.0],
                        ['international information database', 3.0],
                        ['agris', 3.0],
                        ['inis', 3.0],
                        ['medline', 2.5],
                        ['pubmed', 2.5],
                        ['scopus', 2.5],
                        ['web of science', 2.5],
                        ['ebsco', 2.5],
                        ['proquest', 2.5],
                        ['jstor', 2.5],
                        ['eric', 2.0],
                        ['dialog', 2.0],
                    ],
                ],
            ],
            // 3. Computer Applications in Library Operations
            '3' => [
                // 3.1.1 Computer applications: Acquisition
                '3.1.1' => [
                    'concept' => 'the automated acquisitions module',
                    'keywords' => [
                        ['acquisitions module', 3.0],
                        ['acquisition module', 3.0],
                        ['automated acquisition', 3.0],
                        ['electronic ordering', 2.5],
                        ['electronic data interchange', 3.0],
                        ['edi', 2.5],
                        ['fund accounting', 2.0],
                        ['vendor records', 2.0],
                    ],
                ],
                // 3.1.2 Computer applications: Circulation
                '3.1.2' => [
                    'concept' => 'the automated circulation module',
                    'keywords' => [
                        ['circulation module', 3.0],
                        ['automated circulation', 3.0],
                        ['circulation system', 2.5],
                        ['charging and discharging', 3.0],
                        ['self-check', 2.5],
                        ['rfid', 2.5],
                        ['barcode', 2.0],
                        ['patron record', 2.0],
                        ['check-out', 2.0],
                        ['checkout', 2.0],
                        ['overdue', 2.0],
                        ['sip2', 3.0],
                        ['self-check kiosk', 3.0],
                    ],
                ],
                // 3.1.3 Computer applications: Cataloging
                '3.1.3' => [
                    'concept' => 'the automated cataloging module',
                    'keywords' => [
                        ['cataloging module', 3.0],
                        ['automated cataloging', 3.0],
                        ['z39.50', 3.0],
                        ['importing records', 2.5],
                        ['marc records', 2.5],
                        ['marc', 2.0],
                        ['integrated library system', 1.5],
                        ['library management system', 1.5],
                        ['ils', 1.5],
                        ['koha', 2.0],
                        ['online public access catalog', 2.5],
                        ['opac', 2.0],
                        ['discovery layer', 2.5],
                        ['discovery service', 2.5],
                        ['dublin core', 2.0],
                        ['crosswalk', 2.0],
                    ],
                ],
                // 3.1.4 Computer applications: Serials
                '3.1.4' => [
                    'concept' => 'the automated serials module',
                    'keywords' => [
                        ['serials module', 3.0],
                        ['serials control', 3.0],
                        ['serials management', 2.5],
                        ['kardex', 3.0],
                        ['claiming', 2.5],
                        ['check-in', 2.0],
                        ['issn', 2.0],
                        ['subscription', 1.5],
                        ['periodicals', 1.5],
                    ],
                ],
                // 3.1.5 Computer applications: Inventory
                '3.1.5' => [
                    'concept' => 'automated inventory',
                    'keywords' => [
                        ['inventory module', 3.0],
                        ['collection inventory', 3.0],
                        ['stocktaking', 3.0],
                        ['stock-taking', 3.0],
                        ['stock taking', 3.0],
                        ['rfid inventory', 3.0],
                        ['missing items', 2.0],
                        ['inventory', 2.0],
                    ],
                ],
                // 3.1.6 Computer applications: Reporting
                '3.1.6' => [
                    'concept' => 'automated reports and statistics',
                    'keywords' => [
                        ['reports module', 3.0],
                        ['reporting module', 3.0],
                        ['report generation', 2.5],
                        ['generate reports', 2.5],
                        ['circulation statistics', 2.5],
                        ['usage statistics', 2.5],
                        ['management information', 2.0],
                        ['dashboard', 2.0],
                        ['statistics', 1.5],
                    ],
                ],
            ],
            // 4. Computer Technologies
            '4' => [
                // 4.1 Identify basics and developments in telecommunications
                '4.1' => [
                    'concept' => 'telecommunications',
                    'keywords' => [
                        ['telecommunication', 3.0],
                        ['transmission media', 3.0],
                        ['bandwidth', 2.5],
                        ['broadband', 2.5],
                        ['modem', 2.5],
                        ['fiber optic', 2.5],
                        ['fibre optic', 2.5],
                        ['twisted pair', 2.5],
                        ['coaxial cable', 2.5],
                        ['dial-up', 2.5],
                        ['tcp/ip', 2.5],
                        ['satellite', 1.5],
                        ['wireless', 1.5],
                    ],
                ],
                // 4.2 Differentiate networks - LAN, WAN, MAN, etc.
                '4.2' => [
                    'concept' => 'computer networks',
                    'keywords' => [
                        ['local area network', 3.0],
                        ['wide area network', 3.0],
                        ['metropolitan area network', 3.0],
                        ['personal area network', 3.0],
                        ['lan', 2.5],
                        ['wan', 2.5],
                        ['wlan', 2.5],
                        ['network topology', 3.0],
                        ['star topology', 3.0],
                        ['bus topology', 3.0],
                        ['ring topology', 3.0],
                        ['mesh topology', 3.0],
                        ['topology', 2.0],
                        ['client-server', 2.5],
                        ['peer-to-peer', 2.5],
                        ['intranet', 2.0],
                        ['extranet', 2.0],
                        ['router', 2.0],
                    ],
                ],
                // 4.3 Familiarize with the Internet and its application in library functions
                '4.3' => [
                    'concept' => 'the Internet in libraries',
                    'keywords' => [
                        ['internet', 2.0],
                        ['world wide web', 2.5],
                        ['www', 2.0],
                        ['web browser', 2.5],
                        ['browser', 2.0],
                        ['hypertext', 2.5],
                        ['html', 2.0],
                        ['url', 2.0],
                        ['ip address', 2.5],
                        ['domain name', 2.5],
                        ['internet service provider', 3.0],
                        ['isp', 2.0],
                        ['arpanet', 3.0],
                        ['berners-lee', 3.0],
                        ['web 2.0', 2.5],
                        ['library 2.0', 3.0],
                        ['library website', 2.5],
                        ['search engine', 2.0],
                    ],
                ],
            ],
            // 5. Multimedia Technologies
            '5' => [
                // 5.1 Identify current developments
                '5.1' => [
                    'concept' => 'multimedia technologies',
                    'keywords' => [
                        ['multimedia', 2.0],
                        ['hypermedia', 2.5],
                        ['virtual reality', 3.0],
                        ['augmented reality', 3.0],
                        ['3d printing', 3.0],
                        ['streaming', 2.5],
                        ['podcast', 2.5],
                        ['interactive media', 2.5],
                        ['digital video', 2.0],
                        ['animation', 2.0],
                    ],
                ],
                // 5.2 Familiarize with the applications in libraries and information work
                '5.2' => [
                    'concept' => 'multimedia services in libraries',
                    'keywords' => [
                        ['multimedia collection', 3.0],
                        ['multimedia resources', 3.0],
                        ['multimedia center', 3.0],
                        ['media center', 2.5],
                        ['learning resource center', 2.5],
                        ['audiovisual', 2.5],
                        ['audio-visual', 2.5],
                        ['makerspace', 2.5],
                        ['digital storytelling', 2.5],
                        ['video conferencing', 2.0],
                        ['e-learning', 2.0],
                    ],
                ],
            ],
            // 6. Issues in Information Technology
            '6' => [
                // 6.1 Understand the information age and its role for information professionals
                '6.1' => [
                    'concept' => 'the information age',
                    'keywords' => [
                        ['information age', 3.0],
                        ['information society', 2.5],
                        ['information explosion', 3.0],
                        ['information overload', 3.0],
                        ['digital divide', 3.0],
                        ['knowledge society', 2.5],
                        ['digital literacy', 2.5],
                        ['information literacy', 2.0],
                        ['information professional', 2.0],
                    ],
                ],
                // 6.2 Familiarize with emerging trends in information technology
                '6.2' => [
                    'concept' => 'emerging trends in information technology',
                    'keywords' => [
                        // keyword_hints: digital resources
                        ['digital resources', 2.5],
                        ['digital library', 2.5],
                        ['digital libraries', 2.5],
                        ['electronic resources', 2.5],
                        ['e-resources', 2.5],
                        ['e-books', 2.0],
                        ['ebooks', 2.0],
                        ['cloud computing', 3.0],
                        ['internet of things', 3.0],
                        ['blockchain', 3.0],
                        ['big data', 2.5],
                        // keyword_hints: safety and security controls
                        ['security controls', 3.0],
                        ['safety and security', 3.0],
                        ['information security', 2.5],
                        ['firewall', 2.5],
                        ['antivirus', 2.5],
                        ['malware', 2.5],
                        ['phishing', 2.5],
                        ['encryption', 2.5],
                        ['virus', 2.0],
                        ['authentication', 2.0],
                        // keyword_hints: licensing
                        ['licensing', 2.5],
                        ['license agreement', 3.0],
                        ['licensing agreement', 3.0],
                        ['creative commons', 2.5],
                        // keyword_hints: open journal access
                        ['open journal access', 3.0],
                        ['open access', 3.0],
                        ['open journal systems', 3.0],
                        ['ojs', 2.5],
                        ['directory of open access journals', 3.0],
                        ['doaj', 3.0],
                        ['institutional repository', 2.5],
                        ['open source', 2.0],
                        ['data backup', 3.0],
                        ['backup', 2.5],
                    ],
                ],
                // 6.3 Ethical issues in information technology
                '6.3' => [
                    'concept' => 'ethical issues in information technology',
                    'keywords' => [
                        // keyword_hints: apply netiquette
                        ['netiquette', 3.0],
                        // keyword_hints: differentiate intellectual property rights (IPR) and copyright
                        ['intellectual property rights', 3.0],
                        ['intellectual property', 2.5],
                        ['ipr', 2.5],
                        ['copyright', 2.5],
                        ['intellectual property code', 3.0],
                        ['ra 8293', 3.0],
                        ['r.a. 8293', 3.0],
                        ['fair use', 3.0],
                        ['plagiarism', 2.5],
                        ['piracy', 2.5],
                        ['data privacy act', 3.0],
                        ['ra 10173', 3.0],
                        ['r.a. 10173', 3.0],
                        ['cybercrime', 2.5],
                        ['ra 10175', 3.0],
                        ['computer ethics', 3.0],
                        ['ethical issues', 2.5],
                        ['privacy', 2.0],
                    ],
                ],
            ],
        ],

        // ================================================================
        // Library Organization and Management
        // ================================================================
        'management' => [
            // 1. Knowledge Relating to the Development of Organization/Management
            '1' => [
                // 1.1 Trace the historical development of organization and management
                '1.1' => [
                    'concept' => 'schools of management thought',
                    'keywords' => [
                        ['history of management', 3.0],
                        ['scientific management', 3.0],
                        ['frederick taylor', 3.0],
                        ['henri fayol', 3.0],
                        ['fayol', 2.5],
                        ['max weber', 3.0],
                        ['bureaucracy', 2.5],
                        ['hawthorne studies', 3.0],
                        ['elton mayo', 3.0],
                        ['human relations movement', 3.0],
                        ['classical theory', 2.5],
                        ['classical management', 2.5],
                        ['behavioral school', 2.5],
                        ['gilbreth', 3.0],
                        ['henry gantt', 3.0],
                        ['management theory', 2.0],
                        ['taylor', 2.0],
                    ],
                ],
                // 1.2 Explain the basic principles / concepts involved in organization / management
                '1.2' => [
                    'concept' => 'the principles of management',
                    'keywords' => [
                        ['functions of management', 3.0],
                        ['management functions', 3.0],
                        ['posdcorb', 3.0],
                        ['unity of command', 3.0],
                        ['span of control', 3.0],
                        ['chain of command', 3.0],
                        ['scalar chain', 3.0],
                        ['division of labor', 2.5],
                        ['line and staff', 2.5],
                        ['organizational chart', 2.5],
                        ['organizational structure', 2.5],
                        ['authority and responsibility', 2.5],
                        ['centralization', 2.0],
                        ['decentralization', 2.0],
                        ['delegation', 2.0],
                        ['planning', 1.5],
                        ['organizing', 1.5],
                        ['directing', 1.5],
                        ['controlling', 1.5],
                        ['coordinating', 1.5],
                    ],
                ],
                // 1.3 Apply the basic principles / concepts of organization / management to library operations
                '1.3' => [
                    'concept' => 'planning library operations',
                    'keywords' => [
                        ['library operations', 2.5],
                        ['library planning', 2.5],
                        ['strategic planning', 2.5],
                        ['strategic plan', 2.5],
                        ['swot analysis', 3.0],
                        ['swot', 2.5],
                        ['mission statement', 2.5],
                        ['vision statement', 2.5],
                        ['management by objectives', 2.5],
                        ['goals and objectives', 2.0],
                        ['gantt chart', 2.5],
                        ['pert', 2.5],
                        ['decision making', 2.0],
                    ],
                ],
                // 1.4 Identify / differentiate various management styles and leadership qualities / traits
                '1.4' => [
                    'concept' => 'leadership and management styles',
                    'keywords' => [
                        ['leadership style', 3.0],
                        ['management style', 3.0],
                        ['autocratic', 3.0],
                        ['democratic leadership', 3.0],
                        ['laissez-faire', 3.0],
                        ['laissez faire', 3.0],
                        ['participative', 2.5],
                        ['transformational leadership', 3.0],
                        ['transactional leadership', 3.0],
                        ['situational leadership', 3.0],
                        ['theory x', 3.0],
                        ['theory y', 3.0],
                        ['theory z', 3.0],
                        ['mcgregor', 3.0],
                        ['hierarchy of needs', 3.0],
                        ['maslow', 2.5],
                        ['herzberg', 3.0],
                        ['motivation', 2.0],
                        ['leadership', 2.0],
                    ],
                ],
            ],
            // 2. Administration of the Library
            '2' => [
                // 2.1 Relate the work of the librarian within and outside of the organization
                '2.1' => [
                    'concept' => "the librarian's work within and outside the organization",
                    'keywords' => [
                        ['role of the librarian', 2.5],
                        ['professional organizations', 2.5],
                        ['professional association', 2.5],
                        ['plai', 2.5],
                        ['liaison', 2.5],
                        ['linkages', 2.0],
                        ['stakeholders', 2.0],
                        ['administration', 1.5],
                    ],
                ],
                // 2.2 Differentiate the various types of libraries as to objectives, functions, collections, staff and services
                '2.2' => [
                    'concept' => 'the types of libraries',
                    'keywords' => [
                        ['types of libraries', 3.0],
                        ['type of library', 3.0],
                        ['academic library', 2.5],
                        ['academic libraries', 2.5],
                        ['public library', 2.5],
                        ['public libraries', 2.5],
                        ['school library', 2.5],
                        ['school libraries', 2.5],
                        ['special library', 2.5],
                        ['special libraries', 2.5],
                        ['national library', 2.5],
                        ['barangay reading center', 3.0],
                        ['reading center', 2.0],
                        ['research library', 2.0],
                        ['university library', 2.0],
                        ['college library', 2.0],
                    ],
                ],
                // 2.3 Define the composition and functions of the Library Board / Committee
                '2.3' => [
                    'concept' => 'the library board',
                    'keywords' => [
                        ['library board', 3.0],
                        ['library committee', 3.0],
                        ['board of trustees', 3.0],
                        ['composition of the board', 3.0],
                        ['advisory board', 2.5],
                        ['trustees', 2.0],
                        ['board members', 2.0],
                    ],
                ],
            ],
            // 3. Major Administrative Concerns
            '3' => [
                // 3.1 Familiarize oneself with the various elements involved in personnel management
                '3.1' => [
                    'concept' => 'personnel management',
                    'keywords' => [
                        ['personnel management', 3.0],
                        ['human resource management', 3.0],
                        ['human resources', 2.0],
                        ['recruitment', 2.5],
                        ['job description', 3.0],
                        ['job analysis', 2.5],
                        ['performance appraisal', 3.0],
                        ['performance evaluation', 2.5],
                        ['staff development', 3.0],
                        ['succession planning', 2.5],
                        ['paraprofessional', 2.5],
                        ['support staff', 2.0],
                        ['professional staff', 2.0],
                        ['compensation', 2.0],
                        ['salary', 2.0],
                        ['staffing', 2.0],
                        ['personnel', 2.0],
                        ['training', 1.5],
                    ],
                ],
                // 3.2 Identify the various sources of library support and explain the various ways of preparing the library budget
                '3.2' => [
                    'concept' => 'the library budget',
                    'keywords' => [
                        ['library budget', 3.0],
                        ['line-item budget', 3.0],
                        ['line item budget', 3.0],
                        ['program budget', 3.0],
                        ['performance budget', 3.0],
                        ['zero-based budgeting', 3.0],
                        ['zero based budgeting', 3.0],
                        ['ppbs', 3.0],
                        ['formula budget', 3.0],
                        ['lump sum', 2.5],
                        ['sources of funds', 3.0],
                        ['source of funds', 3.0],
                        ['library fee', 2.5],
                        ['fund raising', 2.5],
                        ['fundraising', 2.5],
                        ['budgeting', 2.5],
                        ['budget', 2.0],
                        ['funding', 2.0],
                        ['grants', 2.0],
                    ],
                ],
                // 3.3 Differentiate and perform the major services of the library
                '3.3' => [
                    'concept' => 'the major services of the library',
                    'keywords' => [
                        ['library services', 2.0],
                        ['readers services', 2.5],
                        ['public services', 2.0],
                        ['technical services', 2.0],
                        ['circulation service', 2.5],
                        ['extension service', 3.0],
                        ['outreach', 2.0],
                        ['interlibrary loan', 2.5],
                        ['document delivery', 2.5],
                        ['reserve section', 2.5],
                        ['bookmobile', 2.5],
                    ],
                ],
                // 3.4 Determine the physical requirements of a library in terms of space, facilities, equipment and furniture
                '3.4' => [
                    'concept' => 'library space, facilities and furniture',
                    'keywords' => [
                        ['library building', 3.0],
                        ['building planning', 3.0],
                        ['space requirements', 3.0],
                        ['floor space', 3.0],
                        ['seating capacity', 3.0],
                        ['square meters', 2.5],
                        ['square feet', 2.5],
                        ['furniture', 2.5],
                        ['facilities', 2.0],
                        ['lighting', 2.0],
                        ['ventilation', 2.0],
                        ['air conditioning', 2.0],
                        ['open shelf', 2.0],
                        ['closed shelf', 2.0],
                        ['equipment', 1.5],
                    ],
                ],
                // 3.5 Explain how the library collection should be managed, preserved and conserved
                '3.5' => [
                    'concept' => 'managing and preserving the collection',
                    'keywords' => [
                        ['collection management', 2.5],
                        ['disaster plan', 3.0],
                        ['disaster preparedness', 3.0],
                        ['disaster management', 3.0],
                        ['security of the collection', 2.5],
                        ['pest control', 2.5],
                        ['mutilation', 2.5],
                        ['theft', 2.0],
                        ['book repair', 2.5],
                        ['mending', 2.5],
                        ['preservation', 2.0],
                        ['conservation', 2.0],
                        ['humidity', 2.0],
                        ['collection development policy', 2.5],
                        ['collection development', 2.0],
                        ['salvage', 2.5],
                        ['water damage', 2.5],
                        ['wet books', 2.5],
                        ['business continuity', 3.0],
                    ],
                ],
                // 3.6 Describe different ways of promoting the library
                '3.6' => [
                    'concept' => 'library promotion and public relations',
                    'keywords' => [
                        ['library promotion', 3.0],
                        ['promoting the library', 3.0],
                        ['library marketing', 3.0],
                        ['marketing mix', 3.0],
                        ['public relations', 2.5],
                        ['marketing', 2.5],
                        ['publicity', 2.5],
                        ['advocacy', 2.5],
                        ['library week', 3.0],
                        ['book week', 2.5],
                        ['bulletin board', 2.5],
                        ['brochure', 2.5],
                        ['exhibits', 2.0],
                        ['displays', 2.0],
                        ['newsletter', 2.0],
                        ['social media', 2.0],
                    ],
                ],
                // 3.7 Identify the various ways of evaluating library services
                '3.7' => [
                    'concept' => 'evaluating library services',
                    'keywords' => [
                        ['evaluation of library services', 3.0],
                        ['evaluating library services', 3.0],
                        ['service evaluation', 3.0],
                        ['user satisfaction', 3.0],
                        ['user survey', 2.5],
                        ['libqual', 3.0],
                        ['performance measures', 2.5],
                        ['performance indicators', 2.5],
                        ['output measures', 2.5],
                        ['input measures', 2.5],
                        ['outcome assessment', 2.5],
                        ['benchmarking', 2.5],
                        ['quality assurance', 2.0],
                        ['accreditation', 2.0],
                    ],
                ],
            ],
            // 4. Laws, Related Practices, and Trends
            '4' => [
                // 4.1 Understand RA 9246 and other laws and issuances related to Philippine librarianship
                '4.1' => [
                    'concept' => 'RA 9246 and other library laws',
                    'keywords' => [
                        ['ra 9246', 3.0],
                        ['r.a. 9246', 3.0],
                        ['republic act 9246', 3.0],
                        ['republic act no. 9246', 3.0],
                        ['philippine librarianship act', 3.0],
                        ['librarianship act', 3.0],
                        ['board for librarians', 3.0],
                        ['professional regulation commission', 3.0],
                        ['prc', 2.0],
                        ['ra 7743', 3.0],
                        ['ra 411', 3.0],
                        ['ra 10066', 2.5],
                        ['ra 8047', 3.0],
                        ['ra 10912', 3.0],
                        ['continuing professional development', 3.0],
                        ['cpd', 2.5],
                        ['certificate of registration', 2.5],
                        ['republic act', 2.0],
                        ['presidential decree', 2.0],
                        ['executive order', 2.0],
                    ],
                ],
                // 4.2 Understand the Code of Ethics for Filipino Librarians
                '4.2' => [
                    'concept' => 'the Code of Ethics for Filipino Librarians',
                    'keywords' => [
                        ['code of ethics', 3.0],
                        ['code of ethics for registered librarians', 3.0],
                        ['professional ethics', 2.5],
                        ['ethics', 2.0],
                        ['unethical', 2.0],
                        ['confidentiality', 2.0],
                    ],
                ],
                // 4.3 Discuss trends and practices in the field of librarianship
                '4.3' => [
                    'concept' => 'trends in librarianship',
                    'keywords' => [
                        ['trends in librarianship', 3.0],
                        ['trends and practices', 3.0],
                        ['knowledge management', 2.5],
                        ['embedded librarian', 3.0],
                        ['learning commons', 3.0],
                        ['information commons', 3.0],
                        ['green library', 3.0],
                        ['outsourcing', 2.5],
                        ['consortium', 2.5],
                        ['consortia', 2.5],
                        ['makerspace', 2.0],
                        ['emerging trends', 2.0],
                    ],
                ],
            ],
        ],

        // ================================================================
        // Reference, Bibliography and User Services
        // ================================================================
        'reference' => [
            // 1. Knowledge Relating to the Nature, Historical Development and Functions of Reference and Information Services
            '1' => [
                // 1.1 Define the nature of reference service
                '1.1' => [
                    'concept' => 'the nature of reference service',
                    'keywords' => [
                        ['nature of reference', 3.0],
                        ['definition of reference service', 3.0],
                        ['theories of reference service', 3.0],
                        ['conservative theory', 2.5],
                        ['liberal theory', 2.5],
                        ['moderate theory', 2.5],
                        ['wyer', 2.5],
                        ['reference work', 2.5],
                        ['reference service', 2.0],
                    ],
                ],
                // 1.2 Trace the historical development of reference service
                '1.2' => [
                    'concept' => 'the history of reference service',
                    'keywords' => [
                        ['history of reference', 3.0],
                        ['historical development of reference', 3.0],
                        ['origin of reference service', 3.0],
                        ['samuel swett green', 3.0],
                        ['swett green', 3.0],
                        ['personal assistance to readers', 3.0],
                        ['personal relations between librarians and readers', 3.0],
                        ['first reference librarian', 3.0],
                        ['1876', 2.0],
                    ],
                ],
                // 1.3 Identify / differentiate the functions of reference service
                '1.3' => [
                    'concept' => 'the functions of reference service',
                    'keywords' => [
                        ['functions of reference service', 3.0],
                        ['functions of reference', 3.0],
                        ['information function', 2.5],
                        ['instruction function', 2.5],
                        ['guidance function', 2.5],
                        ['readers advisory', 2.5],
                        ["reader's advisory", 2.5],
                        ['bibliographic instruction', 2.5],
                        ['user education', 2.0],
                        ['guidance', 2.0],
                        ['instruction', 1.5],
                        ['book talk', 2.5],
                    ],
                ],
                // 1.4 Describe the organizational structure of the reference department
                '1.4' => [
                    'concept' => 'the reference department',
                    'keywords' => [
                        ['reference department', 3.0],
                        ['reference section', 3.0],
                        ['organization of the reference department', 3.0],
                        ['tiered reference', 3.0],
                        ['tiered service', 2.5],
                        ['reference area', 2.5],
                        ['head of reference', 2.5],
                        ['information desk', 2.5],
                        ['reference desk', 2.0],
                        ['reference collection', 2.0],
                    ],
                ],
                // 1.5 Describe the qualities and qualifications of a reference librarian
                '1.5' => [
                    'concept' => 'the qualities of a reference librarian',
                    'keywords' => [
                        ['qualities of a reference librarian', 3.0],
                        ['qualifications of a reference librarian', 3.0],
                        ['approachability', 3.0],
                        ['approachable', 2.5],
                        ['knowledge of sources', 2.5],
                        ['personality', 2.0],
                        ['courtesy', 2.0],
                        ['patience', 2.0],
                        ['tact', 2.0],
                        ['qualities', 1.5],
                        ['qualifications', 1.5],
                    ],
                ],
                // 1.6 Enumerate the duties and responsibilities of a reference librarian
                '1.6' => [
                    'concept' => 'the duties of a reference librarian',
                    'keywords' => [
                        ['duties of a reference librarian', 3.0],
                        ['responsibilities of the reference librarian', 3.0],
                        ['duties and responsibilities', 3.0],
                        ['compiling bibliographies', 2.5],
                        ['answering questions', 2.0],
                        ['reference librarian', 1.5],
                    ],
                ],
                // 1.7 Relate reference service with the other services of the library
                '1.7' => [
                    'concept' => 'reference and the other library services',
                    'keywords' => [
                        ['relationship with other services', 3.0],
                        ['relation to other services', 3.0],
                        ['other departments', 2.0],
                        ['technical services', 2.0],
                        ['cataloging department', 2.0],
                        ['circulation', 1.5],
                        ['acquisitions', 1.5],
                        ['cooperation', 1.5],
                    ],
                ],
            ],
            // 2. Reference and Information Sources
            '2' => [
                // 2.1 Define the nature and scope of reference and information sources (both general and specific)
                '2.1' => [
                    'concept' => 'the nature and scope of reference sources',
                    'keywords' => [
                        ['nature of reference sources', 3.0],
                        ['scope of reference sources', 3.0],
                        ['consulted rather than read', 3.0],
                        ['general reference', 2.5],
                        ['primary sources', 2.5],
                        ['secondary sources', 2.5],
                        ['tertiary sources', 2.5],
                        ['reference source', 2.0],
                        ['reference materials', 2.0],
                        ['reference book', 2.0],
                        ['information sources', 1.5],
                    ],
                ],
                // 2.2 Identify / classify the different types of reference sources
                '2.2' => [
                    'concept' => 'the types of reference sources',
                    'keywords' => [
                        ['types of reference sources', 3.0],
                        ['encyclopedia', 2.5],
                        ['almanac', 2.5],
                        ['yearbook', 2.5],
                        ['handbook', 2.5],
                        ['atlas', 2.5],
                        ['gazetteer', 2.5],
                        ['biographical dictionary', 2.5],
                        ['biographical sources', 2.5],
                        ['geographical sources', 2.5],
                        ['enumerative bibliography', 2.5],
                        ['analytical bibliography', 2.5],
                        ['descriptive bibliography', 2.5],
                        ['national bibliography', 2.5],
                        ['trade bibliography', 2.5],
                        ['annotated bibliography', 2.5],
                        ['dictionary', 2.0],
                        ['dictionaries', 2.0],
                        ['directory', 2.0],
                        ['directories', 2.0],
                        ['bibliography', 2.0],
                        ['bibliographies', 2.0],
                        ['manual', 1.5],
                    ],
                ],
                // 2.3 Evaluate the various reference and information sources
                '2.3' => [
                    'concept' => 'evaluating reference sources',
                    'keywords' => [
                        ['evaluation of reference sources', 3.0],
                        ['evaluating reference sources', 3.0],
                        ['evaluate reference sources', 3.0],
                        ['craap', 3.0],
                        ['authority', 2.0],
                        ['currency', 2.0],
                        ['objectivity', 2.0],
                        ['scope', 1.5],
                        ['accuracy', 1.5],
                        ['arrangement', 1.5],
                        ['treatment', 1.5],
                        ['criteria', 1.5],
                    ],
                ],
                // 2.4 Utilize appropriate reference sources to various information needs
                '2.4' => [
                    'concept' => 'choosing the right reference source',
                    'keywords' => [
                        ['appropriate source', 3.0],
                        ['best source', 2.5],
                        ['which source', 2.5],
                        ['would consult', 2.5],
                        ['should consult', 2.5],
                        ['britannica', 2.5],
                        ['world almanac', 2.5],
                        ["who's who", 3.0],
                        ['oxford english dictionary', 2.5],
                        ['times atlas', 2.5],
                        ["statesman's yearbook", 2.5],
                        ['books in print', 2.5],
                        ['ulrich', 2.5],
                        ["readers' guide to periodical literature", 3.0],
                        ['readers guide', 2.5],
                        ['guinness', 2.0],
                        ['webster', 2.0],
                        ['factbook', 2.0],
                    ],
                ],
            ],
            // 3. Reference and Information Services
            '3' => [
                // 3.1 Describe the reference process
                '3.1' => [
                    'concept' => 'the reference process',
                    'keywords' => [
                        ['reference process', 3.0],
                        ['steps in the reference process', 3.0],
                        ['reference transaction', 3.0],
                        ['follow-up', 2.0],
                        ['follow up', 2.0],
                        ['search strategy', 2.0],
                    ],
                ],
                // 3.2 Differentiate the types of reference questions
                '3.2' => [
                    'concept' => 'the types of reference questions',
                    'keywords' => [
                        ['types of reference questions', 3.0],
                        ['reference questions', 2.5],
                        ['directional question', 3.0],
                        ['ready reference question', 3.0],
                        ['ready reference', 2.5],
                        ['quick reference', 2.5],
                        ['specific search', 2.5],
                        ['search question', 2.5],
                        ['fact-finding', 2.5],
                        ['research question', 2.0],
                    ],
                ],
                // 3.3 Identify the various methods and techniques involved in a reference interview
                '3.3' => [
                    'concept' => 'the reference interview',
                    'keywords' => [
                        ['reference interview', 3.0],
                        ['neutral questioning', 3.0],
                        ['neutral question', 3.0],
                        ['question negotiation', 3.0],
                        ['negotiating the question', 3.0],
                        ['active listening', 3.0],
                        ['rusa guidelines', 3.0],
                        ['visceral need', 3.0],
                        ['conscious need', 2.5],
                        ['formalized need', 3.0],
                        ['compromised need', 3.0],
                        ['open-ended question', 2.5],
                        ['open question', 2.5],
                        ['closed question', 2.5],
                        ['sense-making', 2.5],
                        ['nonverbal', 2.5],
                        ['non-verbal', 2.5],
                        ['body language', 2.5],
                        ['eye contact', 2.5],
                    ],
                ],
                // 3.4 Distinguish direct and indirect types of reference services
                '3.4' => [
                    'concept' => 'direct and indirect reference services',
                    'keywords' => [
                        ['direct reference service', 3.0],
                        ['indirect reference service', 3.0],
                        ['direct and indirect', 2.5],
                        ['direct service', 2.5],
                        ['indirect service', 2.5],
                        ['referral service', 3.0],
                        ['referral', 2.5],
                        ['library orientation', 2.5],
                        ['vertical file', 2.5],
                        ['interlibrary loan', 2.0],
                        ['refer the patron', 3.0],
                        ['refer', 1.5],
                    ],
                ],
                // 3.5 Identify the types and functions of information service
                '3.5' => [
                    'concept' => 'information services',
                    'keywords' => [
                        ['information service', 2.0],
                        ['current awareness service', 3.0],
                        ['current awareness', 2.5],
                        ['selective dissemination of information', 3.0],
                        ['sdi', 2.5],
                        ['information repackaging', 3.0],
                        ['repackaging', 2.5],
                        ['community information service', 3.0],
                        ['translation service', 3.0],
                        ['clipping service', 3.0],
                        ['literature search', 2.5],
                        ['document delivery', 2.5],
                        ['alerting', 2.0],
                        ['rss', 2.5],
                        ['table of contents alert', 2.5],
                    ],
                ],
                // 3.6 Demonstrate the use of computers in reference service
                '3.6' => [
                    'concept' => 'virtual reference service',
                    'keywords' => [
                        ['virtual reference', 3.0],
                        ['digital reference', 3.0],
                        ['chat reference', 3.0],
                        ['email reference', 3.0],
                        ['ask a librarian', 3.0],
                        ['sms reference', 3.0],
                        ['questionpoint', 3.0],
                        ['online reference', 2.5],
                        ['electronic reference', 2.5],
                        ['instant messaging', 2.5],
                        ['chatbot', 2.5],
                        ['online databases', 2.0],
                    ],
                ],
                // 3.7 Determine the use and functions of bibliographic networks in reference service
                '3.7' => [
                    'concept' => 'bibliographic networks',
                    'keywords' => [
                        ['bibliographic network', 3.0],
                        ['bibliographic utility', 3.0],
                        ['bibliographic utilities', 3.0],
                        ['union catalog', 2.5],
                        ['union catalogue', 2.5],
                        ['library network', 2.5],
                        ['oclc', 2.5],
                        ['worldcat', 2.5],
                        ['rlin', 2.5],
                        ['consortium', 2.0],
                        ['resource sharing', 2.0],
                    ],
                ],
            ],
        ],

        // ================================================================
        // Selection and Acquisition
        // ================================================================
        'selection' => [
            // 1. Selection of Multi-media Information Sources
            '1' => [
                // 1.1 Identify/describe basic principles for collection development
                '1.1' => [
                    'concept' => 'the principles of collection development',
                    'keywords' => [
                        ['principles of collection development', 3.0],
                        ['collection building', 2.5],
                        ['five laws of library science', 3.0],
                        ['five laws', 2.5],
                        ['books are for use', 3.0],
                        ['every reader his book', 3.0],
                        ['every book its reader', 3.0],
                        ['the right book for the right reader at the right time', 3.0],
                        ['right book', 2.5],
                        ['drury', 3.0],
                        ['mccolvin', 3.0],
                        ['demand theory', 3.0],
                        ['value theory', 3.0],
                        ['collection development', 2.0],
                        ['community analysis', 3.0],
                        ['needs assessment', 3.0],
                        ['user needs', 2.0],
                    ],
                ],
                // 1.2 Apply basic concepts and principles of collection and selection
                '1.2' => [
                    'concept' => 'selection tools and aids',
                    'keywords' => [
                        ['book selection', 2.5],
                        ['selection process', 2.5],
                        ['selection tools', 2.5],
                        ['selection aids', 2.5],
                        ['book reviews', 2.5],
                        ['reviewing sources', 2.5],
                        ['booklist', 2.5],
                        ["publishers' catalogs", 2.5],
                        ['publishers catalogs', 2.5],
                        ['trade bibliographies', 2.5],
                        ['books in print', 2.5],
                        ['core collection', 2.5],
                        ['opening day collection', 3.0],
                        ['basic collection', 2.0],
                        ['selection', 1.0],
                    ],
                ],
                // 1.3 Understand and apply criteria for selection and collection of print and non-print materials
                '1.3' => [
                    'concept' => 'selection criteria for print and non-print materials',
                    'keywords' => [
                        ['selection criteria', 3.0],
                        ['criteria for selection', 3.0],
                        ['non-print materials', 3.0],
                        ['non-print', 2.5],
                        ['nonprint', 2.5],
                        ['audiovisual materials', 2.5],
                        ['reputation of the publisher', 3.0],
                        ['authority of the author', 2.5],
                        ['relevance to the curriculum', 2.5],
                        ['print materials', 2.0],
                        ['physical format', 2.0],
                    ],
                ],
                // 1.4 Apply standards of collection development in libraries
                '1.4' => [
                    'concept' => 'collection development standards',
                    'keywords' => [
                        ['collection standards', 3.0],
                        ['standards for academic libraries', 3.0],
                        ['paarl standards', 3.0],
                        ['ched requirements', 3.0],
                        ['ched', 2.5],
                        ['deped', 2.0],
                        ['volumes per student', 3.0],
                        ['titles per course', 3.0],
                        ['number of volumes', 2.5],
                        ['collecting levels', 3.0],
                        ['collection levels', 3.0],
                        ['standards', 1.5],
                    ],
                ],
                // 1.5 Define the responsibility and place of librarians as selectors of library materials
                '1.5' => [
                    'concept' => 'who selects library materials',
                    'keywords' => [
                        ['responsibility for selection', 3.0],
                        ['librarian as selector', 3.0],
                        ['selection committee', 3.0],
                        ['faculty participation', 2.5],
                        ['faculty involvement', 2.5],
                        ['subject specialist', 2.5],
                        ['bibliographer', 2.5],
                        ['who selects', 2.5],
                        ['selector', 2.0],
                    ],
                ],
            ],
            // 2. Acquisition Process
            '2' => [
                // 2.1 Identify the different methods of acquisition
                '2.1' => [
                    'concept' => 'the methods of acquisition',
                    'keywords' => [
                        ['methods of acquisition', 3.0],
                        ['gifts and exchange', 3.0],
                        ['legal deposit', 3.0],
                        ['approval plan', 3.0],
                        ['blanket order', 3.0],
                        ['standing order', 3.0],
                        ['firm order', 3.0],
                        ['purchase', 2.0],
                        ['gift', 2.0],
                        ['exchange', 2.0],
                        ['donation', 2.0],
                        ['lease', 2.0],
                        ['leasing', 2.0],
                        ['subscription', 2.0],
                    ],
                ],
                // 2.2 Describe ordering, work flow, procedures, forms, records, etc.
                '2.2' => [
                    'concept' => 'the ordering workflow and its records',
                    'keywords' => [
                        ['purchase order', 3.0],
                        ['order form', 3.0],
                        ['pre-order searching', 3.0],
                        ['preorder searching', 3.0],
                        ['bibliographic verification', 3.0],
                        ['outstanding orders', 3.0],
                        ['accession book', 3.0],
                        ['accession record', 2.5],
                        ['accessioning', 2.5],
                        ['encumbrance', 3.0],
                        ['jobber', 3.0],
                        ['purchase request', 2.5],
                        ['order procedure', 2.5],
                        ['order file', 2.5],
                        ['invoice', 2.5],
                        ['claiming', 2.5],
                        ['verification', 2.0],
                        ['ordering', 2.0],
                        ['receiving', 2.0],
                        ['vendor', 2.0],
                        ['dealer', 2.0],
                        ['workflow', 2.0],
                        ['work flow', 2.0],
                        ['on-order file', 3.0],
                        ['placing an order', 2.5],
                    ],
                ],
                // 2.3 Explain factors affecting acquisition work
                '2.3' => [
                    'concept' => 'factors affecting acquisition work',
                    'keywords' => [
                        ['factors affecting acquisition', 3.0],
                        ['exchange rate', 3.0],
                        ['out of print', 3.0],
                        ['out-of-print', 3.0],
                        ['inflation', 2.5],
                        ['price increases', 2.5],
                        ['budget cuts', 2.5],
                        ['customs', 2.0],
                        ['import', 2.0],
                        ['budget', 1.5],
                        ['availability', 1.5],
                        ['fund allocation', 3.0],
                        ['allocation formula', 3.0],
                        ['book budget', 2.5],
                    ],
                ],
                // 2.4 Familiarize with the procedures in the distribution of library materials
                '2.4' => [
                    'concept' => 'processing and distributing new materials',
                    'keywords' => [
                        ['distribution of library materials', 3.0],
                        ['physical processing', 2.5],
                        ['book pocket', 2.5],
                        ['date due slip', 2.5],
                        ['ownership stamp', 2.5],
                        ['property stamp', 2.5],
                        ['distribution', 2.0],
                        ['branch libraries', 2.0],
                        ['labeling', 2.0],
                        ['routing', 2.0],
                        ['processing', 1.5],
                    ],
                ],
            ],
            // 3. Evaluation of Collection
            '3' => [
                // 3.1 Identify approaches to collection evaluation
                '3.1' => [
                    'concept' => 'collection evaluation methods',
                    'keywords' => [
                        ['collection evaluation', 3.0],
                        ['evaluation of the collection', 3.0],
                        ['collection assessment', 3.0],
                        ['collection-centered', 3.0],
                        ['collection centered', 3.0],
                        ['use-centered', 3.0],
                        ['user-centered', 2.5],
                        ['checklist method', 3.0],
                        ['list checking', 3.0],
                        ['circulation studies', 3.0],
                        ['impressionistic', 3.0],
                        ['conspectus', 3.0],
                        ['use studies', 2.5],
                        ['citation analysis', 2.5],
                        ['direct observation', 2.5],
                        ['expert opinion', 2.5],
                        ['comparative statistics', 2.5],
                    ],
                ],
                // 3.2 Apply deselection and weeding criteria and processes
                '3.2' => [
                    'concept' => 'weeding the collection',
                    'keywords' => [
                        ['weeding', 3.0],
                        ['deselection', 3.0],
                        ['de-selection', 3.0],
                        ['crew method', 3.0],
                        ['mustie', 3.0],
                        ['slote', 2.5],
                        ['discarding', 2.5],
                        ['relegation', 2.5],
                        ['weed', 2.5],
                        ['withdrawal', 2.0],
                        ['obsolete', 2.0],
                        ['outdated', 2.0],
                    ],
                ],
            ],
            // 4. Formulation of Collection Development Evaluation
            '4' => [
                // 4.1 Design collection development policies
                '4.1' => [
                    'concept' => 'the collection development policy',
                    'keywords' => [
                        ['collection development policy', 3.0],
                        ['collection development policies', 3.0],
                        ['components of a collection development policy', 3.0],
                        ['collection policy', 3.0],
                        ['selection policy', 3.0],
                        ['weeding policy', 3.0],
                        ['acquisition policy', 2.5],
                        ['gifts policy', 2.5],
                        ['written policy', 2.5],
                        ['policy statement', 2.5],
                        ['policy', 1.5],
                    ],
                ],
            ],
            // 5. Preservation and Conservation
            '5' => [
                // 5.1 Identify/describe preservation and conservation procedures
                '5.1' => [
                    'concept' => 'the causes of deterioration',
                    'keywords' => [
                        ['preservation and conservation', 3.0],
                        ['preventive conservation', 3.0],
                        ['temperature and humidity', 3.0],
                        ['acid paper', 3.0],
                        ['acid-free', 3.0],
                        ['foxing', 3.0],
                        ['silverfish', 3.0],
                        ['deterioration', 2.5],
                        ['preservation', 2.5],
                        ['conservation', 2.5],
                        ['humidity', 2.5],
                        ['light damage', 2.5],
                        ['mold', 2.5],
                        ['mould', 2.5],
                        ['pests', 2.5],
                    ],
                ],
                // 5.2 Apply preservation and conservation
                '5.2' => [
                    'concept' => 'conservation treatments',
                    'keywords' => [
                        ['deacidification', 3.0],
                        ['encapsulation', 3.0],
                        ['lamination', 3.0],
                        ['rebinding', 3.0],
                        ['book repair', 3.0],
                        ['mending', 3.0],
                        ['fumigation', 3.0],
                        ['reformatting', 3.0],
                        ['freeze-drying', 3.0],
                        ['microfilming', 2.5],
                        ['digitization', 2.5],
                        ['restoration', 2.5],
                        ['binding', 2.0],
                    ],
                ],
            ],
            // 6. Issues and Trends in Collection Development
            '6' => [
                // 6.1 Be aware of the automation and trends of collection development
                '6.1' => [
                    'concept' => 'trends in collection development',
                    'keywords' => [
                        ['trends in collection development', 3.0],
                        ['patron-driven acquisition', 3.0],
                        ['patron driven acquisition', 3.0],
                        ['demand-driven acquisition', 3.0],
                        ['demand driven acquisition', 3.0],
                        ['evidence-based acquisition', 3.0],
                        ['big deal', 3.0],
                        ['aggregator', 2.5],
                        ['electronic resources', 2.5],
                        ['e-resources', 2.5],
                        ['e-books', 2.5],
                        ['ebooks', 2.5],
                        ['electronic ordering', 2.5],
                        ['edi', 2.5],
                        ['automation', 2.0],
                    ],
                ],
                // 6.2 Understand access, ownership and ethical issues
                '6.2' => [
                    'concept' => 'access versus ownership',
                    'keywords' => [
                        ['access versus ownership', 3.0],
                        ['access vs ownership', 3.0],
                        ['access vs. ownership', 3.0],
                        ['perpetual access', 3.0],
                        ['license agreement', 3.0],
                        ['licensing', 2.5],
                        ['fair use', 2.5],
                        ['conflict of interest', 2.5],
                        ['ethical issues', 2.5],
                        ['ownership', 2.0],
                        ['copyright', 2.0],
                    ],
                ],
                // 6.3 Apply censorship in selection and collection development
                '6.3' => [
                    'concept' => 'censorship',
                    'keywords' => [
                        ['censorship', 3.0],
                        ['self-censorship', 3.0],
                        ['banned books', 3.0],
                        ['challenged materials', 3.0],
                        ['intellectual freedom', 3.0],
                        ['library bill of rights', 3.0],
                        ['freedom to read', 3.0],
                        ['censor', 2.5],
                        ['censored', 2.5],
                        ['obscene', 2.0],
                        ['objectionable', 2.0],
                    ],
                ],
                // 6.4 Describe/understand resource sharing
                '6.4' => [
                    'concept' => 'resource sharing',
                    'keywords' => [
                        ['resource sharing', 3.0],
                        ['interlibrary loan', 3.0],
                        ['cooperative acquisition', 3.0],
                        ['cooperative collection development', 3.0],
                        ['farmington plan', 3.0],
                        ['consortium', 2.5],
                        ['consortia', 2.5],
                        ['union list', 2.5],
                        ['document delivery', 2.0],
                    ],
                ],
            ],
        ],
    ];

    /**
     * One subject's keywords keyed by the TOS labels the rest of the app
     * stores: topic label => sub-topic label => [phrase, weight] pairs, in
     * TOS order. Empty for anything that is not a board subject.
     *
     * @return array<string, array<string, list<array{0: string, 1: float}>>>
     */
    public static function forSubject(?string $subject): array
    {
        $key = LleTaxonomy::subjectKey($subject);

        if ($key === null) {
            return [];
        }

        $result = [];

        foreach (LleTaxonomy::topics($key) as $topicLabel => $topic) {
            if ($topic['other']) {
                continue;
            }

            foreach ($topic['subtopics'] as $subtopicLabel => $subtopic) {
                $result[$topicLabel][$subtopicLabel] = self::KEYWORDS[$key][$topic['code']][$subtopic['code']]['keywords'] ?? [];
            }
        }

        return $result;
    }

    /**
     * The short noun phrase for a sub-topic (or, given only a topic, the
     * topic's plain name), for generated questions.
     */
    public static function concept(?string $subject, ?string $topic, ?string $subtopic = null): ?string
    {
        $key = LleTaxonomy::subjectKey($subject);
        $topicEntry = LleTaxonomy::topic($subject, $topic);

        if ($key === null || $topicEntry === null || $topicEntry['other']) {
            return null;
        }

        $subtopicEntry = $subtopic !== null ? LleTaxonomy::subtopic($subject, $topic, $subtopic) : null;

        if ($subtopicEntry === null) {
            return $topicEntry['name'];
        }

        return self::KEYWORDS[$key][$topicEntry['code']][$subtopicEntry['code']]['concept'] ?? $subtopicEntry['name'];
    }

    /** How many keyword phrases there are, across every sub-topic. */
    public static function phraseCount(): int
    {
        $count = 0;

        foreach (self::KEYWORDS as $topics) {
            foreach ($topics as $subtopics) {
                foreach ($subtopics as $entry) {
                    $count += count($entry['keywords']);
                }
            }
        }

        return $count;
    }
}
