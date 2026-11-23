<?php

namespace App\Services\Ipo;

use App\Models\Client;
use App\Models\Inventor;
use App\Models\IpoFormInput;
use App\Models\Project;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Assembles everything IPO Form 1 needs from the database (case, client as applicant #1,
 * inventor profiles, patent agents, firm address for service) plus the paralegal's saved
 * input (application type, priority particulars, co-applicants, declarations). The
 * same assembled data is meant to feed Forms 3, 5, 9, 18 later.
 */
class Form1DataBuilder
{
    public const FORM_TYPE = 'form1';

    public const APPLICATION_TYPES = ['ordinary', 'convention', 'pct_np', 'pph'];

    public const SUB_TYPES = ['none', 'divisional', 'patent_of_addition'];

    public const CATEGORIES = ['natural_person', 'other_than_natural', 'educational', 'small_entity', 'startup', 'others'];

    /** 12(iii) declarations — the official wording, in the order the form prints them. */
    public const DECLARATIONS = [
        'possession'          => 'I am/We are in possession of the above-mentioned invention.',
        'spec_filed'          => 'The provisional/complete specification relating to the invention is filed with this application.',
        'biological_material' => 'The invention as disclosed in the specification uses the biological material from India and the necessary permission from the competent authority shall be submitted by me/us before the grant of patent to me/us.',
        'no_objection'        => 'There is no lawful ground of objection(s) to the grant of the Patent to me/us.',
        'true_inventor'       => 'I am/we are the true & first inventor(s).',
        'assignee'            => 'I am/we are the assignee or legal representative of true & first inventor(s).',
        'convention_first'    => 'The application or each of the applications, particulars of which are given in Paragraph-8, was the first application in convention country/countries in respect of my/our invention(s).',
        'convention_priority' => 'I/We claim the priority from the above mentioned application(s) filed in convention country/countries and state that no application for protection in respect of the invention had been made in a convention country before that date by me/us or by any person from which I/We derive the title.',
        'pct_based'           => 'My/our application in India is based on international application under Patent Cooperation Treaty (PCT) as mentioned in Paragraph-9.',
        'divisional'          => 'The application is divided out of my /our application particulars of which is given in Paragraph-10 and pray that this application may be treated as deemed to have been filed on DD/MM/YYYY under section 16 of the Act.',
        'addition'            => 'The said invention is an improvement in or modification of the invention particulars of which are given in Paragraph-11.',
    ];

    public const SERVICE_KEYS = ['name', 'address', 'phone', 'mobile', 'fax', 'email'];

    public function __construct(private readonly PatentOfficeResolver $offices) {}

    /** Saved input for the case, or sensible defaults when nothing has been saved yet. */
    public function input(Project $project): array
    {
        $saved = IpoFormInput::where('project_id', $project->id)->where('form_type', self::FORM_TYPE)->first();

        return $this->normaliseInput($saved?->data ?? [], $project);
    }

    public function normaliseInput(array $data, Project $project): array
    {
        $client = $project->client;
        $type = in_array($data['application_type'] ?? null, self::APPLICATION_TYPES, true) ? $data['application_type'] : 'ordinary';
        $sub = in_array($data['sub_type'] ?? null, self::SUB_TYPES, true) ? $data['sub_type'] : 'none';
        $category = $data['applicant_category'] ?? $client?->applicant_category ?? $this->defaultCategory($client);

        return [
            'application_type'             => $type,
            'sub_type'                     => $sub,
            // A blank title falls back to the one on record for the case.
            'title'                        => filled($data['title'] ?? null) ? $data['title'] : $this->caseTitle($project),
            'applicant_category'           => in_array($category, self::CATEGORIES, true) ? $category : $this->defaultCategory($client),
            'co_applicants'                => array_values($data['co_applicants'] ?? []),
            'inventors_same_as_applicants' => (bool) ($data['inventors_same_as_applicants'] ?? false),
            'agent_user_ids'               => array_values(array_map('intval', $data['agent_user_ids'] ?? [])),
            'convention'                   => array_values($data['convention'] ?? []),
            'pct'                          => $data['pct'] ?? ['application_number' => null, 'filing_date' => null],
            'original'                     => $data['original'] ?? ['application_number' => null, 'filing_date' => null],
            'main'                         => $data['main'] ?? ['application_number' => null, 'filing_date' => null],
            'declarations'                 => array_values(array_intersect(
                $data['declarations'] ?? $this->defaultDeclarations($type, $sub, $category),
                array_keys(self::DECLARATIONS),
            )),
            'office'                       => $data['office'] ?? null,
        ];
    }

