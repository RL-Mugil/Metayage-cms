<?php

namespace App\Services\Ipo;

/**
 * Lays out IPO Form 1 (Patents Rules, 2003, rule 20(1)) section by section on the
 * DocxTableWriter grid, from Form1DataBuilder::build() output. Office-use boxes, fees,
 * attachment counts, signatures, the date and place, and declaration 12(ii) are left
 * blank for the paralegal to complete in Word.
 */
class Form1DocxRenderer
{
    private const TYPE_LABELS = ['ordinary' => 'Ordinary', 'convention' => 'Convention', 'pct_np' => 'PCT-NP', 'pph' => 'PPH'];

    public function render(array $form, string $path): void
    {
        $doc = new DocxTableWriter;

        $this->header($doc);
        $this->applicationType($doc, $form);
        $this->applicants($doc, $form);
        $this->category($doc, $form['category']);
        $this->inventors($doc, $form);
        $doc->full('5. TITLE OF THE INVENTION', true, true)->full((string) ($form['title'] ?? ''));
        $this->agents($doc, $form['agents']);
        $this->service($doc, $form['service']);
        $this->particulars($doc, $form);
        $this->declarations($doc, $form);
        $this->attachments($doc, $form);

        $doc->save($path);
    }

    private function tick(bool $on): string
    {
        return $on ? '(✓)' : '( )';
    }

    private function header(DocxTableWriter $doc): void
    {
        $doc->row([
            ['text' => ['**FORM 1**', 'THE PATENTS ACT 1970 (39 of 1970) and', 'THE PATENTS RULES, 2003', '**APPLICATION FOR GRANT OF PATENT**', '(See section 7, 54 and 135 and sub-rule (1) of rule 20)'], 'span' => 13, 'vmerge' => 'restart'],
            ['text' => '(FOR OFFICE USE ONLY)', 'span' => 11],
        ]);
        foreach (['Application No.', 'Filing date:', 'Amount of Fee paid:', 'CBR No:', 'Signature:'] as $label) {
            $doc->row([['span' => 13, 'vmerge' => 'continue'], ['text' => $label, 'span' => 5], ['text' => '', 'span' => 6]]);
        }
        $doc->row([
            ['text' => '1. APPLICANT’S REFERENCE / IDENTIFICATION NO. (AS ALLOTTED BY OFFICE)', 'span' => 13, 'bold' => true],
            ['text' => '', 'span' => 11],
        ]);
    }

    private function applicationType(DocxTableWriter $doc, array $form): void
    {
        $type = $form['application_type'];
        $sub = $form['sub_type'];
        $doc->full('2. TYPE OF APPLICATION [Please tick (✓) at the appropriate category]', true, true);
        $doc->row(array_map(
            fn (string $key) => ['text' => self::TYPE_LABELS[$key].' '.$this->tick($type === $key), 'span' => 6],
            array_keys(self::TYPE_LABELS),
        ));
        $cells = [];
        foreach (['ordinary', 'convention', 'pct_np'] as $key) {
            $cells[] = ['text' => 'Divisional '.$this->tick($type === $key && $sub === 'divisional'), 'span' => 4];
            $cells[] = ['text' => 'Patent of Addition '.$this->tick($type === $key && $sub === 'patent_of_addition'), 'span' => 4];
        }
        $doc->row($cells);
    }

    private function applicants(DocxTableWriter $doc, array $form): void
    {
        $doc->full('3A. APPLICANT(S)', true, true);
        $doc->row([
            ['text' => 'Name in Full', 'span' => 4, 'bold' => true],
            ['text' => 'Gender (optional, for individuals)', 'span' => 3, 'bold' => true],
            ['text' => 'Nationality', 'span' => 3, 'bold' => true],
            ['text' => 'Country of Residence', 'span' => 3, 'bold' => true],
            ['text' => 'Age (optional, for natural persons)', 'span' => 3, 'bold' => true],
            ['text' => 'Address of the Applicant', 'span' => 8, 'bold' => true],
        ]);
        foreach ($form['applicants'] as $applicant) {
            $this->partyRows($doc, $applicant, true, ['Email', 'Contact number']);
        }
    }

