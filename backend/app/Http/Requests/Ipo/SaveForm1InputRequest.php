<?php

namespace App\Http\Requests\Ipo;

use App\Models\Project;
use App\Services\Ipo\Form1DataBuilder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveForm1InputRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = Project::find($this->route('id'));

        return $project !== null && $this->user()->can('prepareIpoForms', $project);
    }

    public function rules(): array
    {
        $party = fn (string $prefix) => [
            "{$prefix}.name"                 => 'required|string|max:255',
            "{$prefix}.natural_person"       => 'boolean',
            "{$prefix}.gender"               => 'nullable|in:Male,Female,Others,Prefer not to disclose',
            "{$prefix}.age"                  => 'nullable|integer|min:1|max:120',
            "{$prefix}.nationality"          => 'nullable|string|max:100',
            "{$prefix}.country_of_residence" => 'nullable|string|max:100',
            "{$prefix}.address_house"        => 'nullable|string|max:255',
            "{$prefix}.address_street"       => 'nullable|string|max:500',
            "{$prefix}.city"                 => 'nullable|string|max:100',
            "{$prefix}.state"                => 'nullable|string|max:100',
            "{$prefix}.country"              => 'nullable|string|max:100',
            "{$prefix}.pincode"              => 'nullable|string|max:20',
            "{$prefix}.email"                => 'nullable|email|max:255',
            "{$prefix}.phone"                => 'nullable|string|max:30',
        ];
        $pair = fn (string $key) => [
            "{$key}"                    => 'nullable|array',
            "{$key}.application_number" => 'nullable|string|max:100',
            "{$key}.filing_date"        => 'nullable|date',
        ];

        return [
            'application_type'             => ['required', Rule::in(Form1DataBuilder::APPLICATION_TYPES)],
            'sub_type'                     => ['required', Rule::in(Form1DataBuilder::SUB_TYPES)],
            'title'                        => 'nullable|string|max:1000',
            'applicant_category'           => ['required', Rule::in(Form1DataBuilder::CATEGORIES)],
            'co_applicants'                => 'array|max:20',
            ...$party('co_applicants.*'),
            'inventors_same_as_applicants' => 'boolean',
            'agent_user_ids'               => 'array|max:10',
            'agent_user_ids.*'             => ['integer', Rule::exists('users', 'id')->whereNotNull('patent_agent_no')],
            'convention'                   => 'array|max:20',
            'convention.*.country'         => 'nullable|string|max:100',
            'convention.*.application_number' => 'nullable|string|max:100',
            'convention.*.filing_date'     => 'nullable|date',
            'convention.*.applicant_name'  => 'nullable|string|max:500',
            'convention.*.title'           => 'nullable|string|max:1000',
            ...$pair('pct'),
            ...$pair('original'),
            ...$pair('main'),
            'declarations'                 => 'array',
            'declarations.*'               => [Rule::in(array_keys(Form1DataBuilder::DECLARATIONS))],
            'office'                       => ['nullable', Rule::in(config('ipo.offices'))],
        ];
    }
}
