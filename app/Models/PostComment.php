<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class PostComment extends Model
{
    use SoftDeletes;

    protected $table = 'post_comments';

    protected $fillable = [
        'post_id',
        'user_id',
        'body',
    ];

    protected $hidden = [
        'id',
        'post_id',
        'user_id',
        'deleted_at',
    ];

    protected $casts = [
        'uuid' => 'string',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $comment) {
            if (empty($comment->uuid)) {
                $comment->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function post()
    {
        return $this->belongsTo(Posts::class, 'post_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
