<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VoiceCall extends Model
{
    use HasFactory;

    protected $fillable = [
        'at_session_id',
        'direction',
        'status',
        'call_session_state',
        'is_active',
        'caller_number',
        'destination_number',
        'customer_number',
        'assigned_agent_client',
        'assigned_user_id',
        'queue_name',
        'queue_wait_started_at',
        'queue_wait_seconds',
        'started_at',
        'answered_at',
        'ended_at',
        'hangup_cause',
        'error_message',
        'payload',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'queue_wait_started_at' => 'datetime',
        'started_at' => 'datetime',
        'answered_at' => 'datetime',
        'ended_at' => 'datetime',
        'payload' => 'array',
    ];

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(VoiceCallEvent::class);
    }
}