    /** Everything the dialog shows: input, applicant, inventors, agent choices, service, office, gaps. */
    public function prefill(Project $project): array
    {
        $input = $this->input($project);
        $form = $this->build($project, $input);

        return [
            'input'           => $input,
            'client'          => $this->clientDetails($project->client),
            'inventors'       => array_column($this->inventorRows($project), 'summary'),
            'agent_options'   => $this->agentOptions(),
            'service'         => $form['service'],
            'resolved_office' => $form['office'],
            'office_source'   => $form['office_source'],
            'offices'         => config('ipo.offices'),
            'declarations'    => self::DECLARATIONS,
            'missing'         => $form['missing'],
        ];
    }

    /** The data the renderer prints. */
    public function build(Project $project, array $input): array
    {
        $client = $project->client;
        $missing = [];

        $applicants = [];
        if ($client) {
            $applicants[] = $this->clientApplicant($client);
        } else {
            $missing[] = 'The case has no client, so there is no applicant.';
        }
        foreach ($input['co_applicants'] as $co) {
            $applicants[] = $this->blankParty($co) + ['email' => $co['email'] ?? null, 'phone' => $co['phone'] ?? null];
        }
        foreach ($applicants as $index => $applicant) {
            $label = $index === 0 ? 'Applicant' : 'Co-applicant '.$index;
            $missing = array_merge($missing, $this->addressGaps($label, $applicant, true));
        }

        $inventors = array_map(fn (array $row) => $row['form'], $this->inventorRows($project));
        if ($inventors === []) {
            $missing[] = 'No inventors are added to this case.';
        }
        foreach ($inventors as $inventor) {
            $missing = array_merge($missing, $this->addressGaps('Inventor '.$inventor['name'], $inventor, false));
        }

        $agents = $this->agentsByIds($input['agent_user_ids']);
        if ($agents === []) {
            $missing[] = 'Select at least one patent agent (section 6).';
        }

        $service = $this->service();
        foreach (['name' => 'name', 'address' => 'postal address', 'email' => 'email ID', 'mobile' => 'mobile no.'] as $key => $label) {
            if (blank($service[$key])) {
                $missing[] = "Address for service {$label} is not set (Settings → IPO Forms).";
            }
        }

        [$office, $source] = $this->office($input, $applicants[0] ?? null);
        if (! $office) {
            $missing[] = 'Choose the Patent Office: the applicant has no Indian state on record.';
        }

        $missing = array_merge($missing, $this->particularGaps($input));

        return [
            'application_type'             => $input['application_type'],
            'sub_type'                     => $input['sub_type'],
            'title'                        => $input['title'],
            'applicants'                   => $applicants,
            'category'                     => $input['applicant_category'],
            'inventors_same_as_applicants' => $input['inventors_same_as_applicants'],
            'inventors'                    => $inventors,
            'agents'                       => $agents,
            'service'                      => $service,
            'convention'                   => $input['application_type'] === 'convention' ? $input['convention'] : [],
            'pct'                          => $input['application_type'] === 'pct_np' ? $input['pct'] : null,
            'original'                     => $input['sub_type'] === 'divisional' ? $input['original'] : null,
            'main'                         => $input['sub_type'] === 'patent_of_addition' ? $input['main'] : null,
            'declarations'                 => $input['declarations'],
            'office'                       => $office,
            'office_source'                => $source,
            'missing'                      => array_values(array_unique($missing)),
        ];
    }

    public static function formatDate(?string $date): string
    {
        if (blank($date)) {
            return '';
        }
        try {
            return Carbon::parse($date)->format('d/m/Y');
        } catch (\Throwable) {
            return (string) $date;
        }
    }

    /**
     * The title on record: the case's own, else the linked patent application's (what the
     * case page's Application Details shows; some imported cases keep it only there).
     */
    public function caseTitle(Project $project): ?string
    {
        $title = trim((string) $project->invention_title);
        if ($title === '') {
            $title = trim((string) $project->patentApplication?->title);
        }

        return $title !== '' ? $title : null;
    }

