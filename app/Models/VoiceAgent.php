<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoiceAgent extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'client_name',
        'display_name',
        'status',
        'is_available',
        'last_seen_at',
        'last_assigned_at',
        'current_call_session_id',
        'metadata',
    ];

    protected $casts = [
        'is_available' => 'boolean',
        'last_seen_at' => 'datetime',
        'last_assigned_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
