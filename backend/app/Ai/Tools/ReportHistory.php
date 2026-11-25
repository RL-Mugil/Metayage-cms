<?php

namespace App\Ai\Tools;

use App\Ai\Tool;
use App\Models\InternalDocumentSignature;
use App\Models\ReportExport;
use App\Models\User;
use App\Services\Reports\ReportSigner;

class ReportHistory extends Tool
{
    public function name(): string { return 'report_history'; }

    public function title(): string { return 'My saved reports'; }

    public function description(): string
    {
        return 'Reports the user generated (in the portal or through Claude), newest first: id, name, date, format, rows, filters and whether it is signed. Use download_saved_report or sign_report with the id.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['limit' => $this->limitProp()]];
    }

    public function handle(User $user, array $args): array
    {
        $exports = ReportExport::where('generated_by_id', $user->id)->orderByDesc('id')->limit($this->limit($args))
            ->get(['id', 'report_name', 'report_type', 'format', 'row_count', 'filters', 'created_at']);
        $signed = InternalDocumentSignature::where('document_type', ReportSigner::DOCUMENT_TYPE)->whereIn('document_id', $exports->pluck('id'))
            ->pluck('verification_code', 'document_id');

        return ['reports' => $exports->map(fn ($e) => [
            'export_id' => $e->id, 'report' => $e->report_name, 'type' => $e->report_type, 'format' => $e->format, 'rows' => (int) $e->row_count,
            'created' => $e->created_at?->timezone('Asia/Kolkata')->format('d M Y, H:i'), 'filters' => $e->filters,
            'made_through' => ($e->filters['source'] ?? null) === 'claude' ? 'Claude' : 'portal',
            'signed' => isset($signed[$e->id]), 'verification_code' => $signed[$e->id] ?? null,
        ])->all()];
    }
}
