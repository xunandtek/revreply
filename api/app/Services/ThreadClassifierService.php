<?php

namespace App\Services;

use App\Models\GmailThread;
use Illuminate\Support\Str;

class ThreadClassifierService
{
    public function classify(GmailThread $gmailThread): array
    {
        $latestMessage = $gmailThread->messages->sortByDesc('gmail_received_at')->first();

        $content = Str::lower(trim(implode("\n", array_filter([
            $gmailThread->subject,
            $gmailThread->snippet,
            $latestMessage?->subject,
            $latestMessage?->snippet,
            $latestMessage?->body_text,
        ]))));

        if ($this->containsAny($content, ['not interested', 'no thanks', 'unsubscribe', 'remove me'])) {
            return [
                'label' => 'not_interested',
                'confidence' => 0.96,
                'reason' => 'The message contains a direct decline or unsubscribe language.',
            ];
        }

        if ($this->containsAny($content, ['meeting', 'call', 'schedule', 'calendar', 'availability', 'available next week'])) {
            return [
                'label' => 'meeting_request',
                'confidence' => 0.93,
                'reason' => 'The message asks to coordinate time or schedule a conversation.',
            ];
        }

        if ($this->containsAny($content, ['interested', 'sounds good', 'learn more', 'love to', 'keen to'])) {
            return [
                'label' => 'interested',
                'confidence' => 0.88,
                'reason' => 'The sender expresses positive interest or asks to continue the conversation.',
            ];
        }

        return [
            'label' => 'unclear',
            'confidence' => 0.55,
            'reason' => 'The message does not match the current heuristic rules strongly enough.',
        ];
    }

    private function containsAny(string $content, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (Str::contains($content, $needle)) {
                return true;
            }
        }

        return false;
    }
}