<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CpiArticle extends Model
{
    protected $fillable = ['title', 'slug', 'excerpt', 'cover_image', 'cover_alt', 'body_html', 'meta_title', 'meta_description', 'author', 'status', 'published_at'];

    protected $casts = ['published_at' => 'datetime'];
}
