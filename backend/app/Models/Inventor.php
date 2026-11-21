<?php

namespace App\Models;

use App\Casts\EncryptedSafe;
use App\Models\Concerns\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A person named as inventor on one or more cases, with the particulars IPO Form 1
 * asks for. Needs no login; `user_id` is set only when the inventor is given portal
 * access (role `inventor`), which scopes what they can see to their own cases.
 */
class Inventor extends Model
{
    use BelongsToFirm;

    /** Particulars Form 1 needs for every inventor (gender and age are optional). */
    public const REQUIRED_FIELDS = ['full_name', 'nationality', 'city', 'country', 'pincode'];

    protected $fillable = [
        'firm_id', 'full_name', 'gender', 'age', 'nationality', 'country_of_residence',
        'address_house', 'address_street', 'city', 'state', 'country', 'pincode',
        'email', 'phone', 'user_id', 'created_by_id',
    ];

    protected $casts = [
        'gender'         => EncryptedSafe::class,
        'age'            => EncryptedSafe::class,
        'address_house'  => EncryptedSafe::class,
        'address_street' => EncryptedSafe::class,
        'pincode'        => EncryptedSafe::class,
        'phone'          => EncryptedSafe::class,
    ];

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_inventors')->withPivot('sequence')->withTimestamps();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Everything Form 1 needs is on record (an Indian address also needs its state). */
    public function isComplete(): bool
    {
        foreach (self::REQUIRED_FIELDS as $field) {
            if (blank($this->{$field})) {
                return false;
            }
        }
        if (blank($this->address_house) && blank($this->address_street)) {
            return false;
        }

        return ! (in_array(strtolower(trim((string) $this->country)), ['india', 'in'], true) && blank($this->state));
    }

    /** The shape the API returns to the case form and the Forms tab. */
    public function toSummary(): array
    {
        return [
            'id'                   => $this->id,
            'full_name'            => $this->full_name,
            'gender'               => $this->gender,
            'age'                  => $this->age,
            'nationality'          => $this->nationality,
            'country_of_residence' => $this->country_of_residence,
            'address_house'        => $this->address_house,
            'address_street'       => $this->address_street,
            'city'                 => $this->city,
            'state'                => $this->state,
            'country'              => $this->country,
            'pincode'              => $this->pincode,
            'email'                => $this->email,
            'phone'                => $this->phone,
            'has_portal_access'    => $this->user_id !== null,
            'complete'             => $this->isComplete(),
        ];
    }
}
