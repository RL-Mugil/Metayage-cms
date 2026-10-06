<?php

namespace App\Models;

use App\Casts\EncryptedSafe;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contractor extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'contractor_code',
        'name',
        'email',
        'phone',
        'pan_tax_id',
        'gstin',
        'bank_account_number',
        'bank_name',
        'bank_ifsc_code',
        'payment_terms',
        'tds_section',
        'status',
    ];

    protected $casts = [
        'pan_tax_id' => EncryptedSafe::class,
        'bank_account_number' => EncryptedSafe::class,
        'bank_ifsc_code' => EncryptedSafe::class,
    ];

    public function payments(): HasMany
    {
        return $this->hasMany(ContractorPayment::class)->orderByDesc('invoice_date');
    }
}
