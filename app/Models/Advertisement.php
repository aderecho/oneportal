<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Advertisement extends Model
{
    use HasFactory;

    protected $fillable = [
        'author_id',
        'title',
        'body',
        'media_url',
        'media_type',
        'link_url',
        'starts_at',
        'ends_at',
        'is_forever',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_forever' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('status', 'active')
            ->where(fn (Builder $query) => $query
                ->whereNull('starts_at')
                ->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $query) => $query
                ->where('is_forever', true)
                ->orWhereNull('ends_at')
                ->orWhere('ends_at', '>=', now()));
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
