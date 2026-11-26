<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\InvoiceSettlement;
use App\Services\Letterhead;
use App\Services\Reports\GenericReport;
use App\Services\Reports\SignatureSeal;
use App\Services\TallyExport;
use App\Support\FinancePeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Financial Suite → Close & controls → Export to Tally (Accounts): a month's vouchers as TallyPrime XML, with a summary Excel. */
class TallyExportController extends Controller
{
    private function allow(Request $request): void
    {
        abort_unless(InvoiceSettlement::canPay($request->user()), 403, 'Tally export is for Accounts.');
    }

    public function export(Request $request, TallyExport $tally)
    {
        $this->allow($request);
        $data = $request->validate(['month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'], 'format' => 'nullable|in:xml,xlsx,summary']);
        $vouchers = $tally->vouchers($data['month']);
        $format = $data['format'] ?? 'xml';

        if ($format === 'summary') {
            return response()->json(['month' => $data['month'], 'locked' => FinancePeriod::isLocked($data['month'].'-01'), 'vouchers' => $vouchers->count(),
                'by_type' => $vouchers->groupBy('type')->map->count(), 'ledgers' => TallyExport::ledgers()]);
        }
        AuditLog::create(['user_id' => $request->user()->id, 'action' => 'tally_export', 'subject_type' => 'Finance', 'subject_id' => 0,
            'metadata' => ['month' => $data['month'], 'format' => $format, 'vouchers' => $vouchers->count()], 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);

        if ($format === 'xlsx') {
            $bytes = app(GenericReport::class)->excel('Tally vouchers '.$data['month'], $tally->rows($vouchers), ['from_date' => $data['month'].'-01', 'to_date' => null],
                app(Letterhead::class)->get(), null, app(SignatureSeal::class)->png(false));

            return response($bytes, 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="Tally-'.$data['month'].'.xlsx"', 'Cache-Control' => 'no-store']);
        }

        return response($tally->xml($vouchers, (string) (DB::table('system_settings')->where('key', 'tally_company_name')->value('value') ?? '')), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="Tally-'.$data['month'].'.xml"', 'Cache-Control' => 'no-store']);
    }

    public function saveLedgers(Request $request): JsonResponse
    {
        $this->allow($request);
        $rules = collect(TallyExport::DEFAULT_LEDGERS)->mapWithKeys(fn ($v, $k) => [$k => 'nullable|string|max:120'])->all();
        $data = $request->validate($rules + ['company' => 'nullable|string|max:120']);
        DB::table('system_settings')->updateOrInsert(['key' => TallyExport::LEDGERS_KEY],
            ['value' => json_encode(array_intersect_key($data, TallyExport::DEFAULT_LEDGERS)), 'updated_at' => now()]);
        DB::table('system_settings')->updateOrInsert(['key' => 'tally_company_name'], ['value' => $data['company'] ?? '', 'updated_at' => now()]);
        AuditLog::create(['user_id' => $request->user()->id, 'action' => 'tally_ledgers_updated', 'subject_type' => 'Finance', 'subject_id' => 0,
            'metadata' => [], 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);

        return response()->json(TallyExport::ledgers());
    }
}
