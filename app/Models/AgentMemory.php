<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AgentMemory extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'scope',
        'memory_key',
        'content',
        'embedding',
        'metadata',
        'last_accessed_at',
    ];

    protected $casts = [
        'embedding' => 'array',
        'metadata' => 'array',
        'last_accessed_at' => 'datetime',
    ];
}
