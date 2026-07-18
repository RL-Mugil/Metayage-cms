<?php

namespace App\Models;

use App\Models\Concerns\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventionFamily extends Model
{
    use BelongsToFirm;

    protected $fillable = ['firm_id', 'client_id', 'invention_number', 'title', 'earliest_priority_date', 'status'];

    protected $casts = ['earliest_priority_date' => 'date'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(PatentApplication::class)->orderBy('jurisdiction');
    }

    public function engagements(): HasMany
    {
        return $this->hasMany(Project::class)->orderBy('patent_office_code')->orderBy('created_at');
    }
}