    /** One party's block: identity columns merged down beside one row per address line. */
    private function partyRows(DocxTableWriter $doc, array $party, bool $countryBeforeAge, array $contactLabels): void
    {
        $person = (bool) ($party['natural_person'] ?? false);
        $gender = $person ? ((string) ($party['gender'] ?? '') ?: '-') : 'N/A';
        $age = $person ? (blank($party['age'] ?? null) ? '-' : $party['age'].' years') : 'N/A';
        $identity = $countryBeforeAge
            ? [[$party['name'] ?? '', 4], [$gender, 3], [$party['nationality'] ?? '', 3], [$party['country_of_residence'] ?? '', 3], [$age, 3]]
            : [[$party['name'] ?? '', 4], [$gender, 3], [$party['nationality'] ?? '', 3], [$age, 3], [$party['country_of_residence'] ?? '', 3]];

        $lines = [
            'House No.' => $party['address_house'] ?? '', 'Street' => $party['address_street'] ?? '',
            'City' => $party['city'] ?? '', 'State' => $party['state'] ?? '',
            'Country' => $party['country'] ?? '', 'Pin code' => $party['pincode'] ?? '',
        ];
        if ($contactLabels !== []) {
            $lines[$contactLabels[0]] = $party['email'] ?? '';
            $lines[$contactLabels[1]] = $party['phone'] ?? '';
        }

        $first = true;
        foreach ($lines as $label => $value) {
            $cells = array_map(
                fn (array $col) => $first ? ['text' => (string) $col[0], 'span' => $col[1], 'vmerge' => 'restart'] : ['span' => $col[1], 'vmerge' => 'continue'],
                $identity,
            );
            $cells[] = ['text' => $label, 'span' => 3];
            $cells[] = ['text' => (string) $value, 'span' => 5];
            $doc->row($cells);
            $first = false;
        }
    }

    private function category(DocxTableWriter $doc, string $category): void
    {
        $entity = in_array($category, ['other_than_natural', 'small_entity', 'startup', 'others'], true);
        $doc->full('3B. CATEGORY OF APPLICANT [Please tick (✓) at the appropriate category]', true, true);
        $doc->row([
            ['text' => 'Natural Person '.$this->tick($category === 'natural_person'), 'span' => 6],
            ['text' => 'Other than Natural Person '.$this->tick($entity), 'span' => 12, 'align' => 'center'],
            ['text' => 'Educational institution '.$this->tick($category === 'educational'), 'span' => 6],
        ]);
        $doc->row([
            ['text' => '', 'span' => 6],
            ['text' => 'Small Entity '.$this->tick($category === 'small_entity'), 'span' => 4],
            ['text' => 'Startup '.$this->tick($category === 'startup'), 'span' => 4],
            ['text' => 'Others '.$this->tick($category === 'others'), 'span' => 4],
            ['text' => '', 'span' => 6],
        ]);
    }

    private function inventors(DocxTableWriter $doc, array $form): void
    {
        $same = (bool) $form['inventors_same_as_applicants'];
        $doc->full('4. INVENTOR(S) [Please tick (✓) at the appropriate category]', true, true);
        $doc->row([
            ['text' => 'Are all the inventor(s) same as the applicant(s) named above?', 'span' => 8],
            ['text' => 'Yes '.$this->tick($same), 'span' => 8],
            ['text' => 'No '.$this->tick(! $same), 'span' => 8],
        ]);
        if ($same) {
            return;
        }
        $doc->full('If “No”, furnish the details of the inventor(s)', true);
        $doc->row([
            ['text' => 'Name in Full', 'span' => 4, 'bold' => true],
            ['text' => 'Gender (optional, for natural persons)', 'span' => 3, 'bold' => true],
            ['text' => 'Nationality', 'span' => 3, 'bold' => true],
            ['text' => 'Age (optional, for natural persons)', 'span' => 3, 'bold' => true],
            ['text' => 'Country of Residence', 'span' => 3, 'bold' => true],
            ['text' => 'Address of the Inventor', 'span' => 8, 'bold' => true],
        ]);
        foreach ($form['inventors'] as $inventor) {
            $this->partyRows($doc, $inventor, false, []);
        }
    }

    private function agents(DocxTableWriter $doc, array $agents): void
    {
        $agents = $agents ?: [['patent_agent_no' => '', 'name' => '', 'mobile' => '']];
        $first = true;
        foreach ($agents as $agent) {
            foreach (['IN/PA No.' => $agent['patent_agent_no'], 'Name' => $agent['name'], 'Mobile No. (OTP verification mandatory - will be redacted)' => $agent['mobile']] as $label => $value) {
                $doc->row([
                    $first ? ['text' => '6. AUTHORISED REGISTERED PATENT AGENT(S)', 'span' => 12, 'bold' => true, 'vmerge' => 'restart'] : ['span' => 12, 'vmerge' => 'continue'],
                    ['text' => $label, 'span' => 5],
                    ['text' => (string) $value, 'span' => 7],
                ]);
                $first = false;
            }
        }
    }

