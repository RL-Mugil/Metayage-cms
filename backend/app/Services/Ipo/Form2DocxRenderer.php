<?php

namespace App\Services\Ipo;

/**
 * IPO Form 2 (Patents Rules, 2003, rule 13): the provisional or complete specification.
 * Fills the official cover page (title, applicants, preamble with the unused column
 * struck out) from Form1DataBuilder::build() output, then lays out the specification
 * body as headings with placeholders for the drafted text, which is always edited by hand.
 */
class Form2DocxRenderer
{
    public const SPECIFICATIONS = ['provisional', 'complete'];

    private const PREAMBLE = [
        'provisional' => 'The following specification describes the invention.',
        'complete'    => 'The following specification particularly describes the invention and the manner in which it is to be performed.',
    ];

    public function render(array $form, string $specification, string $path): void
    {
        $complete = $specification === 'complete';
        $title = trim((string) ($form['title'] ?? ''));
        $doc = new DocxTableWriter;

        $this->cover($doc, $form, $complete, $title);

        $doc->pageBreak()
            ->paragraph($title !== '' ? mb_strtoupper($title) : '[TITLE OF THE INVENTION]', true, 'center', 12);
        $headings = $complete
            ? ['FIELD OF THE INVENTION', 'BACKGROUND OF THE INVENTION', 'SUMMARY OF THE INVENTION', 'BRIEF DESCRIPTION OF THE DRAWINGS', 'DETAILED DESCRIPTION OF THE INVENTION']
            : ['FIELD OF THE INVENTION', 'BACKGROUND OF THE INVENTION', 'BRIEF DESCRIPTION OF THE DRAWINGS', 'DETAILED DESCRIPTION OF THE INVENTION'];
        foreach ($headings as $heading) {
            $doc->paragraph($heading, true)
                ->paragraph('[Insert '.mb_strtolower($heading).']');
        }

        if ($complete) {
            $doc->pageBreak()
                ->paragraph($this->claimPreamble($form['applicants']), true)
                ->paragraph('1. [Insert claim 1]');
        }

        $this->signature($doc, $form['agents'][0] ?? null);

        if ($complete) {
            $doc->pageBreak()
                ->paragraph('ABSTRACT', true, 'center', 12)
                ->paragraph($title !== '' ? mb_strtoupper($title) : '[TITLE OF THE INVENTION]', true, 'center')
                ->paragraph('[Insert abstract, not more than 150 words]');
        }

        $doc->save($path);
    }

    private function cover(DocxTableWriter $doc, array $form, bool $complete, string $title): void
    {
        $doc->row([[
            'text' => [
                'FORM 2', 'THE PATENT ACT 1970', '(39 of 1970)', '&', 'The Patents Rules, 2003',
                $complete ? '~~PROVISIONAL/~~COMPLETE SPECIFICATION' : 'PROVISIONAL~~/COMPLETE~~ SPECIFICATION',
                '(See section 10 and rule 13)',
            ],
            'span' => DocxTableWriter::GRID, 'bold' => true, 'align' => 'center',
        ]]);

        $doc->full('1. TITLE OF THE INVENTION', true)->full($title);

        $doc->full('2. APPLICANT(S)', true);
        foreach ($form['applicants'] ?: [[]] as $applicant) {
            $doc->full([
                '(a) NAME: '.($applicant['name'] ?? ''),
                '(b) NATIONALITY: '.($applicant['nationality'] ?? ''),
                '(c) ADDRESS: '.Form1DataBuilder::addressLine($applicant),
            ]);
        }

        $doc->full('3. PREAMBLE TO THE DESCRIPTION', true);
        $doc->row([
            ['text' => 'PROVISIONAL', 'span' => 12, 'bold' => true, 'align' => 'center', 'strike' => $complete],
            ['text' => 'COMPLETE', 'span' => 12, 'bold' => true, 'align' => 'center', 'strike' => ! $complete],
        ]);
        $doc->row([
            ['text' => self::PREAMBLE['provisional'], 'span' => 12, 'strike' => $complete],
            ['text' => self::PREAMBLE['complete'], 'span' => 12, 'strike' => ! $complete],
        ]);

        $doc->full('**4. DESCRIPTION** (Description shall start from next page.)')
            ->full('**5. CLAIMS** (not applicable for provisional specification. Claims should start with the preamble — "I/we claim" on separate page)')
            ->full('**6. DATE AND SIGNATURE** (to be given at the end of last page of specification)')
            ->full('**7. ABSTRACT OF THE INVENTION** (to be given along with complete specification on separate page)')
            ->full([
                'Note: -',
                '* Repeat boxes in case of more than one entry.',
                '* To be signed by the applicant(s) or by authorized registered patent agent.',
                '* Name of the applicant should be given in full, family name in the beginning.',
                '* Complete address of the applicant should be given stating the postal index no./code, state and country.',
                '* Strike out the column which is/are not applicable.',
            ]);
    }

    /** "I claim:" for a single individual applicant, otherwise "We claim:". */
    private function claimPreamble(array $applicants): string
    {
        $single = count($applicants) === 1 && ! empty($applicants[0]['natural_person']);

        return $single ? 'I claim:' : 'We claim:';
    }

    private function signature(DocxTableWriter $doc, ?array $agent): void
    {
        $doc->paragraph('')
            ->paragraph(AgentSignature::datedLine())
            ->paragraph('Signature:');
        if ($image = AgentSignature::imageFor($agent)) {
            $doc->image($image);
        }
        $doc->paragraph('Name: '.Form1DataBuilder::signatory($agent))
            ->paragraph($agent ? 'Agent for the Applicant(s)' : '');
    }
}
