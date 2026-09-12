<?php

namespace App\Services\QuestionClassification;

/**
 * Free/local stand-in for the AI item classifier: a hand-authored keyword
 * dictionary that mirrors QuestionMappingService::CURATED_TOPICS exactly
 * (same subject/topic/subtopic strings, in the same order) so the two never
 * drift apart silently -- a test asserts the key structures match 1:1.
 *
 * Shape: subject => topic => subtopic => list of [phrase, weight] cues.
 * Every phrase is authored lowercase and matched as a whole-word/whole-phrase
 * substring (see LocalQuestionClassifier::containsPhrase()).
 *
 * Weighting convention:
 *  - full canonical phrase (e.g. "dewey decimal classification")  ~2.5-3.0
 *  - common abbreviation (e.g. "ddc")                              ~2.0-2.5
 *  - looser/partial phrase (e.g. "dewey decimal")                  ~1.5-2.0
 *  - generic adjacent single word, weak topic-level tiebreaker     ~1.0-1.5
 * Multi-word phrases naturally dominate scoring: they carry a higher weight
 * AND their shorter constituent phrases tend to fire alongside them, so
 * their weights stack.
 *
 * @var array<string, array<string, array<string, array<int, array{0: string, 1: float}>>>>
 */
final class TopicKeywordDictionary
{
    public const KEYWORDS = [
        'Cataloging and Classification' => [
            'RDA and Descriptive Cataloging' => [
                'Core Elements of Description' => [
                    ['rda core element', 2.5],
                    ['core elements of description', 2.5],
                    ['descriptive cataloging', 1.5],
                    ['title proper', 1.5],
                    ['statement of responsibility', 1.5],
                ],
                'FRBR/LRM Concepts' => [
                    ['functional requirements for bibliographic records', 3.0],
                    ['work, expression, manifestation', 2.5],
                    ['work expression manifestation item', 2.5],
                    ['frbr', 2.5],
                    ['lrm', 2.0],
                ],
                'Content, Media, and Carrier Types' => [
                    ['content media and carrier type', 3.0],
                    ['content type', 2.0],
                    ['media type', 2.0],
                    ['carrier type', 2.0],
                ],
            ],
            'Classification Systems and Call Numbers' => [
                'Dewey Decimal Classification (DDC)' => [
                    ['dewey decimal classification', 3.0],
                    ['dewey decimal', 2.5],
                    ['ddc', 2.0],
                    ['dewey', 1.5],
                    ['call number', 1.0],
                ],
                'Library of Congress Classification (LCC)' => [
                    ['library of congress classification', 3.0],
                    ['lc classification', 2.5],
                    ['lcc', 2.0],
                    ['congress classification schedule', 2.0],
                ],
                'Universal Decimal Classification (UDC)' => [
                    ['universal decimal classification', 3.0],
                    ['udc notation', 2.0],
                    ['udc', 2.0],
                ],
            ],
            'Subject Headings and Authority Control' => [
                'Library of Congress Subject Headings (LCSH)' => [
                    ['library of congress subject headings', 3.0],
                    ['lcsh', 2.5],
                    ['subject heading list', 1.5],
                ],
                'Authority Records and Cross-References' => [
                    ['authority record', 2.0],
                    ['cross-reference', 1.5],
                    ['see also reference', 2.0],
                    ['see reference', 1.5],
                ],
                'Name Authority Control' => [
                    ['name authority file', 2.5],
                    ['name authority control', 3.0],
                    ['authorized access point', 2.0],
                ],
            ],
            'Cutter Numbers and Shelf Arrangement' => [
                'Cutter-Sanborn Tables' => [
                    ['cutter-sanborn', 3.0],
                    ['cutter sanborn', 3.0],
                    ['cutter table', 2.5],
                    ['cutter number', 2.0],
                ],
                'Shelflisting Practices' => [
                    ['shelflisting', 2.5],
                    ['shelf list', 2.0],
                    ['shelving order', 1.5],
                ],
                'Alternate Shelving Schemes' => [
                    ['alternate shelving', 2.5],
                    ['movable shelving', 2.0],
                    ['browsing arrangement', 1.5],
                    ['shelving by size', 1.5],
                ],
            ],
            'MARC Format and Bibliographic Records' => [
                'MARC 21 Field Structure' => [
                    ['marc 21', 2.5],
                    ['marc field', 2.0],
                    ['marc format', 2.0],
                    ['marc tag', 1.5],
                ],
                'Fixed and Variable Fields' => [
                    ['fixed field', 2.5],
                    ['variable field', 2.5],
                    ['field indicator', 1.5],
                    ['leader', 1.0],
                ],
                'Bibliographic vs Authority Records' => [
                    ['bibliographic versus authority', 2.5],
                    ['bibliographic record', 1.5],
                    ['authority record type', 1.5],
                ],
            ],
            'Edition and Physical Description' => [
                'Edition Statements' => [
                    ['edition statement', 2.5],
                    ['revised edition', 1.5],
                    ['edition area', 2.0],
                ],
                'Physical Description Area' => [
                    ['physical description area', 3.0],
                    ['physical description', 2.0],
                    ['extent of item', 2.0],
                    ['pagination', 1.5],
                    ['illustrative matter', 1.5],
                ],
                'Series Statements' => [
                    ['series statement', 2.5],
                    ['series title', 2.0],
                    ['numbered series', 1.5],
                ],
            ],
        ],
        'Indexing and Abstracting' => [
            'Controlled Vocabulary and Thesauri' => [
                'Preferred and Non-Preferred Terms' => [
                    ['preferred term', 2.5],
                    ['non-preferred term', 2.5],
                    ['use for reference', 1.5],
                    ['use reference', 1.5],
                ],
                'Broader/Narrower/Related Terms' => [
                    ['broader term', 2.5],
                    ['narrower term', 2.5],
                    ['related term', 2.0],
                    ['bt nt rt', 2.0],
                ],
                'Thesaurus Construction' => [
                    ['thesaurus construction', 2.5],
                    ['building a thesaurus', 2.0],
                    ['controlled vocabulary', 1.5],
                ],
            ],
            'Abstract Types and Functions' => [
                'Informative Abstracts' => [
                    ['informative abstract', 2.5],
                    ['informative summary', 1.5],
                ],
                'Indicative Abstracts' => [
                    ['indicative abstract', 2.5],
                    ['descriptive abstract', 2.0],
                ],
                'Critical/Evaluative Abstracts' => [
                    ['critical abstract', 2.5],
                    ['evaluative abstract', 2.5],
                    ['critical evaluation of the document', 1.5],
                ],
            ],
            'Precision, Recall, and Retrieval Quality' => [
                'Precision vs Recall Tradeoff' => [
                    ['precision and recall', 3.0],
                    ['precision vs recall', 3.0],
                    ['precision ratio', 2.0],
                    ['recall ratio', 2.0],
                ],
                'Relevance Ranking' => [
                    ['relevance ranking', 2.5],
                    ['relevance score', 2.0],
                    ['ranked results', 1.5],
                ],
                'Search Result Evaluation' => [
                    ['search result evaluation', 2.5],
                    ['evaluating search results', 2.0],
                    ['retrieval effectiveness', 1.5],
                ],
            ],
            'Citation Indexing' => [
                'Citation Analysis' => [
                    ['citation analysis', 2.5],
                    ['citation count', 1.5],
                    ['impact factor', 1.5],
                ],
                'Bibliographic Coupling' => [
                    ['bibliographic coupling', 3.0],
                ],
                'Co-Citation' => [
                    ['co-citation', 3.0],
                    ['co citation', 3.0],
                ],
            ],
            'Exhaustivity and Specificity' => [
                'Depth of Indexing' => [
                    ['depth of indexing', 2.5],
                    ['indexing depth', 2.0],
                    ['exhaustivity', 2.0],
                ],
                'Term Specificity' => [
                    ['term specificity', 2.5],
                    ['specific term', 1.5],
                ],
                'Indexing Consistency' => [
                    ['indexing consistency', 2.5],
                    ['inter-indexer consistency', 2.5],
                ],
            ],
        ],
        'Information Technology' => [
            'OPAC and Discovery Systems' => [
                'OPAC Search Features' => [
                    ['online public access catalog', 2.5],
                    ['opac search', 2.5],
                    ['opac', 2.0],
                ],
                'Discovery Layer Tools' => [
                    ['discovery layer', 2.5],
                    ['discovery tool', 2.0],
                    ['discovery service', 2.0],
                ],
                'Federated Search' => [
                    ['federated search', 3.0],
                    ['metasearch', 2.0],
                ],
            ],
            'Boolean Search and Query Strategy' => [
                'AND/OR/NOT Operators' => [
                    ['boolean operator', 2.5],
                    ['and/or/not', 2.5],
                    ['boolean search', 2.0],
                ],
                'Truncation and Wildcards' => [
                    ['truncation symbol', 2.5],
                    ['wildcard', 2.0],
                    ['truncate', 1.5],
                    ['asterisk symbol', 1.0],
                ],
                'Nested Queries' => [
                    ['nested query', 2.5],
                    ['nested boolean', 2.5],
                    ['parenthes', 1.0],
                ],
            ],
            'Metadata and Interoperability Standards' => [
                'Dublin Core' => [
                    ['dublin core element set', 2.5],
                    ['dublin core', 2.5],
                    ['dc metadata', 2.0],
                ],
                'MARC-to-Dublin Core Mapping' => [
                    ['marc to dublin core', 3.0],
                    ['metadata crosswalk', 2.5],
                    ['crosswalk', 2.0],
                ],
                'Z39.50/SRU Protocols' => [
                    ['z39.50', 3.0],
                    ['z39 50', 3.0],
                    ['sru protocol', 2.5],
                    ['srw', 1.5],
                ],
            ],
            'Integrated Library Systems' => [
                'ILS Modules' => [
                    ['integrated library system', 2.5],
                    ['ils module', 2.5],
                    ['library system module', 1.5],
                ],
                'Circulation and Cataloging Integration' => [
                    ['circulation module', 2.0],
                    ['cataloging module', 2.0],
                    ['integration between circulation', 2.0],
                ],
                'Vendor ILS Platforms' => [
                    ['vendor ils', 2.5],
                    ['ils vendor', 2.5],
                    ['ils platform', 2.0],
                ],
            ],
            'Digital Security and Data Protection' => [
                'Authentication and Access Control' => [
                    ['access control', 2.0],
                    ['authentication', 2.0],
                    ['login credentials', 1.5],
                ],
                'Phishing and Social Engineering' => [
                    ['phishing', 2.5],
                    ['social engineering', 2.5],
                ],
                'Data Backup Practices' => [
                    ['data backup', 2.5],
                    ['backup practice', 2.0],
                    ['disaster recovery backup', 1.5],
                ],
            ],
            'RFID, Barcodes, and Automation' => [
                'RFID Tagging' => [
                    ['radio frequency identification', 2.5],
                    ['rfid tag', 2.5],
                    ['rfid', 2.0],
                ],
                'Self-Check Kiosks' => [
                    ['self check kiosk', 2.5],
                    ['self-check', 2.0],
                    ['self-checkout', 2.0],
                ],
                'Inventory Automation' => [
                    ['inventory automation', 2.5],
                    ['automated inventory', 2.0],
                    ['shelf reading automation', 1.5],
                ],
            ],
        ],
        'Reference Services' => [
            'Reference Interview and User Needs' => [
                'Open vs Closed Questions' => [
                    ['open versus closed question', 2.5],
                    ['open-ended question', 2.0],
                    ['closed question', 2.0],
                ],
                'Negotiating the Query' => [
                    ['negotiating the query', 2.5],
                    ['reference negotiation', 2.5],
                    ['query negotiation', 2.0],
                ],
                'Nonverbal Cues' => [
                    ['nonverbal cue', 2.5],
                    ['body language', 1.5],
                    ['eye contact', 1.0],
                ],
            ],
            'Ready Reference and Referral' => [
                'Quick-Fact Sources' => [
                    ['ready reference source', 2.5],
                    ['quick-fact', 2.0],
                    ['quick fact source', 2.0],
                ],
                'Interlibrary Referral' => [
                    ['interlibrary referral', 2.5],
                    ['refer the patron to another library', 2.0],
                    ['interlibrary loan', 1.5],
                ],
                'Escalation to Specialists' => [
                    ['escalate to a specialist', 2.5],
                    ['refer to a subject specialist', 2.0],
                ],
            ],
            'Source Evaluation and Information Literacy' => [
                'Authority and Currency Evaluation' => [
                    ['authority and currency', 2.5],
                    ['currency of the source', 2.0],
                    ['evaluating authority', 2.0],
                ],
                'Teaching Search Strategies' => [
                    ['teaching search strategies', 2.5],
                    ['information literacy instruction', 2.0],
                    ['bibliographic instruction', 1.5],
                ],
                'Citation Guidance' => [
                    ['citation guidance', 2.5],
                    ['citation style', 1.5],
                    ['apa format', 1.0],
                    ['mla format', 1.0],
                ],
            ],
            'Current Awareness Services' => [
                'Selective Dissemination of Information (SDI)' => [
                    ['selective dissemination of information', 3.0],
                    ['sdi service', 2.5],
                ],
                'New Acquisitions Alerts' => [
                    ['new acquisitions list', 2.5],
                    ['acquisitions alert', 2.0],
                    ['new arrivals list', 1.5],
                ],
                'RSS/Table-of-Contents Alerts' => [
                    ['table of contents alert', 2.5],
                    ['rss feed', 2.5],
                    ['toc alert', 2.0],
                ],
            ],
            "Reader's Advisory" => [
                'Genre and Appeal Factors' => [
                    ['appeal factor', 2.5],
                    ['genre reading', 1.5],
                    ['readers advisory genre', 2.0],
                ],
                'Read-Alike Recommendations' => [
                    ['read-alike', 3.0],
                    ['readalike', 3.0],
                    ['similar book recommendation', 1.5],
                ],
                'Book Talks' => [
                    ['book talk', 2.5],
                    ['booktalk', 2.5],
                ],
            ],
        ],
        'Library Management' => [
            'Planning and Policy Development' => [
                'Strategic Planning' => [
                    ['strategic planning process', 2.5],
                    ['strategic plan', 2.5],
                ],
                'Collection Development Policy' => [
                    ['collection development policy', 2.5],
                    ['cdp', 1.5],
                ],
                'Mission and Vision Statements' => [
                    ['mission statement', 2.5],
                    ['vision statement', 2.5],
                ],
            ],
            'Budgeting and Resource Allocation' => [
                'Line-Item Budgeting' => [
                    ['line item budgeting', 2.5],
                    ['line-item budget', 2.5],
                ],
                'Cost-Benefit Analysis' => [
                    ['cost-benefit analysis', 3.0],
                    ['cost benefit', 2.0],
                ],
                'Grant and Fund Sourcing' => [
                    ['grant funding', 2.5],
                    ['fund sourcing', 2.0],
                    ['grant proposal', 1.5],
                ],
            ],
            'Staff Development and Supervision' => [
                'Training Programs' => [
                    ['staff training program', 2.5],
                    ['training program', 2.0],
                    ['professional development program', 1.5],
                ],
                'Performance Appraisal' => [
                    ['performance appraisal', 2.5],
                    ['performance evaluation', 2.0],
                ],
                'Delegation and Motivation' => [
                    ['delegation of tasks', 2.0],
                    ['staff motivation', 2.0],
                    ['motivating staff', 2.0],
                ],
            ],
            'Assessment and Performance Indicators' => [
                'Library Performance Metrics' => [
                    ['performance metric', 2.5],
                    ['key performance indicator', 2.0],
                    ['library statistics', 1.5],
                ],
                'SWOT Analysis' => [
                    ['swot analysis', 3.0],
                    ['strengths weaknesses opportunities threats', 2.5],
                ],
                'User Satisfaction Surveys' => [
                    ['user satisfaction survey', 2.5],
                    ['patron satisfaction', 2.0],
                ],
            ],
            'Disaster and Risk Management' => [
                'Disaster Preparedness Plans' => [
                    ['disaster preparedness', 2.5],
                    ['disaster plan', 2.0],
                    ['emergency plan', 1.5],
                ],
                'Collection Salvage Procedures' => [
                    ['collection salvage', 2.5],
                    ['salvage procedure', 2.0],
                    ['water damage recovery', 1.5],
                ],
                'Business Continuity Planning' => [
                    ['business continuity plan', 2.5],
                    ['continuity of operations', 2.0],
                ],
            ],
        ],
        'Selection and Acquisition' => [
            'Selection Criteria and Collection Policy' => [
                'Community/User Needs Assessment' => [
                    ['community needs assessment', 2.5],
                    ['user needs assessment', 2.5],
                    ['needs assessment', 1.5],
                ],
                'Selection Aids and Reviews' => [
                    ['selection aid', 2.5],
                    ['book review source', 1.5],
                    ['review journal', 1.5],
                ],
                'Weeding Criteria' => [
                    ['weeding criteria', 2.5],
                    ['weeding the collection', 2.0],
                    ['deselection', 2.0],
                ],
            ],
            'Acquisition Methods and Ordering Workflow' => [
                'Purchase, Gift, and Exchange' => [
                    ['purchase gift and exchange', 2.5],
                    ['gift and exchange', 2.0],
                    ['exchange program', 1.5],
                ],
                'Standing and Blanket Orders' => [
                    ['standing order', 2.5],
                    ['blanket order', 2.5],
                ],
                'Approval Plans' => [
                    ['approval plan vendor', 2.0],
                    ['approval plan', 2.5],
                ],
            ],
            'Vendor Evaluation and Bibliographic Verification' => [
                'Vendor Reliability Assessment' => [
                    ['vendor reliability', 2.5],
                    ['vendor evaluation', 2.5],
                    ['vendor performance', 1.5],
                ],
                'Pre-Order Bibliographic Verification' => [
                    ['pre-order verification', 2.5],
                    ['bibliographic verification', 2.5],
                    ['verify bibliographic information', 2.0],
                ],
                'Price Comparison' => [
                    ['price comparison', 2.5],
                    ['comparing vendor prices', 2.0],
                ],
            ],
            'Gifts and Donations Management' => [
                'Gift Evaluation Criteria' => [
                    ['gift evaluation', 2.5],
                    ['evaluating donations', 2.0],
                ],
                'Deed of Gift Agreements' => [
                    ['deed of gift', 3.0],
                    ['gift agreement', 2.0],
                ],
                'Disposal of Unwanted Gifts' => [
                    ['disposal of unwanted', 2.5],
                    ['discard unwanted donations', 2.0],
                    ['unwanted gift', 1.5],
                ],
            ],
            'Budgeting for Acquisitions' => [
                'Encumbrance Tracking' => [
                    ['encumbrance tracking', 3.0],
                    ['encumbrance', 2.0],
                ],
                'Fund Allocation by Subject' => [
                    ['subject fund allocation', 2.5],
                    ['fund allocation', 2.0],
                ],
                'Approval and Purchase Orders' => [
                    ['approval and purchase order', 2.5],
                    ['purchase order', 1.5],
                    ['po number', 1.0],
                ],
            ],
        ],
    ];
}
