<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Crypt;

class CpiBooking extends Model
{
    protected $fillable = [
        'start_utc', 'end_utc', 'name', 'email', 'organization', 'topic', 'visitor_timezone', 'status', 'manage_token_hash',
        'sequence', 'reschedule_count', 'rescheduled_from_utc', 'last_rescheduled_by', 'manage_token_enc',
    ];

    protected $hidden = ['manage_token_hash', 'manage_token_enc'];

    protected $casts = ['start_utc' => 'datetime', 'end_utc' => 'datetime', 'rescheduled_from_utc' => 'datetime'];

    /** IPFlow staff invited to this call. */
    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'cpi_booking_staff', 'booking_id', 'user_id')->withTimestamps();
    }

    /** The visitor's manage token, if this booking stored one (older bookings did not). */
    public function manageToken(): ?string
    {
        try {
            return $this->manage_token_enc ? Crypt::decryptString($this->manage_token_enc) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function storeManageToken(string $token): void
    {
        $this->update(['manage_token_hash' => hash('sha256', $token), 'manage_token_enc' => Crypt::encryptString($token)]);
    }

    /** The visitor's own zone when valid, else the given fallback. */
    public function visitorZone(string $fallback): string
    {
        $tz = (string) $this->visitor_timezone;
        try {
            return $tz !== '' ? (new \DateTimeZone($tz))->getName() : $fallback;
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
