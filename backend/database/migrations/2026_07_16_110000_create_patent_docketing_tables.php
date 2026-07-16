<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Docketing core:
 *  - patent_applications — the legal anchor entity (one per application/patent,
 *    shared across all chained service matters). Holds legal status + statutory dates.
 *  - renewal_schedules — S.53/Rule 80 renewal fee rows (years 3–20) per application.
 *  - docket_events / docket_deadlines — event-driven statutory deadline engine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patent_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('application_number')->nullable()->index();
            $table->string('title')->nullable();
            $table->date('priority_date')->nullable();
            $table->date('filing_date')->nullable();
            $table->date('publication_date')->nullable();
            $table->date('rfe_filed_date')->nullable();
            $table->string('grant_number')->nullable();
            $table->date('grant_date')->nullable();
            // Legal status of the application at the IPO — distinct from any
            // matter's work status: Pending, Published, Under Examination,
            // Granted, Lapsed, Refused, Abandoned, Withdrawn
            $table->string('legal_status')->default('Pending')->index();
            $table->string('jurisdiction', 8)->default('IN');
            $table->timestamps();
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('patent_application_id')->nullable()
                ->constrained('patent_applications')->nullOnDelete();
        });

        Schema::create('renewal_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patent_application_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('renewal_year'); // 3..20
            $table->date('due_date');
            $table->string('status')->default('Unpaid'); // Unpaid | Paid | Waived | Lapsed
            $table->date('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['patent_application_id', 'renewal_year']);
        });

        Schema::create('docket_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('patent_application_id')->nullable()
                ->constrained('patent_applications')->nullOnDelete();
            $table->string('event_type');  // fer_received, hearing_held, granted, published, refused, renewal_missed, provisional_filed, pct_filed, rfe_filed
            $table->date('event_date');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('docket_deadlines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('docket_event_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('patent_application_id')->nullable()
                ->constrained('patent_applications')->nullOnDelete();
            $table->string('title');
            $table->string('legal_basis')->nullable(); // e.g. "Rule 24B(5)"
            $table->date('due_date')->index();
            $table->date('extended_due_date')->nullable(); // hard outer limit (e.g. +3mo Form 4)
            $table->string('status')->default('Open')->index(); // Open | Completed | Missed | Waived
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        $this->backfillApplications();
    }

    /**
     * One patent_application per elevation chain of Indian patent matters.
     * Chain root = walk parent_project_id upward. Legal dates aggregated
     * from the chain; legal_status derived from the latest matter.
     */
    private function backfillApplications(): void
    {
        $patentServices = [
            'PRV', 'CPT', 'CPD', 'CVP', 'CPE', 'PCT', 'NAP', 'NPE', 'NAF', 'NPA',
            'DVA', 'PAD', '9EP', '98A', '18F', '18A', 'FER', 'SER', 'TER',
            'HRG', 'GRT', 'RNF', 'OPP', 'PGO', '27F', 'ROA', 'ERH', '24F',
            'RPO', 'ABN', 'WDR',
        ];

        $projects = DB::table('projects')
            ->whereIn(DB::raw('UPPER(service_code)'), $patentServices)
            ->where(function ($q) {
                $q->whereNull('patent_office_code')->orWhere('patent_office_code', 'IN');
            })
            ->orderBy('id')
            ->get();

        $byId = $projects->keyBy('id');
        $rootOf = function ($p) use ($byId) {
            $seen = [];
            while ($p->parent_project_id && isset($byId[$p->parent_project_id]) && !isset($seen[$p->id])) {
                $seen[$p->id] = true;
                $p = $byId[$p->parent_project_id];
            }
            return $p->id;
        };

        $chains = [];
        foreach ($projects as $p) {
            $chains[$rootOf($p)][] = $p;
        }

        foreach ($chains as $members) {
            $collection = collect($members);
            $latest = $collection->sortByDesc('id')->first();

            $legalStatus = 'Pending';
            $statuses = $collection->pluck('status')->map(fn ($s) => (string) $s);
            $codes = $collection->pluck('service_code')->map(fn ($c) => strtoupper((string) $c));
            if ($statuses->contains('Granted') || ($codes->contains('GRT') && $statuses->contains('Completed'))) {
                $legalStatus = 'Granted';
            } elseif ($statuses->contains('Refused')) {
                $legalStatus = 'Refused';
            } elseif ($codes->contains('WDR')) {
                $legalStatus = 'Withdrawn';
            } elseif ($statuses->contains('Abandoned') || $codes->contains('ABN')) {
                $legalStatus = 'Abandoned';
            } elseif ($codes->intersect(['FER', 'SER', 'TER', 'HRG'])->isNotEmpty()) {
                $legalStatus = 'Under Examination';
            } elseif ($codes->intersect(['9EP', '98A', '18F', '18A'])->isNotEmpty()) {
                $legalStatus = 'Published';
            }

            $appId = DB::table('patent_applications')->insertGetId([
                'client_id'      => $latest->client_id,
                'title'          => $latest->invention_title ?: $latest->project_name,
                'priority_date'  => $collection->pluck('priority_date')->filter()->min(),
                'filing_date'    => $collection->pluck('filing_date')->filter()->min(),
                'grant_date'     => $statuses->contains('Granted')
                    ? $collection->firstWhere('status', 'Granted')?->updated_at
                    : null,
                'legal_status'   => $legalStatus,
                'jurisdiction'   => 'IN',
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);

            DB::table('projects')
                ->whereIn('id', $collection->pluck('id'))
                ->update(['patent_application_id' => $appId]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('docket_deadlines');
        Schema::dropIfExists('docket_events');
        Schema::dropIfExists('renewal_schedules');
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('patent_application_id');
        });
        Schema::dropIfExists('patent_applications');
    }
};