    private function service(DocxTableWriter $doc, array $service): void
    {
        $lines = [
            'Name' => $service['name'], 'Postal Address' => $service['address'], 'Telephone No.' => $service['phone'],
            'Mobile No. (OTP verification mandatory - will be redacted)' => $service['mobile'], 'Fax No.' => $service['fax'],
            'E-mail ID (OTP verification mandatory - will be redacted)' => $service['email'],
        ];
        $first = true;
        foreach ($lines as $label => $value) {
            $doc->row([
                $first ? ['text' => '7. ADDRESS FOR SERVICE OF APPLICANT IN INDIA', 'span' => 12, 'bold' => true, 'vmerge' => 'restart'] : ['span' => 12, 'vmerge' => 'continue'],
                ['text' => $label, 'span' => 5],
                ['text' => (string) $value, 'span' => 7],
            ]);
            $first = false;
        }
    }

    private function particulars(DocxTableWriter $doc, array $form): void
    {
        $doc->full('8. IN CASE OF APPLICATION CLAIMING PRIORITY OF APPLICATION FILED IN CONVENTION COUNTRY, PARTICULARS OF CONVENTION APPLICATION', true, true);
        $doc->row(array_map(fn (string $h) => ['text' => $h, 'span' => 4, 'bold' => true],
            ['Country', 'Application Number', 'Filing date', 'Name of the applicant', 'Title of the invention', 'IPC (as classified in the convention country)']));
        foreach ($form['convention'] ?: [[]] as $row) {
            $doc->row(array_map(fn (string $value) => ['text' => $value, 'span' => 4], [
                (string) ($row['country'] ?? ''), (string) ($row['application_number'] ?? ''),
                Form1DataBuilder::formatDate($row['filing_date'] ?? null), (string) ($row['applicant_name'] ?? ''),
                (string) ($row['title'] ?? ''), '',
            ]));
        }

        $pairs = [
            ['9. IN CASE OF PCT NATIONAL PHASE APPLICATION, PARTICULARS OF INTERNATIONAL APPLICATION FILED UNDER PATENT CO-OPERATION TREATY (PCT)', 'International application number', 'International filing date', $form['pct']],
            ['10. IN CASE OF DIVISIONAL APPLICATION FILED UNDER SECTION 16, PARTICULARS OF ORIGINAL (FIRST) APPLICATION', 'Original (first) application No.', 'Date of filing of original (first) application', $form['original']],
            ['11. IN CASE OF PATENT OF ADDITION FILED UNDER SECTION 54, PARTICULARS OF MAIN APPLICATION OR PATENT', 'Main application/patent No.', 'Date of filing of main application', $form['main']],
        ];
        foreach ($pairs as [$heading, $numberLabel, $dateLabel, $values]) {
            $doc->full($heading, true, true);
            $doc->row([['text' => $numberLabel, 'span' => 12, 'bold' => true], ['text' => $dateLabel, 'span' => 12, 'bold' => true]]);
            $doc->row([
                ['text' => (string) ($values['application_number'] ?? ''), 'span' => 12],
                ['text' => Form1DataBuilder::formatDate($values['filing_date'] ?? null), 'span' => 12],
            ]);
        }
    }

