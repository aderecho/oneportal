<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NewsPostVisibility extends Model
{
    protected $fillable = ['news_post_id', 'department_id', 'scope'];
}
