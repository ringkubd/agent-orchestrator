<?php

use Illuminate\Support\Facades\Route;
use Anwar\AgentOrchestrator\Http\Controllers\WebhookController;

Route::prefix('api/v1/agent')
    ->middleware('api')
    ->group(function () {
        Route::post('/webhook', [WebhookController::class, 'handleWebhook'])->name('agent.webhook');
    });
