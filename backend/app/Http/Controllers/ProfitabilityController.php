<?php

namespace App\Http\Controllers;

use App\Services\MatterProfitability;
use App\Support\DataAccess;
use Illuminate\Http\Request;

/** Finance → Profitability: margin per matter, or rolled up by client, service, office or Client Manager. */
class ProfitabilityController extends Controller
{
    public function __construct(private MatterProfitability $profit) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless(DataAccess::canOpenFinance($user), 403, 'You do not have access to finance.');
        $f = $request->validate(['by' => 'nullable|in:matter,client,service,office,manager', 'from' => 'nullable|date', 'to' => 'nullable|date',
            'client_id' => 'nullable|integer', 'project_id' => 'nullable|integer', 'export' => 'nullable|in:xlsx', 'refresh' => 'nullable|boolean']);
        if (! empty($f['refresh'])) {
            MatterProfitability::bump();
        }
        $rows = $this->profit->rows($user, $f);
        $grouped = $this->profit->grouped($rows, $f['by'] ?? 'matter');

        if (($f['export'] ?? null) === 'xlsx') {
            $flat = $grouped->map(fn ($r) => collect($r)->map(fn ($v) => is_array($v) ? trim(($v['code'] ?? '').' '.($v['name'] ?? '')) : $v)->all())->all();
            $bytes = app(\App\Services\Reports\GenericReport::class)->excel('Matter profitability', $flat,
                ['from_date' => $f['from'] ?? null, 'to_date' => $f['to'] ?? null], app(\App\Services\Letterhead::class)->get(), null,
                app(\App\Services\Reports\SignatureSeal::class)->png(false));

            return response($bytes, 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="Profitability-'.now()->format('Ymd').'.xlsx"', 'Cache-Control' => 'no-store']);
        }

        $rates = MatterProfitability::rates();

        return response()->json([
            'rows' => $grouped,
            'total' => $this->profit->grouped($rows, 'office')->reduce(function ($carry, $g) {
                foreach ($g as $k => $v) {
                    if (is_numeric($v) && $k !== 'margin_pct') {
                        $carry[$k] = round(($carry[$k] ?? 0) + $v, 2);
                    }
                }

                return $carry;
            }, []),
            'rates_set' => $rates['default'] > 0 || $rates['designations'] !== [],
        ]);
    }
}
