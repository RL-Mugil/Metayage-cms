<?php

namespace App\Http\Requests;

use App\Services\TrademarkMatterService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\Project::class);
    }

    public function rules(): array
    {
        return [
            'record_mode'           => 'nullable|in:new,existing',
            'client_id'             => 'required|exists:clients,id',
            'project_code'          => 'nullable|required_if:record_mode,existing|string|max:100|unique:projects,project_code',
            'docket_number'         => 'nullable|required_if:record_mode,existing|string|max:100',
            'project_name'          => 'required|string|max:255',
            'project_type'          => 'required|string|max:100',
            'case_type'             => 'nullable|string|max:100',
            'invention_title'       => 'nullable|string',
            'technology_field'      => 'nullable|string',
            'application_number'    => 'nullable|string|max:100',
            'patent_office_code'    => ['required', 'string', 'regex:/^[A-Za-z0-9]{2}$/'],
            'service_code'          => ['required', 'string', 'regex:/^[A-Za-z0-9]{3,5}$/'],
            'filing_date'           => 'nullable|date',
            'assigned_partner_id'   => 'nullable|exists:users,id',
            'assigned_manager_id'   => 'nullable|exists:users,id',
            'secondary_manager_id'  => 'nullable|exists:users,id',
            'patent_engineer_id'    => 'nullable|exists:users,id',
            'assigned_team'         => 'nullable|array',
            'start_date'            => 'nullable|date',
            'target_filing_date'    => 'nullable|date',
            'hard_deadline'         => 'nullable|date|after_or_equal:today',
            'idf_received_date'     => 'nullable|date',
            'advance_payment_date'  => 'nullable|date',
            'partial_payment_date'  => 'nullable|date',
            'full_payment_date'     => 'nullable|date',
            'urgency'               => 'nullable|string',
            'notes'                 => 'nullable|string',
            'circle'                => 'nullable|in:A,B',
            ...$this->trademarkRules(),
        ];
    }

    /** Mark details for TM* service codes; mark text is mandatory on new filings. */
    private function trademarkRules(): array
    {
        $service = (string) $this->input('service_code');
        if (! TrademarkMatterService::isTrademarkCode($service)) {
            return ['trademark' => 'prohibited'];
        }

        $isFiling = TrademarkMatterService::isFilingCode($service);
        $classCount = match (strtoupper($service)) {
            'TMF' => 'size:1',
            'TMC' => 'min:2',
            default => 'array',
        };

        return [
            'trademark'                => $isFiling ? 'required|array' : 'nullable|array',
            'trademark.mark_text'      => [$isFiling ? 'required' : 'nullable', 'string', 'max:255'],
            'trademark.mark_type'      => [$isFiling ? 'required' : 'nullable', Rule::in(TrademarkMatterService::markTypes())],
            'trademark.nice_classes'   => [$isFiling ? 'required' : 'nullable', 'array', $classCount],
            'trademark.nice_classes.*' => 'integer|between:1,45|distinct',
        ];
    }

    public function messages(): array
    {
        return [
            'trademark.nice_classes.size' => 'A single-class filing (TMF) takes exactly one Nice class — use TMC for several.',
            'trademark.nice_classes.min'  => 'A multi-class filing (TMC) needs at least two Nice classes.',
        ];
    }
}
