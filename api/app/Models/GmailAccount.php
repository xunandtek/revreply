<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\AsEncryptedArrayObject;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'google_email',
    'display_name',
    'scopes',
    'access_token',
    'refresh_token',
    'token_expires_at',
    'gmail_history_id',
    'watch_expires_at',
    'sync_status',
    'last_sync_started_at',
    'last_synced_at',
    'last_error_code',
    'last_error_message',
])]
class GmailAccount extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'watch_expires_at' => 'datetime',
            'last_sync_started_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function threads(): HasMany
    {
        return $this->hasMany(GmailThread::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(GmailMessage::class);
    }

    public function drafts(): HasMany
    {
        return $this->hasMany(ReplyDraft::class);
    }
}