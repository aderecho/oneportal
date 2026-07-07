<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NewsPost extends Model
{
    use HasFactory;

    protected $fillable = ['author_id', 'title', 'body', 'status', 'published_at'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isSuperAdmin()) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($user) {
            $query->whereHas('visibilities', fn (Builder $visibility) => $visibility->where('scope', 'organization'))
                ->orWhereHas('visibilities', fn (Builder $visibility) => $visibility
                    ->where('scope', 'department')
                    ->where('department_id', $user->department_id));
        });
    }

    public function visibilities()
    {
        return $this->hasMany(NewsPostVisibility::class);
    }
}