    private function declarations(DocxTableWriter $doc, array $form): void
    {
        $doc->full('12. DECLARATIONS', true, true);

        $inventor = [
            '**(i) Declaration by the inventor(s)**',
            '**(In case the applicant is an assignee:** the inventor(s) may sign herein below or the applicant may upload the assignment or enclose the assignment with this application for patent or send the assignment by post/electronic transmission duly authenticated within the prescribed period).',
            'I/We, the above named inventor(s) is/are the true & first inventor(s) for this Invention and declare that the applicant(s) herein is/are my/our assignee or legal representative.',
        ];
        foreach ($form['inventors'] ?: [['name' => '']] as $row) {
            array_push($inventor, '', '(a) Date', '(b) Signature(s)', '(c) Name(s): '.($row['name'] ?? ''));
        }
        $doc->full($inventor);

        $doc->full([
            '**(ii) Declaration by the applicant(s) in the convention country**',
            '**(In case the applicant in India is different than the applicant in the convention country:** the applicant in the convention country may sign herein below or applicant in India may upload the assignment from the applicant in the convention country or enclose the said assignment with this application for patent or send the assignment by post/electronic transmission duly authenticated within the prescribed period)',
            'I/We, the applicant(s) in the convention country declare that the applicant(s) herein is/are my/our assignee or legal representative.',
            '(a) Date', '(b) Signature(s)', '(c) Name(s) of the signatory',
        ]);

        $deemed = Form1DataBuilder::formatDate($form['original']['filing_date'] ?? null);
        $lines = ['**(iii) Declaration by the applicant(s)**', 'I/We the applicant(s) hereby declare(s) that: -'];
        foreach (Form1DataBuilder::DECLARATIONS as $key => $text) {
            if ($key === 'divisional' && $deemed !== '') {
                $text = str_replace('DD/MM/YYYY', $deemed, $text);
            }
            $lines[] = (in_array($key, $form['declarations'], true) ? '☑ ' : '☐ ').$text;
        }
        $doc->full($lines);
    }

    private function attachments(DocxTableWriter $doc, array $form): void
    {
        $doc->full(['**13. FOLLOWING ARE THE ATTACHMENTS WITH THE APPLICATION**', '(a) Form 2'], false, true);
        $doc->row(array_map(fn (string $h) => ['text' => $h, 'span' => 6, 'bold' => true], ['Item', 'Details', 'Fee', 'Remarks']));
        foreach ([
            ['Complete/ provisional specification)#', 'No. of pages'],
            ['No. of Claim(s)', 'No. of claims and No. of pages'],
            ['Abstract', 'No. of pages'],
            ['No. of Drawing(s)', 'No. of drawings and No. of pages'],
        ] as [$item, $details]) {
            $doc->row([['text' => $item, 'span' => 6], ['text' => $details, 'span' => 6], ['text' => '', 'span' => 6], ['text' => '', 'span' => 6]]);
        }

        $agent = $form['agents'][0] ?? null;
        $signatory = Form1DataBuilder::signatory($agent);
        $doc->full([
            '# In case of a complete specification, if the applicant desires to adopt the drawings filed with his provisional specification as the drawings or part of the drawings for the complete specification under rule 13(4), the number of such pages filed with the provisional specification are required to be mentioned here.',
            '',
            '(b) Complete specification (in conformation with the international application)/as amended before the International Preliminary Examination Authority (IPEA), as applicable (2 copies).',
            '(c) Sequence listing in electronic form',
            '(d) Drawings (in conformation with the international application)/as amended before the International Preliminary Examination Authority (IPEA), as applicable (2 copies).',
            '(e) Priority document(s) or a request to retrieve the priority document(s) from DAS (Digital Access Service) if the applicant had already requested the office of first filing to make the priority document(s) available to DAS.',
            '(f) Translation of priority document/Specification/International Search Report/International Preliminary Report on Patentability.',
            '(g) Statement and Undertaking on Form 3',
            '(h) Declaration of Inventorship on Form 5',
            '(i) Power of Authority',
            '(j) ………………………………………………………………',
            '',
            'Total fee ₹………. in Cash/ Banker\'s Cheque /Bank Draft bearing No………. Date………… on …………. Bank.',
            '',
            'I/We hereby declare that to the best of my/our knowledge, information and belief the fact and matters stated herein are correct and I/We request that a patent may be granted to me/us for the said invention.',
            '',
            'Dated this ………… day of ………… 20……',
            'Signature:',
            'Name: '.$signatory,
            $agent ? 'Agent for the Applicant(s)' : '',
            '',
            'To,',
            'The Controller of Patents',
            'The Patent Office, at '.($form['office'] ?: '……………'),
            '',
            'Note: -',
            '* Repeat boxes in case of more than one entry.',
            '* To be signed by the applicant(s) or by authorized registered patent agent otherwise where mentioned.',
            '* Tick (✓)/cross (x) whichever is applicable/not applicable in declaration in paragraph-12.',
            '* Name of the inventor and applicant should be given in full, family name in the beginning.',
            '* Strike out the portion which is/are not applicable.',
            '* For fee: See First Schedule.',
        ]);
    }
}
