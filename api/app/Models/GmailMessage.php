<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'gmail_account_id',
    'gmail_thread_id',
    'gmail_message_id',
    'sender_email',
    'sender_name',
    'recipients',
    'cc',
    'subject',
    'snippet',
    'body_text',
    'gmail_received_at',
    'is_unread',
    'raw_payload',
])]
class GmailMessage extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'recipients' => 'array',
            'cc' => 'array',
            'gmail_received_at' => 'datetime',
            'is_unread' => 'boolean',
            'raw_payload' => 'array',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(GmailAccount::class, 'gmail_account_id');
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(GmailThread::class, 'gmail_thread_id');
    }

    public function drafts(): HasMany
    {
        return $this->hasMany(ReplyDraft::class);
    }
}