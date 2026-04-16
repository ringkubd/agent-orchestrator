<?php

namespace Anwar\AgentOrchestrator\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentMessage extends Model
{
    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
    ];

    /**
     * Get the conversation that owns the message.
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AgentConversation::class, 'conversation_id');
    }
}
