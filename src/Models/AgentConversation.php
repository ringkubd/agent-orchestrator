<?php

namespace Anwar\AgentOrchestrator\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentConversation extends Model
{
    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
    ];

    /**
     * Get the messages for the conversation.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(AgentMessage::class, 'conversation_id');
    }
}
