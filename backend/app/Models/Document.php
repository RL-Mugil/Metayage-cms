<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'project_id',
        'client_id',
        'file_name',
        'file_type',
        'file_size',
        'category',
        'storage_path',
        'current_version',
        'uploaded_by_id',
        'checked_out_by_id',
        'checked_out_at',
        'ocr_enabled',
        'ocr_content',
        'metadata',
        'status',
    ];

    protected $casts = [
        'metadata' => 'array',
        'checked_out_at' => 'datetime',
        'ocr_enabled' => 'boolean',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    public function checkedOutBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_out_by_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class);
    }
}
