<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Project;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Renames a service code or country/patent-office code and cascades that rename into every
 * *live* piece of data that keys off it, so e.g. renaming "REN" to "RNF" doesn't leave the
 * dropdown, project docket numbers, and workflow config pointing at three different names for
 * the same thing (which is exactly the drift this feature exists to fix — see
 * RenewalActionController's REN/RNF dual-lookup and the alias migration it works around).
 *
 * Deliberately NOT touched by the cascade (documented inline at each call site too):
 *  - patent_invoices_in — historical financial record, some rows already synced to Zoho Books.
 *  - project_elevations, projects.original_docket — historical snapshot of what happened at
 *    elevation time, same semantics as an audit log entry.
 *  - project_tracker (TrackerRow), feedback_requests — legacy/cosmetic snapshots, already not
 *    guaranteed in sync with `projects`.
 *  - audit_logs.metadata, ip_notifications.meta — immutable historical JSON.
 *  - config/project_import_codes.php — independent fallback catalog, unrelated to the live
 *    dropdown_service_codes/dropdown_country_codes settings this feature edits.
 *
 * V1 only supports same-length renames (the docket number is a fixed-width string) and never
 * merges onto an already-existing distinct code.
 */
class CodeRenameService
{
    private const TYPE_COLUMN = [
        'service' => 'service_code',
        'country' => 'patent_office_code',
    ];

    private const DROPDOWN_KEY = [
        'service' => 'dropdown_service_codes',
        'country' => 'dropdown_country_codes',
    ];

    /** Business-rule validation (shape/presence is validated by the controller's FormRequest-style rules). */
    public function assertRenameIsValid(string $type, string $old, string $new): void
    {
        $old = strtoupper(trim($old));
        $new = strtoupper(trim($new));

        if ($old === '' || $new === '') {
            throw ValidationException::withMessages(['new_code' => 'Both the current and new code are required.']);
        }
        if ($old === $new) {
            throw ValidationException::withMessages(['new_code' => 'The new code must be different from the current code.']);
        }
        if (strlen($old) !== strlen($new)) {
            throw ValidationException::withMessages([
                'new_code' => "The new code must be the same length as the current code (" . strlen($old) . ' characters) — renaming to a different length isn\'t supported yet.',
            ]);
        }
        if (! preg_match('/^[A-Z0-9]+$/', $new)) {
            throw ValidationException::withMessages(['new_code' => 'Codes may only contain letters and numbers.']);
        }

        $codes = array_map(fn ($i) => strtoupper($i['code']), $this->dropdownItems($type));
        if (! in_array($old, $codes, true)) {
            throw ValidationException::withMessages(['old_code' => "'{$old}' was not found in the current code list."]);
        }
        if (in_array($new, $codes, true)) {
            throw ValidationException::withMessages([
                'new_code' => "'{$new}' already exists as a separate code — use Merge instead (the controller detects this and offers it before this method is ever reached for that case).",
            ]);
        }
    }

    /**
     * Validation for a merge (fold $old into an already-existing $new), the mirror image of
     * assertRenameIsValid(): here $new must already be a registered, distinct code rather than
     * a fresh one.
     */
    public function assertMergeIsValid(string $type, string $old, string $new): void
    {
        $old = strtoupper(trim($old));
        $new = strtoupper(trim($new));

        if ($old === '' || $new === '') {
            throw ValidationException::withMessages(['new_code' => 'Both the code being retired and the target code are required.']);
        }
        if ($old === $new) {
            throw ValidationException::withMessages(['new_code' => 'The target code must be different from the code being retired.']);
        }
        if (strlen($old) !== strlen($new)) {
            throw ValidationException::withMessages([
                'new_code' => "The target code must be the same length as the code being retired (" . strlen($old) . ' characters) — merging codes of different lengths isn\'t supported yet.',
            ]);
        }

        $codes = array_map(fn ($i) => strtoupper($i['code']), $this->dropdownItems($type));
        if (! in_array($old, $codes, true)) {
            throw ValidationException::withMessages(['old_code' => "'{$old}' was not found in the current code list."]);
        }
        if (! in_array($new, $codes, true)) {
            throw ValidationException::withMessages(['new_code' => "'{$new}' was not found in the current code list — merge only combines two already-registered codes."]);
        }
    }

    /** Number of projects (any status, including soft-deleted) currently carrying $code. */
    public function usageCount(string $type, string $code): int
    {
        $column = self::TYPE_COLUMN[$type];
        return Project::withTrashed()->where($column, strtoupper(trim($code)))->count();
    }

    /**
     * Case-insensitive catalog lookup — returns the matching dropdown entry (with live usage
     * count) or null if $code isn't a currently-registered code. Used by the controller to
     * detect the "rename target already exists" condition and offer a merge instead.
     */
    public function findExistingCode(string $type, string $code): ?array
    {
        $code = strtoupper(trim($code));
        foreach ($this->dropdownWithCounts($type) as $item) {
            if (strtoupper($item['code']) === $code) {
                return $item;
            }
        }
        return null;
    }

    /** The dropdown_service_codes/dropdown_country_codes list, each item annotated with live usage count. */
    public function dropdownWithCounts(string $type): array
    {
        $column = self::TYPE_COLUMN[$type];
        $counts = Project::withTrashed()
            ->whereNotNull($column)
            ->select($column, DB::raw('count(*) as cnt'))
            ->groupBy($column)
            ->get()
            ->reduce(function ($carry, $row) use ($column) {
                $carry[strtoupper((string) $row->$column)] = (int) $row->cnt;
                return $carry;
            }, []);

        return array_map(function ($item) use ($counts) {
            return [
                'code' => $item['code'],
                'label' => $item['label'],
                'projects_count' => $counts[strtoupper($item['code'])] ?? 0,
            ];
        }, $this->dropdownItems($type));
    }

    /**
     * Read-only: runs the exact same cascade as execute() inside a transaction it then rolls
     * back, so the impact counts (including per-table collision-skip counts) are guaranteed
     * accurate without persisting anything. No cache/audit side effects.
     */
    public function previewImpact(string $type, string $old, string $new): array
    {
        $this->assertRenameIsValid($type, $old, $new);

        DB::beginTransaction();
        try {
            return $this->runCascade($type, strtoupper(trim($old)), strtoupper(trim($new)));
        } finally {
            DB::rollBack();
        }
    }

    /** Executes the cascade for real, then invalidates caches and writes an audit trail. */
    public function execute(string $type, string $old, string $new, int $actorUserId, ?string $ip, ?string $userAgent): array
    {
        $this->assertRenameIsValid($type, $old, $new);
        $old = strtoupper(trim($old));
        $new = strtoupper(trim($new));

        return DB::transaction(function () use ($type, $old, $new, $actorUserId, $ip, $userAgent) {
            // Re-validate under the transaction to close the window against a concurrent rename.
            $this->assertRenameIsValid($type, $old, $new);

            $result = $this->runCascade($type, $old, $new);

            Cache::forget('system_settings_shared');
            Cache::increment('dashboard_v'); // busts project_stats_*/dashboard_metrics_*/etc.

            AuditLog::create([
                'user_id' => $actorUserId,
                'action' => $type === 'service' ? 'rename_service_code' : 'rename_country_code',
                'metadata' => $result,
                'ip_address' => $ip,
                'user_agent' => $userAgent,
            ]);

            return $result;
        });
    }

    /**
     * Read-only preview of what merging $old into $new would touch — same rollback-transaction
     * trick as previewImpact(), including accurate per-table discard counts.
     */
    public function previewMergeImpact(string $type, string $old, string $new): array
    {
        $this->assertMergeIsValid($type, $old, $new);

        DB::beginTransaction();
        try {
            return $this->runMergeCascade($type, strtoupper(trim($old)), strtoupper(trim($new)));
        } finally {
            DB::rollBack();
        }
    }

    /** Executes the merge for real, then invalidates caches and writes an audit trail. */
    public function executeMerge(string $type, string $old, string $new, int $actorUserId, ?string $ip, ?string $userAgent): array
    {
        $this->assertMergeIsValid($type, $old, $new);
        $old = strtoupper(trim($old));
        $new = strtoupper(trim($new));

        return DB::transaction(function () use ($type, $old, $new, $actorUserId, $ip, $userAgent) {
            // Re-validate under the transaction to close the window against a concurrent change.
            $this->assertMergeIsValid($type, $old, $new);

            $result = $this->runMergeCascade($type, $old, $new);

            Cache::forget('system_settings_shared');
            Cache::increment('dashboard_v');

            AuditLog::create([
                'user_id' => $actorUserId,
                'action' => $type === 'service' ? 'merge_service_code' : 'merge_country_code',
                'metadata' => $result,
                'ip_address' => $ip,
                'user_agent' => $userAgent,
            ]);

            return $result;
        });
    }

    // ── The rename cascade ──────────────────────────────────────────────────────────────

    private function runCascade(string $type, string $old, string $new): array
    {
        $column = self::TYPE_COLUMN[$type];

        $projects = $this->applyProjectsCascade($column, $type, $old, $new);

        // project_elevations (from/to_service_code, from/to_docket) and projects.original_docket
        // are intentionally left untouched — historical record of what happened at the time,
        // not live data. patent_invoices_in is likewise intentionally left untouched (historical
        // financial record, some rows already synced to Zoho Books).

        $feeField = $type === 'service' ? 'service_code' : 'jurisdiction';
        $feeRateCards = $this->applyTableCascade(
            'fee_rate_cards',
            $feeField,
            $old,
            $new,
            ['jurisdiction', 'service_code', 'entity_tier', 'year_from', 'year_to', 'validation_country']
        );

        $serviceTransitionRules = $this->applyServiceTransitionCascade($type, $old, $new);

        $lifecycleField = $type === 'service' ? 'service_code' : 'jurisdiction';
        $jurisdictionLifecycleTemplates = $this->applyTableCascade(
            'jurisdiction_lifecycle_templates',
            $lifecycleField,
            $old,
            $new,
            ['jurisdiction', 'service_code', 'version']
        );

        $jurisdictionTables = [];
        if ($type === 'country') {
            foreach (['patent_applications', 'deadline_rule_definitions', 'ip_records'] as $table) {
                $count = DB::table($table)->where('jurisdiction', $old)->count();
                if ($count > 0) {
                    DB::table($table)->where('jurisdiction', $old)->update(['jurisdiction' => $new, 'updated_at' => now()]);
                }
                $jurisdictionTables[$table] = $count;
            }
        }

        // project_tabs only maps service codes (see SettingsController::updateProjectTabs).
        $projectTabsHits = $type === 'service' ? $this->renameInProjectTabs($old, $new) : 0;

        $this->renameDropdownEntry($type, $old, $new);

        return [
            'type' => $type,
            'old_code' => $old,
            'new_code' => $new,
            'projects' => $projects,
            'fee_rate_cards' => $feeRateCards,
            'service_transition_rules' => $serviceTransitionRules,
            'jurisdiction_lifecycle_templates' => $jurisdictionLifecycleTemplates,
            'jurisdiction_tables' => $jurisdictionTables,
            'project_tabs_hits' => $projectTabsHits,
        ];
    }

    // ── The merge cascade — folds $old into an already-existing $new ────────────────────
    //
    // Confirmed with the user: on a config collision (both codes already have a row for the
    // exact same tuple, e.g. the same fee_rate_cards jurisdiction+entity_tier+year-range), the
    // TARGET ($new, the survivor) wins — its row is kept, $old's colliding row is permanently
    // deleted (not left dangling on a code that's being retired). Reuses every piece of the
    // rename cascade that has no collision risk (projects, project_tabs) unchanged.

    private function runMergeCascade(string $type, string $old, string $new): array
    {
        $column = self::TYPE_COLUMN[$type];

        // No collision risk here — many projects legitimately share one service/country code.
        $projects = $this->applyProjectsCascade($column, $type, $old, $new);

        $feeField = $type === 'service' ? 'service_code' : 'jurisdiction';
        $feeRateCards = $this->applyTableCascadeForMerge(
            'fee_rate_cards',
            $feeField,
            $old,
            $new,
            ['jurisdiction', 'service_code', 'entity_tier', 'year_from', 'year_to', 'validation_country']
        );

        $serviceTransitionRules = $this->applyServiceTransitionMergeCascade($type, $old, $new);

        $lifecycleField = $type === 'service' ? 'service_code' : 'jurisdiction';
        $jurisdictionLifecycleTemplates = $this->applyTableCascadeForMerge(
            'jurisdiction_lifecycle_templates',
            $lifecycleField,
            $old,
            $new,
            ['jurisdiction', 'service_code', 'version']
        );

        // No per-row uniqueness constraint on these — many rows already share one jurisdiction,
        // so a blind update (same as the rename cascade) is correct here too, no collision logic.
        $jurisdictionTables = [];
        if ($type === 'country') {
            foreach (['patent_applications', 'deadline_rule_definitions', 'ip_records'] as $table) {
                $count = DB::table($table)->where('jurisdiction', $old)->count();
                if ($count > 0) {
                    DB::table($table)->where('jurisdiction', $old)->update(['jurisdiction' => $new, 'updated_at' => now()]);
                }
                $jurisdictionTables[$table] = $count;
            }
        }

        // renameInProjectTabs() already array_unique()s after substitution, so if a tab has
        // both $old and $new it collapses to one entry with zero changes needed for merge.
        $projectTabsHits = $type === 'service' ? $this->renameInProjectTabs($old, $new) : 0;

        // Unlike rename (which relabels the old entry in place), $new already has its own
        // dropdown entry/label — $old's entry is retired outright.
        $this->removeDropdownEntry($type, $old);

        return [
            'type' => $type,
            'old_code' => $old,
            'new_code' => $new,
            'projects' => $projects,
            'fee_rate_cards' => $feeRateCards,
            'service_transition_rules' => $serviceTransitionRules,
            'jurisdiction_lifecycle_templates' => $jurisdictionLifecycleTemplates,
            'jurisdiction_tables' => $jurisdictionTables,
            'project_tabs_hits' => $projectTabsHits,
        ];
    }

    /**
     * Rewrites projects.service_code/patent_office_code plus, where safe, the embedded code in
     * docket_number/project_code — reusing DocketNumberService::recanonicalize() (which already
     * knows how to recompose the canonical string using THIS project's own client_code length
     * and duplicate-checks the result) rather than doing fixed-offset string surgery. A project
     * whose docket doesn't match the canonical pattern (or would collide with another project's
     * docket) gets only its dedicated column updated — its docket text is left as-is and it's
     * reported as needing manual review, rather than risking corrupting an irregular/legacy value.
     */
    private function applyProjectsCascade(string $column, string $type, string $old, string $new): array
    {
        $updated = 0;
        $irregular = 0;
        $irregularIds = [];
        $docketService = app(DocketNumberService::class);

        Project::withTrashed()->where($column, $old)->orderBy('id')
            ->chunkById(200, function ($projects) use (&$updated, &$irregular, &$irregularIds, $type, $new, $column, $docketService) {
                foreach ($projects as $project) {
                    $office = $type === 'country' ? $new : null;
                    $service = $type === 'service' ? $new : null;
                    try {
                        $parts = $docketService->recanonicalize($project, $office, $service);
                        $project->forceFill($parts)->save();
                        $updated++;
                    } catch (\Throwable $e) {
                        $project->forceFill([$column => $new])->save();
                        $irregular++;
                        $irregularIds[] = $project->id;
                    }
                }
            });

        return ['updated' => $updated, 'irregular' => $irregular, 'irregular_ids' => $irregularIds];
    }

    /**
     * Generic rename-one-field-with-collision-skip for a table identified by a full tuple of
     * columns (which includes $field itself). A row is skipped — left on the old code, reported,
     * never overwritten/merged — if another row already occupies the resulting tuple.
     */
    private function applyTableCascade(string $table, string $field, string $old, string $new, array $tupleColumns): array
    {
        $rows = DB::table($table)->where($field, $old)->get();
        if ($rows->isEmpty()) {
            return ['updated' => 0, 'skipped' => 0, 'skipped_ids' => []];
        }

        $allRows = DB::table($table)->get();
        $updated = 0;
        $skippedIds = [];

        foreach ($rows as $row) {
            $collides = $allRows->contains(function ($other) use ($row, $field, $new, $tupleColumns) {
                if ($other->id === $row->id) {
                    return false;
                }
                foreach ($tupleColumns as $col) {
                    $expected = $col === $field ? $new : ($row->$col ?? null);
                    if ((string) ($other->$col ?? '') !== (string) ($expected ?? '')) {
                        return false;
                    }
                }
                return true;
            });

            if ($collides) {
                $skippedIds[] = $row->id;
                continue;
            }

            DB::table($table)->where('id', $row->id)->update([$field => $new, 'updated_at' => now()]);
            $updated++;
        }

        return ['updated' => $updated, 'skipped' => count($skippedIds), 'skipped_ids' => $skippedIds];
    }

    /**
     * Merge counterpart to applyTableCascade(): same tuple-collision detection, but a colliding
     * row is DELETED (the target/$new's existing row wins) instead of left on the old code —
     * $old is being retired entirely, so skipping would leave it orphaned on a dead code.
     */
    private function applyTableCascadeForMerge(string $table, string $field, string $old, string $new, array $tupleColumns): array
    {
        $rows = DB::table($table)->where($field, $old)->get();
        if ($rows->isEmpty()) {
            return ['updated' => 0, 'discarded' => 0, 'discarded_ids' => []];
        }

        $allRows = DB::table($table)->get();
        $updated = 0;
        $discardedIds = [];

        foreach ($rows as $row) {
            $collides = $allRows->contains(function ($other) use ($row, $field, $new, $tupleColumns) {
                if ($other->id === $row->id) {
                    return false;
                }
                foreach ($tupleColumns as $col) {
                    $expected = $col === $field ? $new : ($row->$col ?? null);
                    if ((string) ($other->$col ?? '') !== (string) ($expected ?? '')) {
                        return false;
                    }
                }
                return true;
            });

            if ($collides) {
                DB::table($table)->where('id', $row->id)->delete();
                $discardedIds[] = $row->id;
                continue;
            }

            DB::table($table)->where('id', $row->id)->update([$field => $new, 'updated_at' => now()]);
            $updated++;
        }

        return ['updated' => $updated, 'discarded' => count($discardedIds), 'discarded_ids' => $discardedIds];
    }

    /**
     * service_transition_rules is special-cased because BOTH from_service_code and
     * to_service_code can independently carry the old code within the same row (e.g. a
     * self-transition like RNF -> RNF), and its unique key covers both columns together.
     */
    private function applyServiceTransitionCascade(string $type, string $old, string $new): array
    {
        if ($type === 'country') {
            return $this->applyTableCascade(
                'service_transition_rules',
                'jurisdiction',
                $old,
                $new,
                ['jurisdiction', 'from_service_code', 'to_service_code']
            );
        }

        $rows = DB::table('service_transition_rules')
            ->where('from_service_code', $old)->orWhere('to_service_code', $old)
            ->get();
        if ($rows->isEmpty()) {
            return ['updated' => 0, 'skipped' => 0, 'skipped_ids' => []];
        }

        $allRows = DB::table('service_transition_rules')->get();
        $updated = 0;
        $skippedIds = [];

        foreach ($rows as $row) {
            $newFrom = $row->from_service_code === $old ? $new : $row->from_service_code;
            $newTo = $row->to_service_code === $old ? $new : $row->to_service_code;

            $collides = $allRows->contains(fn ($other) => $other->id !== $row->id
                && $other->jurisdiction === $row->jurisdiction
                && $other->from_service_code === $newFrom
                && $other->to_service_code === $newTo);

            if ($collides) {
                $skippedIds[] = $row->id;
                continue;
            }

            DB::table('service_transition_rules')->where('id', $row->id)->update([
                'from_service_code' => $newFrom,
                'to_service_code' => $newTo,
                'updated_at' => now(),
            ]);
            $updated++;
        }

        return ['updated' => $updated, 'skipped' => count($skippedIds), 'skipped_ids' => $skippedIds];
    }

    /** Merge counterpart to applyServiceTransitionCascade(): delete-on-collision instead of skip. */
    private function applyServiceTransitionMergeCascade(string $type, string $old, string $new): array
    {
        if ($type === 'country') {
            return $this->applyTableCascadeForMerge(
                'service_transition_rules',
                'jurisdiction',
                $old,
                $new,
                ['jurisdiction', 'from_service_code', 'to_service_code']
            );
        }

        $rows = DB::table('service_transition_rules')
            ->where('from_service_code', $old)->orWhere('to_service_code', $old)
            ->get();
        if ($rows->isEmpty()) {
            return ['updated' => 0, 'discarded' => 0, 'discarded_ids' => []];
        }

        $allRows = DB::table('service_transition_rules')->get();
        $updated = 0;
        $discardedIds = [];

        foreach ($rows as $row) {
            $newFrom = $row->from_service_code === $old ? $new : $row->from_service_code;
            $newTo = $row->to_service_code === $old ? $new : $row->to_service_code;

            $collides = $allRows->contains(fn ($other) => $other->id !== $row->id
                && $other->jurisdiction === $row->jurisdiction
                && $other->from_service_code === $newFrom
                && $other->to_service_code === $newTo);

            if ($collides) {
                DB::table('service_transition_rules')->where('id', $row->id)->delete();
                $discardedIds[] = $row->id;
                continue;
            }

            DB::table('service_transition_rules')->where('id', $row->id)->update([
                'from_service_code' => $newFrom,
                'to_service_code' => $newTo,
                'updated_at' => now(),
            ]);
            $updated++;
        }

        return ['updated' => $updated, 'discarded' => count($discardedIds), 'discarded_ids' => $discardedIds];
    }

    /** Replaces $old with $new inside every project_tabs entry's codes[] array, in place. */
    private function renameInProjectTabs(string $old, string $new): int
    {
        $raw = DB::table('system_settings')->where('key', 'project_tabs')->value('value');
        if (! $raw) {
            return 0;
        }

        $tabs = json_decode($raw, true) ?: [];
        $hits = 0;
        foreach ($tabs as &$tab) {
            $codes = $tab['codes'] ?? [];
            $idx = array_search($old, $codes, true);
            if ($idx !== false) {
                $codes[$idx] = $new;
                $tab['codes'] = array_values(array_unique($codes));
                $hits++;
            }
        }
        unset($tab);

        if ($hits > 0) {
            DB::table('system_settings')->where('key', 'project_tabs')
                ->update(['value' => json_encode($tabs), 'updated_at' => now()]);
        }

        return $hits;
    }

    /** Renames the matching entry's `code` inside dropdown_service_codes/dropdown_country_codes (label untouched). */
    private function renameDropdownEntry(string $type, string $old, string $new): void
    {
        $key = self::DROPDOWN_KEY[$type];
        $items = $this->dropdownItems($type);
        foreach ($items as &$item) {
            if (strtoupper($item['code']) === $old) {
                $item['code'] = $new;
            }
        }
        unset($item);

        DB::table('system_settings')->where('key', $key)
            ->update(['value' => json_encode($items), 'updated_at' => now()]);
    }

    /**
     * Merge counterpart to renameDropdownEntry(): $old's catalog entry is retired outright
     * rather than relabeled, since $new already has its own entry/label.
     */
    private function removeDropdownEntry(string $type, string $old): void
    {
        $key = self::DROPDOWN_KEY[$type];
        $items = array_values(array_filter(
            $this->dropdownItems($type),
            fn ($item) => strtoupper($item['code']) !== $old
        ));

        DB::table('system_settings')->where('key', $key)
            ->update(['value' => json_encode($items), 'updated_at' => now()]);
    }

    private function dropdownItems(string $type): array
    {
        $key = self::DROPDOWN_KEY[$type];
        $raw = DB::table('system_settings')->where('key', $key)->value('value');
        return $raw ? (json_decode($raw, true) ?: []) : [];
    }
}
