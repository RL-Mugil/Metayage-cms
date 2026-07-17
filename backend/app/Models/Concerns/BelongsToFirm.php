<?php

namespace App\Models\Concerns;

use App\Models\Firm;
use App\Support\FirmContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToFirm
{
    public static function bootBelongsToFirm(): void
    {
        static::creating(function ($model): void {
            if ($model->getAttribute('firm_id') === null) {
                $model->setAttribute('firm_id', app(FirmContext::class)->idOrSingleActiveFirm());
            }
        });
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }
}
