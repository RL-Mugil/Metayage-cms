<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CpiPatent extends Model
{
    public const COUNTRIES = ['India', 'USA', 'Others'];

    /** Suggested domains (the admin may also type a new one). */
    public const DOMAINS = [
        'Artificial Intelligence', 'Telecommunication', 'Electric Vehicle', 'Healthcare', 'Cryptography', '3D / AR / VR', 'Blockchain',
        'Manufacturing', 'Business Intelligence', 'Network Management', 'Food and Beverages', 'Aerospace Engineering',
        'Bio-Medical Engineering', 'Polymer Technology',
    ];

    protected $fillable = ['patent_number', 'slug', 'title', 'domain', 'country', 'abstract', 'image', 'image_alt', 'status', 'published_at'];

    protected $casts = ['published_at' => 'datetime'];
}
