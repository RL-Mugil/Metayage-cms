<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\MatterCost;
use App\Models\PatentInvoiceIn;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\DataAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Matter (case) profitability, INR: fees billed without GST, less official fees paid, foreign
 * associate and other costs, and staff time at each designation's internal cost rate. An invoice
 * with no recorded costs on its case falls back to its own attorney / consultant / referral fees.
 * Also: recoverable costs not yet billed (leakage). Within the user's finance scope.
 */
class MatterProfitability
{
    public const RATES_KEY = 'time_cost_rates';

    /** @return array{default: float, designations: array<string, float>} */
    public static function rates(): array
    {
        $saved = json_decode((string) DB::table('system_settings')->where('key', self::RATES_KEY)->value('value'), true) ?: [];

        return ['default' => (float) ($saved['default'] ?? 0), 'designations' => array_map('floatval', $saved['designations'] ?? [])];
    }

    /** Projects the user may see finance for. */
    public function projects(User $user)
    {
        $query = Project::query();
        if (! DataAccess::seesAllFinance($user)) {
            if ($user->isGalvanizer()) {
                $query->where(fn ($q) => $user->applyProjectScope($q));
            } elseif (DataAccess::canOpenFinance($user)) {
                DataAccess::scopeManagedCases($query, $user);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        return $query;
    }

    /**
     * One row per matter.
     *
     * @param  array{from?: ?string, to?: ?string, client_id?: ?int, project_id?: ?int}  $f
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(User $user, array $f = []): Collection
    {
        $from = $f['from'] ?? null;
        $to = $f['to'] ?? null;
        $projects = $this->projects($user)
            ->when($f['client_id'] ?? null, fn ($q, $v) => $q->where('client_id', $v))
            ->when($f['project_id'] ?? null, fn ($q, $v) => $q->whereKey($v))
            ->with(['client:id,client_code,legal_name,company_name,account_manager_id', 'client.accountManager:id,name'])
            ->get(['id', 'client_id', 'docket_number', 'project_name', 'service_code', 'patent_office_code', 'status', 'assigned_manager_id']);
        $ids = $projects->pluck('id');
        if ($ids->isEmpty()) {
            return collect();
        }

        $invoices = PatentInvoiceIn::where('type', 'invoice')->whereNotIn('status', ['Draft', 'Cancelled'])->whereIn('project_id', $ids)
            ->when($from, fn ($q) => $q->where('invoice_date', '>=', $from))->when($to, fn ($q) => $q->where('invoice_date', '<=', $to))
            ->get()->groupBy('project_id');
        $costs = MatterCost::whereIn('project_id', $ids)
            ->when($from, fn ($q) => $q->where('bill_date', '>=', $from))->when($to, fn ($q) => $q->where('bill_date', '<=', $to))
            ->get()->groupBy('project_id');
        $time = TimeEntry::whereIn('project_id', $ids)
            ->when($from, fn ($q) => $q->where('entry_date', '>=', $from))->when($to, fn ($q) => $q->where('entry_date', '<=', $to))
            ->get(['project_id', 'user_id', 'duration_hours'])->groupBy('project_id');

        $rates = self::rates();
        $designationOf = Employee::whereNotNull('user_id')->pluck('designation_id', 'user_id');
        $rateFor = fn ($userId) => $rates['designations'][(string) ($designationOf[$userId] ?? '')] ?? $rates['default'];

        return $projects->map(function (Project $p) use ($invoices, $costs, $time, $rateFor) {
            $inv = $invoices->get($p->id, collect());
            $cost = $costs->get($p->id, collect());
            $hours = $time->get($p->id, collect());
            $inr = fn (PatentInvoiceIn $i) => (float) ($i->exchange_rate ?? 1) ?: 1.0;

            $billed = round($inv->sum(fn ($i) => (float) ($i->total_bcy ?? $i->invoice_amount)
                - ((float) $i->igst_amount + (float) $i->cgst_amount + (float) $i->sgst_amount) * $inr($i)), 2);
            $outstanding = round($inv->sum(fn ($i) => (float) ($i->balance_due ?? 0) * $inr($i)), 2);
            $officialBilled = round($inv->sum(fn ($i) => (float) $i->patent_office_fees * $inr($i)), 2);
            $official = round((float) $cost->where('kind', 'official_fee')->sum('amount_bcy'), 2);
            $associate = round((float) $cost->where('kind', 'associate')->sum('amount_bcy'), 2);
            $other = round((float) $cost->where('kind', 'other')->sum('amount_bcy'), 2);
            // No costs recorded on the case: the invoices' own internal fees stand in for associate cost.
            $legacy = $cost->isEmpty() ? round($inv->sum(fn ($i) => (float) $i->attorney_fees + (float) $i->consultant_fees + (float) $i->referral_fees), 2) : 0.0;
            $timeHours = round((float) $hours->sum('duration_hours'), 2);
            $timeCost = round($hours->sum(fn ($t) => (float) $t->duration_hours * $rateFor($t->user_id)), 2);
            $margin = round($billed - $official - $associate - $other - $legacy - $timeCost, 2);
            // Leakage: recoverable official fees paid beyond what was billed, and recoverable costs left unbilled 30+ days.
            $officialRecoverable = round((float) $cost->where('kind', 'official_fee')->where('recoverable', true)->sum('amount_bcy'), 2);
            $unbilledOld = round((float) $cost->where('recoverable', true)->whereNull('billed_invoice_id')
                ->filter(fn ($c) => $c->bill_date && $c->bill_date->lt(now()->subDays(30)))->sum('amount_bcy'), 2);

            return [
                'project_id' => $p->id, 'docket' => $p->docket_number, 'name' => $p->project_name, 'status' => $p->status,
                'service' => $p->service_code, 'office' => $p->patent_office_code,
                'client' => $p->client ? ['id' => $p->client->id, 'code' => $p->client->client_code, 'name' => $p->client->legal_name ?: $p->client->company_name] : null,
                'manager' => $p->client?->accountManager?->name,
                'invoices' => $inv->count(), 'billed' => $billed, 'collected' => round($billed - $outstanding, 2), 'outstanding' => $outstanding,
                'official_billed' => $officialBilled, 'official_paid' => $official, 'associate' => round($associate + $legacy, 2), 'other' => $other,
                'hours' => $timeHours, 'time_cost' => $timeCost, 'margin' => $margin,
                'margin_pct' => $billed > 0 ? round($margin / $billed * 100, 1) : null,
                'leakage' => round(max(0, $officialRecoverable - $officialBilled), 2), 'unbilled_costs_30d' => $unbilledOld,
            ];
        })->filter(fn ($r) => $r['invoices'] > 0 || $r['official_paid'] > 0 || $r['associate'] > 0 || $r['other'] > 0 || $r['hours'] > 0)->values();
    }

    /**
     * Matters rolled up by client, service, office, Client Manager or not at all.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function grouped(Collection $rows, string $by): Collection
    {
        if ($by === 'matter') {
            return $rows;
        }
        $key = fn ($r) => match ($by) {
            'client' => $r['client'] ? $r['client']['code'].' · '.$r['client']['name'] : '—',
            'service' => $r['service'] ?: '—',
            'office' => $r['office'] ?: '—',
            'manager' => $r['manager'] ?: '—',
            default => '—',
        };
        $sum = ['invoices', 'billed', 'collected', 'outstanding', 'official_billed', 'official_paid', 'associate', 'other', 'hours', 'time_cost', 'margin', 'leakage', 'unbilled_costs_30d'];

        return $rows->groupBy($key)->map(function (Collection $g, $label) use ($sum) {
            $row = ['group' => (string) $label, 'matters' => $g->count()];
            foreach ($sum as $k) {
                $row[$k] = round((float) $g->sum($k), 2);
            }
            $row['margin_pct'] = $row['billed'] > 0 ? round($row['margin'] / $row['billed'] * 100, 1) : null;

            return $row;
        })->sortByDesc('billed')->values();
    }
}
