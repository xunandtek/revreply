<?php

use App\Http\Controllers\Api\GmailController;
use Illuminate\Support\Facades\Route;

Route::prefix('integrations/gmail')->group(function (): void {
    Route::get('connect', [GmailController::class, 'connect']);
    Route::get('callback', [GmailController::class, 'callback']);
    Route::get('accounts', [GmailController::class, 'accounts']);
    Route::post('accounts/{gmailAccount}/sync', [GmailController::class, 'sync']);
});

Route::get('threads', [GmailController::class, 'threads']);
Route::get('threads/{gmailThread}', [GmailController::class, 'thread']);
Route::patch('drafts/{replyDraft}', [GmailController::class, 'updateDraft']);