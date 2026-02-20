<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoiceCallEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'voice_call_id',
        'event_type',
        'occurred_at',
        'payload',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'payload' => 'array',
    ];

    public function voiceCall(): BelongsTo
    {
        return $this->belongsTo(VoiceCall::class);
    }
}
