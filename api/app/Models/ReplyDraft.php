<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'gmail_account_id',
    'gmail_thread_id',
    'gmail_message_id',
    'draft_subject',
    'draft_body',
    'status',
    'generation_source',
    'approved_at',
    'sent_at',
])]
class ReplyDraft extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'sent_at' => 'datetime',
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

    public function message(): BelongsTo
    {
        return $this->belongsTo(GmailMessage::class, 'gmail_message_id');
    }
}