<?php

namespace App\Models;

use App\Models\Concerns\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class IpRecord extends Model
{
    use BelongsToFirm, SoftDeletes;

    protected $fillable = [
        'client_id', 'record_code', 'record_type', 'jurisdiction', 'title',
        'client_reference', 'legal_status', 'status_date', 'responsible_user_id',
        'backup_user_id', 'tags', 'data_quality_status', 'notes',
    ];

    protected $casts = ['status_date' => 'date', 'tags' => 'array'];

    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function responsibleUser(): BelongsTo { return $this->belongsTo(User::class, 'responsible_user_id'); }
    public function backupUser(): BelongsTo { return $this->belongsTo(User::class, 'backup_user_id'); }
    public function projects(): HasMany { return $this->hasMany(Project::class); }
    public function patentApplication(): HasOne { return $this->hasOne(PatentApplication::class); }
    public function trademarkApplication(): HasOne { return $this->hasOne(TrademarkApplication::class); }
    public function events(): HasMany { return $this->hasMany(DocketEvent::class); }
    public function deadlines(): HasMany { return $this->hasMany(DocketDeadline::class); }
}