    /** "Name (IN/PA-1234)" for the agent who signs, or '' when none is chosen. */
    public static function signatory(?array $agent): string
    {
        if (! $agent) {
            return '';
        }

        return $agent['name'].' (IN/PA-'.preg_replace('/^IN\/?PA[-\s]*/i', '', (string) $agent['patent_agent_no']).')';
    }

    /** One-line postal address: house, street, city, state - pin code, country. */
    public static function addressLine(array $party): string
    {
        $state = trim(implode(' - ', array_filter([$party['state'] ?? null, $party['pincode'] ?? null], fn ($v) => filled($v))));
        $parts = [$party['address_house'] ?? null, $party['address_street'] ?? null, $party['city'] ?? null, $state, $party['country'] ?? null];

        return implode(', ', array_filter($parts, fn ($v) => filled($v)));
    }

    private function defaultCategory(?Client $client): string
    {
        return $client?->client_type === 'individual' ? 'natural_person' : 'other_than_natural';
    }

    private function defaultDeclarations(string $type, string $sub, ?string $category): array
    {
        $keys = ['possession', 'spec_filed', 'no_objection', $category === 'natural_person' ? 'true_inventor' : 'assignee'];
        if ($type === 'convention') {
            array_push($keys, 'convention_first', 'convention_priority');
        }
        if ($type === 'pct_np') {
            $keys[] = 'pct_based';
        }
        if ($sub === 'divisional') {
            $keys[] = 'divisional';
        }
        if ($sub === 'patent_of_addition') {
            $keys[] = 'addition';
        }

        return $keys;
    }

    private function clientDetails(?Client $client): ?array
    {
        if (! $client) {
            return null;
        }

        return [
            'id' => $client->id, 'client_code' => $client->client_code, 'client_type' => $client->client_type,
            'legal_name' => $client->legal_name, 'company_name' => $client->company_name,
            'nationality' => $client->nationality, 'address' => $client->address,
            'address_house' => $client->address_house, 'address_street' => $client->address_street,
            'city' => $client->city, 'state' => $client->state, 'country' => $client->country,
            'pincode' => $client->pincode, 'contact_email' => $client->contact_email, 'phone' => $client->phone,
            'applicant_category' => $client->applicant_category,
        ];
    }

    private function clientApplicant(Client $client): array
    {
        $isPerson = $client->client_type === 'individual';

        return [
            'name'                 => $client->legal_name ?: $client->company_name,
            'gender'               => null,
            'age'                  => null,
            'natural_person'       => $isPerson,
            'nationality'          => $client->nationality,
            'country_of_residence' => $client->country ?: $client->nationality,
            'address_house'        => $client->address_house,
            // Older clients only carry the free-text address; print it as the street line.
            'address_street'       => $client->address_street ?: $client->address,
            'city'                 => $client->city,
            'state'                => $client->state,
            'country'              => $client->country ?: $client->nationality,
            'pincode'              => $client->pincode,
            'email'                => $client->contact_email,
            'phone'                => $client->phone,
        ];
    }

    private function blankParty(array $row): array
    {
        return [
            'name'                 => $row['name'] ?? null,
            'gender'               => $row['gender'] ?? null,
            'age'                  => $row['age'] ?? null,
            'natural_person'       => (bool) ($row['natural_person'] ?? false),
            'nationality'          => $row['nationality'] ?? null,
            'country_of_residence' => $row['country_of_residence'] ?? ($row['country'] ?? null),
            'address_house'        => $row['address_house'] ?? null,
            'address_street'       => $row['address_street'] ?? null,
            'city'                 => $row['city'] ?? null,
            'state'                => $row['state'] ?? null,
            'country'              => $row['country'] ?? null,
            'pincode'              => $row['pincode'] ?? null,
        ];
    }

    /** Each inventor on the case, in filing order: the API summary and the printable form row. */
    private function inventorRows(Project $project): array
    {
        return $project->inventors()->get()->map(function (Inventor $inventor) {
            $form = $this->blankParty($inventor->only([
                'gender', 'age', 'nationality', 'country_of_residence',
                'address_house', 'address_street', 'city', 'state', 'country', 'pincode',
            ]));
            $form['name'] = $inventor->full_name;
            $form['natural_person'] = true;

            return ['summary' => $inventor->toSummary(), 'form' => $form];
        })->values()->all();
    }

