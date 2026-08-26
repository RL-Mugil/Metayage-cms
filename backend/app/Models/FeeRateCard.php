<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Government + professional fee rate card. See the fee_rate_cards migration
 * for the schema and database/migrations/*_seed_fee_rate_cards_initial_data.php
 * for how the firm's real IN/US/EP/PCT fee proposal maps onto it.
 */
class FeeRateCard extends Model
{
    protected $fillable = [
        'jurisdiction', 'service_code', 'entity_tier',
        'year_from', 'year_to', 'validation_country',
        'govt_fee_amount', 'govt_fee_currency',
        'professional_fee_amount', 'professional_fee_currency', 'professional_fee_max_amount',
        'professional_fee_charge_basis', 'fee_breakdown', 'notes', 'is_active',
    ];

    protected $casts = [
        'year_from' => 'decimal:1',
        'year_to' => 'decimal:1',
        'govt_fee_amount' => 'decimal:2',
        'professional_fee_amount' => 'decimal:2',
        'professional_fee_max_amount' => 'decimal:2',
        'fee_breakdown' => 'array',
        'is_active' => 'boolean',
    ];
}
