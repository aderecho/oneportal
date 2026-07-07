<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    use HasFactory;

    protected $fillable = ['unit_id', 'name', 'code', 'slug', 'department_head_id', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function serviceProviders()
    {
        return $this->belongsToMany(ServiceProvider::class)
            ->withPivot('is_active')
            ->wherePivot('is_active', true)
            ->withTimestamps();
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }
}
