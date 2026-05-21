<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DomainLog extends Model
{
    protected $fillable = [
        'step',
        'request',
        'response',
        'success',
    ];

    protected $casts = [
        'success' => 'boolean',
        'request' => 'array',
        'response' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
