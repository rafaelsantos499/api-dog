<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PostView extends Model
{
    protected $table = 'post_views';

    protected $fillable = [
        'post_id',
        'user_id',
    ];
}
