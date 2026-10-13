<?php

/*
 * Trademark service lifecycles (Trade Marks Act 1999 / Trade Marks Rules 2017).
 *
 * Single source for the IN lifecycle-template seed migration and for
 * ProjectController::stagesForServiceCode()'s fallback, so non-templated
 * offices still get trademark (not patent) stages.
 */

$filing = [
    'Instruction & mark details received',
    'Clearance search & class selection',
    'TM-A & TM-48 (POA) prepared',
    'Client approval',
    'TM-A filed – application no. recorded',
    'Formalities check / Vienna codification',
    'Examination',
    'Accepted & advertised in TM Journal',
    'Opposition window (4 months)',
    'Registered – certificate issued',
];

return [
    // Service codes whose New Case form must capture mark text (new filings).
    'filing_codes' => ['TMF', 'TMC', 'TMMD'],

    // Mark types; those flagged true need a mark representation image.
    'mark_types' => [
        'Word' => false,
        'Device' => true,
        'Composite' => true,
        'Shape' => true,
        'Colour' => true,
        'Sound' => false,
    ],

    // Stage after which a non-word mark must have its image on file.
    'image_gate_stage' => 'TM-A filed – application no. recorded',

    'templates' => [
        'TMF' => ['India trademark filing', $filing],
        'TMC' => ['India multi-class trademark filing', $filing],
        'TMS' => ['India trademark search', [
            'Instruction received', 'IP India public search', 'Conflicting marks analysed',
            'Search report drafted', 'Internal review', 'Report sent to client', 'Closed',
        ]],
        'TMD' => ['India trademark advice', [
            'Instruction received', 'Mark & class analysis', 'Advice drafted',
            'Internal review', 'Advice sent to client', 'Closed',
        ]],
        'TME' => ['India TM examination report reply', [
            'Examination report docketed (1-month deadline)', 'Objections analysed (S.9 / S.11)',
            'Reply & user evidence drafted', 'Internal review', 'Client approval', 'Reply filed',
            'Awaiting Registrar decision',
        ]],
        'TMJ' => ['India TM journal monitoring', [
            'Journal parameters set', 'Journal reviewed', 'Conflicting marks flagged',
            'Report to client', 'Closed',
        ]],
        'TMO' => ['India TM opposition filing', [
            'Conflicting mark identified (4-month deadline)', 'Grounds analysed',
            'Notice of opposition (TM-O) drafted', 'Client approval', 'TM-O filed',
            'Counter-statement received', 'Evidence (Rule 45)', 'Hearing', 'Decision',
        ]],
        'TMOR' => ['India TM opposition counter-statement', [
            'Notice of opposition received (2-month deadline)', 'Grounds analysed',
            'Counter-statement drafted', 'Client approval', 'Counter-statement filed',
            'Evidence (Rules 46–47)', 'Hearing', 'Decision',
        ]],
        'TMH' => ['India TM hearing', [
            'Hearing notice docketed', 'Strategy & evidence prepared', 'Written submissions drafted',
            'Internal review', 'Client preparation', 'Hearing attended', 'Awaiting order',
        ]],
        'TMG' => ['India TM registration certificate', [
            'Registration monitored', 'Certificate downloaded & verified', 'Sent to client',
            'Renewal date docketed', 'Closed',
        ]],
        'TMRN' => ['India TM renewal', [
            'Renewal instruction confirmed', 'Due date verified (10-year term)',
            'Client authority & fees confirmed', 'TM-R filed', 'Renewal recorded',
            'Next renewal docketed', 'Closed',
        ]],
        'TMA' => ['India TM assignment', [
            'Assignment instruction', 'Deed drafted & stamped', 'Client execution',
            'TM-P filed', 'Register updated', 'Closed',
        ]],
        'TMWT' => ['India TM watch service', [
            'Watch set up', 'Periodic journal review', 'Conflicts reported',
            'Client instructions', 'Closed',
        ]],
        'TMINF' => ['India TM infringement action', [
            'Infringement identified', 'Evidence gathered', 'Cease-and-desist drafted',
            'Client approval', 'Notice served', 'Response monitored', 'Escalated / closed',
        ]],
        'TMMD' => ['Madrid Protocol international filing', [
            'Base application verified', 'MM2 prepared', 'Client approval',
            'Filed via IP India (office of origin)', 'Certified & sent to WIPO',
            'International registration', 'Designated-office decisions monitored', 'Closed',
        ]],
    ],

    // [from, to, description]. No event/status gate: ServiceTransitionService checks
    // those against the patent application, which trademark cases don't have.
    'transitions' => [
        ['TMS', 'TMF', 'File the mark after a clear search.'],
        ['TMF', 'TME', 'Reply to the examination report.'],
        ['TMC', 'TME', 'Reply to the examination report.'],
        ['TME', 'TMH', 'Attend the show-cause hearing.'],
        ['TMF', 'TMOR', 'File the counter-statement to an opposition.'],
        ['TMC', 'TMOR', 'File the counter-statement to an opposition.'],
        ['TMF', 'TMG', 'Collect the registration certificate.'],
        ['TMC', 'TMG', 'Collect the registration certificate.'],
        ['TME', 'TMG', 'Collect the registration certificate.'],
        ['TMH', 'TMG', 'Collect the registration certificate.'],
        ['TMOR', 'TMG', 'Collect the registration certificate.'],
        ['TMG', 'TMRN', 'Start the 10-year renewal service.'],
        ['TMRN', 'TMRN', 'Create the next renewal service engagement.'],
    ],
];
