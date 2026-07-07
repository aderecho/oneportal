<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServiceProvider extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'entity_id',
        'acs_url',
        'slo_url',
        'launch_url',
        'x509_cert',
        'signing_algo',
        'default_relay_state',
        'status',
        'attribute_release',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'attribute_release' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
