<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Real rates from the firm's IN/US/EP/PCT fee proposal, so Settings > Finance
// ships with actual data instead of an empty screen. See the fee_rate_cards
// migration for the schema this fills, and the plan doc for how each PDF row
// maps onto it (EP dual-currency, ranges, PCT's mixed-currency breakdown, etc).
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $rows = [];

        $add = function (array $row) use (&$rows, $now) {
            $rows[] = array_merge([
                'entity_tier' => null,
                'year_from' => null, 'year_to' => null,
                'validation_country' => null,
                'govt_fee_amount' => null, 'govt_fee_currency' => null,
                'professional_fee_amount' => null, 'professional_fee_currency' => null,
                'professional_fee_max_amount' => null,
                'professional_fee_charge_basis' => 'per_unit',
                'fee_breakdown' => null, 'notes' => null, 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ], $row);
        };

        // ── India (govt fee currency INR, professional fee currency INR) ──
        $inTier = function (string $service, ?float $govtDiscounted, ?float $govtStandard, ?float $prof, ?string $notes = null) use ($add) {
            $add(['jurisdiction' => 'IN', 'service_code' => $service, 'entity_tier' => 'discounted',
                'govt_fee_amount' => $govtDiscounted, 'govt_fee_currency' => $govtDiscounted !== null ? 'INR' : null,
                'professional_fee_amount' => $prof, 'professional_fee_currency' => $prof !== null ? 'INR' : null, 'notes' => $notes]);
            $add(['jurisdiction' => 'IN', 'service_code' => $service, 'entity_tier' => 'standard',
                'govt_fee_amount' => $govtStandard, 'govt_fee_currency' => $govtStandard !== null ? 'INR' : null,
                'professional_fee_amount' => $prof, 'professional_fee_currency' => $prof !== null ? 'INR' : null, 'notes' => $notes]);
        };

        $inTier('SRC', null, null, 12500, 'Patentability search & reporting — first level');
        $inTier('2SR', null, null, 7500, 'Patentability search & reporting — second level (optional)');
        $inTier('PRV', 1600, 8000, 35000);
        $inTier('CPT', null, null, 35000);
        $inTier('CPD', 1600, 8000, 70000);
        $inTier('9EP', 2500, 12500, 4000);
        $inTier('18F', 4000, 20000, 4000);
        $inTier('18A', 8000, 60000, 4000, 'Fastrack — discounted rate only if applicant is Startup/MSME registered');
        $inTier('ASN', null, null, 5000);
        $inTier('FER', null, null, 25000);
        $inTier('HRG', null, null, 30000);
        $inTier('FFP', 1600, 8000, 5000);
        $inTier('CER', 1000, 5000, 3000);

        // India renewal fee — banded by year, professional fee is flat per renewal
        // transaction (not multiplied per year), unlike EP's per-year accrual below.
        $inRenewalBand = function (float $from, float $to, float $govtDiscounted, float $govtStandard) use ($add) {
            $add(['jurisdiction' => 'IN', 'service_code' => 'RNF', 'entity_tier' => 'discounted',
                'year_from' => $from, 'year_to' => $to, 'govt_fee_amount' => $govtDiscounted, 'govt_fee_currency' => 'INR',
                'professional_fee_amount' => 5000, 'professional_fee_currency' => 'INR', 'professional_fee_charge_basis' => 'flat_per_transaction']);
            $add(['jurisdiction' => 'IN', 'service_code' => 'RNF', 'entity_tier' => 'standard',
                'year_from' => $from, 'year_to' => $to, 'govt_fee_amount' => $govtStandard, 'govt_fee_currency' => 'INR',
                'professional_fee_amount' => 5000, 'professional_fee_currency' => 'INR', 'professional_fee_charge_basis' => 'flat_per_transaction']);
        };
        $inRenewalBand(2, 6, 800, 4000);
        $inRenewalBand(7, 10, 2400, 12000);
        // Source proposal literally says "10th-15th yr" overlapping the prior band's
        // 10th year — treated as 11th-15th here to keep bands non-overlapping.
        $inRenewalBand(11, 15, 4800, 24000);
        $inRenewalBand(16, 20, 8000, 40000);

        // ── US (USPTO) — only Micro Entity numbers given; Small Entity numbers
        // are preserved in notes but not auto-populated (no Large/standard number
        // was given at all — that row is left blank for staff to fill in). ──
        $usTier = function (string $service, ?float $govtMicro, ?float $prof, ?string $smallEntityNote = null) use ($add) {
            $add(['jurisdiction' => 'US', 'service_code' => $service, 'entity_tier' => 'discounted',
                'govt_fee_amount' => $govtMicro, 'govt_fee_currency' => $govtMicro !== null ? 'USD' : null,
                'professional_fee_amount' => $prof, 'professional_fee_currency' => $prof !== null ? 'USD' : null,
                'notes' => $smallEntityNote]);
            $add(['jurisdiction' => 'US', 'service_code' => $service, 'entity_tier' => 'standard',
                'govt_fee_amount' => null, 'govt_fee_currency' => null,
                'professional_fee_amount' => $prof, 'professional_fee_currency' => $prof !== null ? 'USD' : null,
                'notes' => 'Large/standard USPTO fee not given in source proposal — confirm actual fee. ' . ($smallEntityNote ?? '')]);
        };
        $usTier('NPV', 300, 750, 'Small Entity govt fee (not used by default): USD 664');
        $usTier('CCP', null, 125);
        $usTier('ASN', null, 125);
        $usTier('TRC', 840, 300, 'Small Entity govt fee (not used by default): USD 1,680');
        $usTier('ROA', null, 150);
        $usTier('NFO', null, 1250, 'Covers response to each office action, final or non-final');
        $usTier('IDS', null, 125);
        $usTier('RCE', 272, 350, 'Small Entity govt fee (not used by default): USD 544');
        $usTier('NOA', 240, 500, 'Small Entity govt fee (not used by default): USD 480');

        // US maintenance fee milestones (3.5 / 7.5 / 11.5 yrs) — reference data,
        // not wired into the renewal-approval flow (that's IN/EP RNF only).
        $usMaintenance = function (float $milestone, float $govtMicro, string $smallNote) use ($add) {
            $add(['jurisdiction' => 'US', 'service_code' => 'MFE', 'entity_tier' => 'discounted',
                'year_from' => $milestone, 'year_to' => $milestone, 'govt_fee_amount' => $govtMicro, 'govt_fee_currency' => 'USD',
                'professional_fee_amount' => 350, 'professional_fee_currency' => 'USD', 'notes' => $smallNote]);
            $add(['jurisdiction' => 'US', 'service_code' => 'MFE', 'entity_tier' => 'standard',
                'year_from' => $milestone, 'year_to' => $milestone, 'govt_fee_amount' => null, 'govt_fee_currency' => null,
                'professional_fee_amount' => 350, 'professional_fee_currency' => 'USD',
                'notes' => 'Large/standard USPTO fee not given in source proposal. ' . $smallNote]);
        };
        $usMaintenance(3.5, 500, 'Small Entity govt fee (not used by default): USD 1,000');
        $usMaintenance(7.5, 940, 'Small Entity govt fee (not used by default): USD 1,880');
        $usMaintenance(11.5, 1925, 'Small Entity govt fee (not used by default): USD 3,850');

        // ── EP (European Patent Office) — no entity tiering; note the dual
        // currency: government fee in EUR, professional/attorney fee in GBP. ──
        $add(['jurisdiction' => 'EP', 'service_code' => 'CVP', 'govt_fee_amount' => 4145, 'govt_fee_currency' => 'EUR',
            'professional_fee_amount' => 1000, 'professional_fee_currency' => 'GBP', 'notes' => 'Filing a patent application']);
        $add(['jurisdiction' => 'EP', 'service_code' => 'CCP', 'professional_fee_amount' => 250, 'professional_fee_currency' => 'GBP']);
        $add(['jurisdiction' => 'EP', 'service_code' => 'ASN', 'professional_fee_amount' => 250, 'professional_fee_currency' => 'GBP']);
        $add(['jurisdiction' => 'EP', 'service_code' => 'ROA', 'professional_fee_amount' => 200, 'professional_fee_currency' => 'GBP']);
        $add(['jurisdiction' => 'EP', 'service_code' => '94E', 'professional_fee_amount' => 1350, 'professional_fee_currency' => 'GBP',
            'professional_fee_max_amount' => 2500, 'notes' => 'Response to each office action/communication — range GBP 1,350-2,500, low end pre-filled']);
        $add(['jurisdiction' => 'EP', 'service_code' => 'GRT', 'govt_fee_amount' => 950, 'govt_fee_currency' => 'EUR',
            'professional_fee_amount' => 1000, 'professional_fee_currency' => 'GBP', 'professional_fee_max_amount' => 1200,
            'notes' => 'Grant fees & related formalities, including fee to translate to DE and FR — range GBP 1,000-1,200, low end pre-filled']);

        // EP validation fee per country — govt + professional fee both given in GBP in the source proposal.
        $epValidation = function (string $country, ?float $govt, float $prof) use ($add) {
            $add(['jurisdiction' => 'EP', 'service_code' => 'EPV', 'validation_country' => $country,
                'govt_fee_amount' => $govt, 'govt_fee_currency' => $govt !== null ? 'GBP' : null,
                'professional_fee_amount' => $prof, 'professional_fee_currency' => 'GBP']);
        };
        $epValidation('United Kingdom', null, 325);
        $epValidation('France', null, 325);
        $epValidation('Germany', null, 325);
        $epValidation('Netherlands', 23, 705);
        $epValidation('Spain', 238, 1405);

        // EP renewal/maintenance fee — per-year accrual (unlike IN's flat-per-transaction
        // professional fee above), no entity tiering.
        $epRenewalYear = function (float $year, float $govt) use ($add) {
            $add(['jurisdiction' => 'EP', 'service_code' => 'RNF', 'year_from' => $year, 'year_to' => $year,
                'govt_fee_amount' => $govt, 'govt_fee_currency' => 'EUR',
                'professional_fee_amount' => 200, 'professional_fee_currency' => 'GBP', 'professional_fee_charge_basis' => 'per_unit']);
        };
        $epRenewalYear(3, 505);
        $epRenewalYear(4, 630);
        $epRenewalYear(5, 880);
        $epRenewalYear(6, 1125);
        $epRenewalYear(7, 1245);
        $epRenewalYear(8, 1370);
        $epRenewalYear(9, 1495);
        $add(['jurisdiction' => 'EP', 'service_code' => 'RNF', 'year_from' => 10, 'year_to' => 20,
            'govt_fee_amount' => 1690, 'govt_fee_currency' => 'EUR',
            'professional_fee_amount' => 200, 'professional_fee_currency' => 'GBP', 'professional_fee_charge_basis' => 'per_unit',
            'notes' => 'Flat EUR 1,690/yr for years 10-20']);

        // ── PCT (WIPO) — genuinely multi-currency, page-count-dependent government
        // fee. Deliberately left unpopulated (govt_fee_amount = NULL) rather than
        // guessed — staff read the breakdown and total manually. ──
        $add([
            'jurisdiction' => 'WO', 'service_code' => 'PCT',
            'govt_fee_amount' => null, 'govt_fee_currency' => 'MIXED',
            'professional_fee_amount' => 15000, 'professional_fee_currency' => 'INR',
            'fee_breakdown' => json_encode([
                ['label' => 'WIPO International Fee (up to 30pp; +USD 15/extra page)', 'amount' => 1453, 'currency' => 'USD'],
                ['label' => 'Priority fee (up to 30pp; +INR 150/extra page)', 'amount' => 5000, 'currency' => 'INR'],
                ['label' => 'International Search Fee (Indian Patent Office as ISA)', 'amount' => 10000, 'currency' => 'INR'],
            ]),
            'notes' => 'Government fee spans USD + INR and depends on page count — total manually before invoicing.',
        ]);

        DB::table('fee_rate_cards')->insert($rows);
    }

    public function down(): void
    {
        DB::table('fee_rate_cards')->truncate();
    }
};
