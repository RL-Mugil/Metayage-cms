<?php

namespace App\Ai\Tools;

use App\Ai\Tool;
use App\Models\Client;
use App\Models\User;
use App\Services\FinanceFigures;
use App\Support\DataAccess;
use Illuminate\Support\Facades\Gate;

class ClientStatement extends Tool
{
    public function name(): string { return 'client_statement'; }

    public function title(): string { return 'Client statement of account'; }

    public function description(): string
    {
        return 'Statement of account for one client the user may see: billed, received, outstanding, ageing, every invoice with its balance, and payments received. '
            .'Set file to pdf or xlsx to also get a letterhead statement file (Payment Collection report for that client, unsigned draft, 30-minute link).';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'client_id' => ['type' => 'integer'],
            'client_code' => ['type' => 'string'],
            'file' => ['type' => 'string', 'enum' => ['none', 'pdf', 'xlsx']],
        ]];
    }

    public function availableTo(User $user): bool
    {
        return DataAccess::canOpenFinance($user);
    }

    public function readOnly(): bool
    {
        return false;
    }

    public function handle(User $user, array $args): array
    {
        $client = ! empty($args['client_id']) ? Client::findOrFail((int) $args['client_id'])
            : Client::where('client_code', (string) $this->str($args, 'client_code'))->firstOrFail();
        Gate::forUser($user)->authorize('view', $client);
        $figures = app(FinanceFigures::class);
        $today = now()->startOfDay();

        $invoices = $figures->rows($user, $client->id)->sortBy(fn ($r) => $r['date'] ? $r['date']->format('Y-m-d') : '0000')->values();
        $payments = $figures->payments($user, $client->id)->with('invoice:id,invoice_uin')->orderBy('payment_date')
            ->get(['patent_invoice_in_id', 'payment_date', 'amount', 'currency', 'amount_bcy', 'payment_mode', 'reference']);

        $out = [
            'client' => $client->client_code.' · '.($client->legal_name ?: $client->company_name), 'currency' => 'INR',
            'summary' => $figures->summary($user, $client->id), 'ageing' => $figures->ageing($user, $client->id),
            'invoices' => $invoices->take(self::MAX_ROWS)->map(fn ($r) => [
                'invoice' => $r['number'], 'date' => self::date($r['date']), 'due_date' => self::date($r['due_date']),
                'billed' => round($r['billed'], 2), 'outstanding' => round($r['outstanding'], 2), 'status' => $r['status'],
                'days_overdue' => $r['outstanding'] > 0 && $r['due_date'] && $r['due_date']->lt($today) ? (int) $r['due_date']->diffInDays($today) : 0,
            ])->all(),
            'payments' => $payments->take(self::MAX_ROWS)->map(fn ($p) => [
                'date' => self::date($p->payment_date), 'invoice' => $p->invoice?->invoice_uin, 'amount' => (float) $p->amount,
                'currency' => $p->currency, 'amount_inr' => (float) $p->amount_bcy, 'mode' => $p->payment_mode, 'reference' => $p->reference,
            ])->all(),
            'truncated' => $invoices->count() > self::MAX_ROWS || $payments->count() > self::MAX_ROWS,
        ];

        $file = $args['file'] ?? 'none';
        if (in_array($file, ['pdf', 'xlsx'], true)) {
            $report = app(GenerateReport::class)->handle($user, ['type' => 'payment-collection', 'format' => $file, 'client_code' => $client->client_code]);
            $out += ['export_id' => $report['export_id'], 'download_url' => $report['download_url'], 'link_expires' => $report['link_expires'], 'files' => $report['files']];
        }

        return $out;
    }
}
