<?php

namespace Anwar\AgentOrchestrator\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Anwar\AgentOrchestrator\Models\AgentConversation;
use Anwar\AgentOrchestrator\Models\AgentMessage;

class StoreConversationTurn implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected string $phone,
        protected string $message,
        protected string $reply,
        protected string $platform = 'web',
        protected ?string $sessionId = null,
        protected array $metadata = []
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // 1. Find or create the conversation
        $conversation = AgentConversation::firstOrCreate(
            ['session_id' => $this->sessionId ?? $this->phone],
            [
                'phone' => $this->phone,
                'platform' => $this->platform,
                'metadata' => [
                    'source' => $this->platform,
                ]
            ]
        );

        // 2. Store User Message
        AgentMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $this->message,
            'metadata' => array_merge($this->metadata, ['type' => 'incoming']),
        ]);

        // 3. Store Assistant Reply
        AgentMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $this->reply,
            'metadata' => ['type' => 'outgoing'],
        ]);
    }
}
