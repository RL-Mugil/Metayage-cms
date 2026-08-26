<?php

namespace App\Models;

use App\Models\Concerns\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrademarkApplication extends Model
{
    use BelongsToFirm;

    protected $fillable = [
        'ip_record_id', 'application_number', 'registration_number', 'mark_text',
        'mark_type', 'nice_classes', 'goods_services', 'proprietor_name', 'filing_date',
        'journal_date', 'journal_number', 'registration_date', 'renewal_due_date',
        'office_status', 'office_status_date',
    ];

    protected $casts = [
        'nice_classes' => 'array', 'filing_date' => 'date', 'journal_date' => 'date',
        'registration_date' => 'date', 'renewal_due_date' => 'date', 'office_status_date' => 'date',
    ];

    public function ipRecord(): BelongsTo { return $this->belongsTo(IpRecord::class); }
}
