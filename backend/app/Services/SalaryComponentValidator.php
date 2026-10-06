<?php

namespace App\Services;

use App\Models\SalaryComponent;
use Illuminate\Validation\ValidationException;

/**
 * Cross-row invariants for the salary component template that a plain
 * `$request->validate()` rule set can't express — field-shape validation
 * (calc_type is one of flat/percentage/remainder, etc.) stays in
 * SalaryComponentController's own request validation.
 */
class SalaryComponentValidator
{
    /**
     * @param  SalaryComponent|null  $existing  null for a create
     * @param  array<string,mixed>  $data  the incoming (already field-validated) payload
     */
    public function assertValid(?SalaryComponent $existing, array $data): void
    {
        $code = $data['code'] ?? $existing?->code;
        $calcType = $data['calc_type'] ?? $existing?->calc_type;
        $category = $data['category'] ?? $existing?->category ?? SalaryComponent::CATEGORY_EARNING;
        $percentageOf = array_key_exists('percentage_of', $data) ? $data['percentage_of'] : $existing?->percentage_of;
        $sequence = (int) ($data['sequence'] ?? $existing?->sequence ?? 0);
        $active = array_key_exists('active', $data) ? (bool) $data['active'] : ($existing?->active ?? true);

        $errors = [];

        if ($category === SalaryComponent::CATEGORY_CTC_ADDON && $calcType === 'remainder') {
            $errors['calc_type'] = ['A CTC Addon component can\'t be the "remainder" — remainder only applies to earning components balancing against payable gross.'];
        }

        if ($category === SalaryComponent::CATEGORY_CTC_ADDON && strtoupper((string) $code) === 'BASIC') {
            $errors['category'] = ['The "BASIC" component must stay an Earning — Provident Fund is computed from it.'];
        }

        if ($calcType === 'percentage') {
            $this->validatePercentageOf($percentageOf, $sequence, $category, $errors);
        }

        if ($calcType === 'employee_field' && ! in_array($percentageOf, SalaryComponent::EMPLOYEE_FIELDS, true)) {
            $errors['percentage_of'] = ['An "employee field" component must name one of: ' . implode(', ', SalaryComponent::EMPLOYEE_FIELDS) . '.'];
        }

        // The remainder/BASIC-must-exist invariants below only govern earning
        // components — they balance against payable gross, which ctc_addon rows
        // (summed separately into Total CTC, never subtracted from Net Pay) have
        // nothing to do with.
        if ($active && $calcType === 'remainder' && $category === SalaryComponent::CATEGORY_EARNING) {
            $otherRemainder = SalaryComponent::where('calc_type', 'remainder')
                ->where('category', SalaryComponent::CATEGORY_EARNING)->where('active', true)
                ->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))
                ->exists();
            if ($otherRemainder) {
                $errors['calc_type'] = ['Only one component may be the "remainder" at a time — deactivate the existing one first.'];
            }
        }

        if ($active && $sequence > 0) {
            $sequenceClash = SalaryComponent::where('active', true)->where('sequence', $sequence)
                ->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))
                ->exists();
            if ($sequenceClash) {
                $errors['sequence'] = ["Another active component is already at sequence {$sequence}."];
            }
        }

        $willBeActive = fn (string $reservedCode) => $active && strtoupper((string) $code) === $reservedCode;
        $wasActive = fn (string $reservedCode) => $existing && strtoupper($existing->code) === $reservedCode && $existing->active;

        if ($wasActive('BASIC') && ! $willBeActive('BASIC')) {
            $this->assertAnotherActiveExists('BASIC', 'code', $existing, $errors, 'active',
                'A component with code "BASIC" must stay active — Provident Fund is computed from it.');
        }

        if ($existing && $existing->calc_type === 'remainder' && $existing->category === SalaryComponent::CATEGORY_EARNING && $existing->active
            && ! ($active && $calcType === 'remainder')) {
            $otherRemainder = SalaryComponent::where('calc_type', 'remainder')
                ->where('category', SalaryComponent::CATEGORY_EARNING)->where('active', true)
                ->where('id', '!=', $existing->id)->exists();
            if (! $otherRemainder) {
                $errors['calc_type'] = ['At least one active "remainder" component is required to balance the template to payable gross.'];
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function assertSafeToDelete(SalaryComponent $component): void
    {
        $errors = [];

        if ($component->active && strtoupper($component->code) === 'BASIC') {
            $this->assertAnotherActiveExists('BASIC', 'code', $component, $errors, 'code',
                'The active "BASIC" component cannot be deleted — Provident Fund is computed from it.');
        }

        if ($component->active && $component->calc_type === 'remainder' && $component->category === SalaryComponent::CATEGORY_EARNING) {
            $otherRemainder = SalaryComponent::where('calc_type', 'remainder')
                ->where('category', SalaryComponent::CATEGORY_EARNING)->where('active', true)
                ->where('id', '!=', $component->id)->exists();
            if (! $otherRemainder) {
                $errors['calc_type'] = ['The active "remainder" component cannot be deleted — at least one is required to balance the template.'];
            }
        }

        // Nothing else may reference this component's code in a percentage_of.
        $dependents = SalaryComponent::where('percentage_of', $component->code)->where('id', '!=', $component->id)->pluck('name');
        if ($dependents->isNotEmpty()) {
            $errors['code'] = ['Still referenced by: ' . $dependents->implode(', ') . ' — update or remove those first.'];
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function validatePercentageOf(?string $percentageOf, int $sequence, string $category, array &$errors): void
    {
        if (empty($percentageOf)) {
            $errors['percentage_of'] = ['A percentage component must reference what it\'s a percentage of.'];

            return;
        }

        if ($percentageOf === SalaryComponent::GROSS) {
            if ($category === SalaryComponent::CATEGORY_EARNING) {
                $errors['percentage_of'] = ['Gross is now a derived total (the sum of the earning components), not an input — an earning component can\'t be a percentage of it. Reference BASIC or another earning component by code instead.'];
            }

            return;
        }

        $reference = SalaryComponent::where('code', $percentageOf)->first();
        if (! $reference) {
            $errors['percentage_of'] = ["No component with code \"{$percentageOf}\" exists."];
        } elseif (! $reference->active) {
            $errors['percentage_of'] = ["\"{$percentageOf}\" is not active."];
        } elseif ((int) $reference->sequence >= $sequence) {
            $errors['percentage_of'] = ['A percentage component may only reference an earlier component (smaller sequence), to prevent circular references.'];
        } elseif ($reference->category !== SalaryComponent::CATEGORY_EARNING) {
            $errors['percentage_of'] = ["\"{$percentageOf}\" is a CTC Addon — a percentage can only reference an Earning component or Gross."];
        }
    }

    private function assertAnotherActiveExists(string $code, string $codeColumn, ?SalaryComponent $existing, array &$errors, string $errorKey, string $message): void
    {
        $otherExists = SalaryComponent::where($codeColumn, $code)->where('active', true)
            ->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))
            ->exists();
        if (! $otherExists) {
            $errors[$errorKey] = [$message];
        }
    }
}
