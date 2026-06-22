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
    'subject',
    'snippet',
    'participants',
    'latest_message_at',
    'classification',
    'classification_confidence',
    'classification_reason',
    'status',
])]
class GmailThread extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'participants' => 'array',
            'latest_message_at' => 'datetime',
            'classification_confidence' => 'decimal:2',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(GmailAccount::class, 'gmail_account_id');
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