    private function agentOptions(): array
    {
        return User::whereNotNull('patent_agent_no')->where('patent_agent_no', '!=', '')
            ->where('status', 'Active')->orderBy('name')->get()
            ->map(fn (User $user) => [
                'id' => $user->id, 'name' => $user->name, 'patent_agent_no' => $user->patent_agent_no, 'mobile' => self::agentMobile($user),
                'has_signature' => filled($user->patent_agent_signature_path),
            ])
            ->all();
    }

    /** An agent's mobile: their HRMS employee record first, else their login's own. */
    public static function agentMobile(User $user): ?string
    {
        $mobile = \App\Models\Employee::where('user_id', $user->id)->value('mobile');

        return filled($mobile) ? $mobile : $user->mobile;
    }

    private function agentsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $users = User::whereIn('id', $ids)->whereNotNull('patent_agent_no')->get()->keyBy('id');

        // Keep the order the paralegal picked them in.
        return collect($ids)->map(fn (int $id) => $users->get($id))->filter()
            // signature_path stays server-side: build() output only feeds the DOCX renderers.
            ->map(fn (User $user) => [
                'name' => $user->name, 'patent_agent_no' => $user->patent_agent_no, 'mobile' => self::agentMobile($user),
                'signature_path' => $user->patent_agent_signature_path,
            ])
            ->values()->all();
    }

    private function service(): array
    {
        $rows = DB::table('system_settings')->where('key', 'like', 'ipo_service_%')->pluck('value', 'key');

        return collect(self::SERVICE_KEYS)->mapWithKeys(fn (string $key) => [$key => $rows['ipo_service_'.$key] ?? null])->all();
    }

    /** @return array{0: ?string, 1: string} office and where it came from */
    private function office(array $input, ?array $applicant): array
    {
        if ($this->offices->isOffice($input['office'] ?? null)) {
            return [$input['office'], 'manual'];
        }
        $resolved = $applicant && $this->isIndia($applicant['country'] ?? null) ? $this->offices->forState($applicant['state'] ?? null) : null;

        return [$resolved, $resolved ? 'state' : 'none'];
    }

    private function isIndia(?string $country): bool
    {
        return blank($country) || in_array(strtolower(trim($country)), ['india', 'in', 'indian'], true);
    }

    private function addressGaps(string $label, array $party, bool $isApplicant): array
    {
        $gaps = [];
        $prefix = $label !== '' ? $label.': ' : '';
        if (blank($party['name'] ?? null)) {
            $gaps[] = $prefix.'name is missing.';
        }
        if (blank($party['nationality'] ?? null)) {
            $gaps[] = $prefix.'nationality is missing.';
        }
        if (blank($party['address_house'] ?? null) && blank($party['address_street'] ?? null)) {
            $gaps[] = $prefix.'address (house no. / street) is missing.';
        }
        foreach (['city' => 'city', 'pincode' => 'pin code'] as $key => $name) {
            if (blank($party[$key] ?? null)) {
                $gaps[] = $prefix."{$name} is missing.";
            }
        }
        if ($this->isIndia($party['country'] ?? null) && blank($party['state'] ?? null)) {
            $gaps[] = $prefix.'state is missing.';
        }
        if ($isApplicant && blank($party['email'] ?? null)) {
            $gaps[] = $prefix.'email is missing.';
        }

        return $gaps;
    }

    private function particularGaps(array $input): array
    {
        $gaps = [];
        if ($input['application_type'] === 'convention') {
            if ($input['convention'] === []) {
                $gaps[] = 'Add the convention (priority) application particulars (section 8).';
            }
            foreach ($input['convention'] as $i => $row) {
                if (blank($row['country'] ?? null) || blank($row['application_number'] ?? null) || blank($row['filing_date'] ?? null)) {
                    $gaps[] = 'Priority application '.($i + 1).': country, number and filing date are required.';
                }
            }
        }
        $required = [
            'pct'      => [$input['application_type'] === 'pct_np', 'PCT international application number and filing date (section 9).'],
            'original' => [$input['sub_type'] === 'divisional', 'Original (first) application number and filing date (section 10).'],
            'main'     => [$input['sub_type'] === 'patent_of_addition', 'Main application/patent number and filing date (section 11).'],
        ];
        foreach ($required as $key => [$applies, $message]) {
            if ($applies && (blank($input[$key]['application_number'] ?? null) || blank($input[$key]['filing_date'] ?? null))) {
                $gaps[] = 'Enter the '.$message;
            }
        }

        return $gaps;
    }
}
