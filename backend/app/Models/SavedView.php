<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SavedView extends Model
{
    public const PAGES = ['patent_portfolio', 'dashboard', 'pending_payments'];

    protected $fillable = ['user_id', 'page', 'name', 'filters'];

    protected $casts = ['filters' => 'array'];
}
