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
        Schema::create('agent_conversations', function (Blueprint $table) {
            $table->id();
            $table->string('session_id')->nullable()->index();
            $table->string('phone')->nullable()->index();
            $table->string('platform')->default('web');
            $table->json('metadata')->nullable();
            $table->timestamps();
            
            // Allow querying by both session and phone
            $table->index(['phone', 'session_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_conversations');
    }
};
