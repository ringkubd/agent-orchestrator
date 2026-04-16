<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('agent_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('agent_conversations')->onDelete('cascade');
            $table->string('role'); // user, assistant
            $table->longText('content');
            $table->json('metadata')->nullable();
            $table->timestamps();
            
            $table->index('role');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_messages');
    }
};
