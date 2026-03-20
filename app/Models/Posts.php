<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Posts extends Model
{
    use HasFactory, SoftDeletes;

    // Table renamed to 'posts' in migration to represent social posts
    protected $table = 'posts';

    protected $fillable = [
        'uuid',
        'user_id',
        'original_path',
        'feed_path',
        'thumb_path',
        'access',
        'weight',
        'age',
        'title',
        'description',
        'views',
        'likes',
        'shares',
        'comments_count',
        'is_published',
        'published_at',
    ];

    protected $casts = [
        'views' => 'integer',
        'likes' => 'integer',
        'shares' => 'integer',
        'comments_count' => 'integer',
        'is_published' => 'boolean',
        'published_at' => 'datetime',
        'uuid' => 'string'
    ];

    protected $appends = [
        'original_url',
        'feed_url',
        'thumb_url',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Use `uuid` for route model binding instead of numeric `id`.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function getOriginalUrlAttribute(): ?string
    {
        if (empty($this->original_path)) {
            return null;
        }

        $svc = app(\App\Services\StorageService::class);

        return $svc->url($this->original_path);
    }

    public function getFeedUrlAttribute(): ?string
    {
        if (empty($this->feed_path)) {
            return null;
        }

        $svc = app(\App\Services\StorageService::class);

        return $svc->url($this->feed_path);
    }

    public function getThumbUrlAttribute(): ?string
    {
        if (empty($this->thumb_path)) {
            return null;
        }

        $svc = app(\App\Services\StorageService::class);

        return $svc->url($this->thumb_path);
    }
}
