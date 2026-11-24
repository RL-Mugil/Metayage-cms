<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Client;
use App\Support\PostalAddress;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Splits existing clients' one-line address into the IPO-form parts (house, street,
 * city, state, pincode). Only clients whose parts are all empty. --dry-run writes a review
 * CSV to private storage and prints a summary; --apply writes the parts (the old one-line
 * address is left as it was) with an audit entry per client.
 */
class SplitClientAddresses extends Command
{
    protected $signature = 'clients:split-addresses {--dry-run : Write the review CSV only} {--apply : Write the split parts}';

    protected $description = 'Split existing clients\' one-line address into house / street / city / state / pincode';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        if (! $apply && ! $this->option('dry-run')) {
            $this->error('Pass --dry-run (review) or --apply.');

            return self::INVALID;
        }

        $clients = Client::query()
            ->whereNotNull('address')->where('address', '!=', '')
            ->where(fn ($q) => $q->whereNull('address_house')->orWhere('address_house', ''))
            ->where(fn ($q) => $q->whereNull('address_street')->orWhere('address_street', ''))
            ->where(fn ($q) => $q->whereNull('city')->orWhere('city', ''))
            ->where(fn ($q) => $q->whereNull('pincode')->orWhere('pincode', ''))
            ->orderBy('id')->get();

        $rows = [];
        $counts = ['clean' => 0, 'partial' => 0];
        foreach ($clients as $client) {
            $split = PostalAddress::split($client->address, $client->state, $client->country ?: $client->nationality);
            $counts[$split['confidence']]++;
            $rows[] = [$client->client_code, $client->legal_name ?: $client->company_name, $client->address,
                $split['address_house'], $split['address_street'], $split['city'], $split['state'], $client->country ?: $client->nationality, $split['pincode'], $split['confidence']];

            if ($apply) {
                DB::transaction(function () use ($client, $split) {
                    $before = $client->only(['address_house', 'address_street', 'city', 'state', 'pincode']);
                    $client->forceFill([
                        'address_house' => $split['address_house'] ?: null,
                        'address_street' => $split['address_street'] ?: null,
                        'city' => $split['city'] ?: null,
                        'state' => $client->state ?: ($split['state'] ?: null),
                        'pincode' => $split['pincode'] ?: null,
                    ])->save();
                    AuditLog::create([
                        'user_id' => null, 'action' => 'client_address_split', 'subject_type' => 'Client', 'subject_id' => $client->id,
                        'metadata' => ['from_address' => $client->address, 'before' => $before, 'split' => $split],
                    ]);
                });
            }
        }

        $name = 'reports/client-address-split-'.now()->format('Ymd-His').'.csv';
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Client code', 'Name', 'Old address', 'House / building', 'Street / area', 'City', 'State', 'Country', 'Pincode', 'Confidence'], ',', '"', '');
        foreach ($rows as $r) {
            fputcsv($out, $r, ',', '"', '');
        }
        rewind($out);
        Storage::disk('local')->put($name, stream_get_contents($out));
        fclose($out);

        $this->info(($apply ? 'Split ' : 'Would split ').count($rows)." client address(es): {$counts['clean']} clean, {$counts['partial']} partial.");
        $this->line('Review CSV: '.Storage::disk('local')->path($name));

        return self::SUCCESS;
    }
}
