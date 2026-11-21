<?php

namespace App\Models;

use App\Casts\EncryptedSafe;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** IPO-form particulars of an inventor-role User (see inventor_profiles migration). */
class InventorProfile extends Model
{
    protected $fillable = [
        'user_id', 'full_name', 'gender', 'age', 'nationality', 'country_of_residence',
        'address_house', 'address_street', 'city', 'state', 'country', 'pincode',
    ];

    protected $casts = [
        'gender'         => EncryptedSafe::class,
        'age'            => EncryptedSafe::class,
        'address_house'  => EncryptedSafe::class,
        'address_street' => EncryptedSafe::class,
        'pincode'        => EncryptedSafe::class,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
