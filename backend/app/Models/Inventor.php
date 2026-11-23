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
        'firm_id', 'client_id', 'full_name', 'gender', 'age', 'nationality', 'country_of_residence',
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

    /** The client this inventor is managed under (the client panel's Inventors tab). */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Validation rules for an inventor's Form 1 particulars, shared by the Inventors tab,
     * the case picker and an Inventor login added from the Users tab. The address set Form 1
     * prints is required when adding; an edit may leave a field out but not blank it.
     * $data is the input being validated (to require the state for an Indian address).
     */
    public static function particularRules(bool $creating, array $data = []): array
    {
        $must = $creating ? 'required' : 'sometimes|required';
        $country = strtolower(trim((string) ($data['country'] ?? '')));
        $indian = in_array($country, ['india', 'in'], true);

        return [
            'gender'               => 'nullable|in:Male,Female,Others,Prefer not to disclose',
            'age'                  => 'nullable|integer|min:1|max:120',
            'nationality'          => "{$must}|string|max:100",
            'country_of_residence' => 'nullable|string|max:100',
            'address_house'        => ($creating ? 'required_without:address_street|' : '').'nullable|string|max:255',
            'address_street'       => ($creating ? 'required_without:address_house|' : '').'nullable|string|max:500',
            'city'                 => "{$must}|string|max:100",
            'state'                => ($indian && ($creating || array_key_exists('state', $data)) ? 'required|' : 'nullable|').'string|max:100',
            'country'              => "{$must}|string|max:100",
            'pincode'              => "{$must}|string|max:20",
            'email'                => 'nullable|email|max:255',
            'phone'                => 'nullable|string|max:30',
        ];
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
            'client_id'            => $this->client_id,
            'client_code'          => $this->client?->client_code,
            'client_name'          => $this->client ? ($this->client->legal_name ?: $this->client->company_name) : null,
            'case_count'           => $this->projects_count ?? $this->projects()->count(),
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
