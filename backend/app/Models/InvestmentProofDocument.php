<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvestmentProofDocument extends Model
{
    protected $fillable = [
        'proof_of_investment_id',
        'section',
        'storage_path',
        'original_filename',
        'uploaded_by_id',
    ];

    public function proofOfInvestment(): BelongsTo
    {
        return $this->belongsTo(ProofOfInvestment::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }
}
