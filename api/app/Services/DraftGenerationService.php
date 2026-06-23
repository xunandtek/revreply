<?php

namespace App\Services;

use App\Models\GmailMessage;
use App\Models\GmailThread;

class DraftGenerationService
{
    public function generate(GmailThread $gmailThread, GmailMessage $latestMessage): array
    {
        return match ($gmailThread->classification) {
            'meeting_request' => [
                'draft_subject' => $latestMessage->subject ?: 'Re: '.$gmailThread->subject,
                'draft_body' => implode("\n\n", [
                    'Hi '.$this->recipientName($latestMessage).',',
                    'Thanks for reaching out. I would be happy to continue the conversation.',
                    'Please send over a couple of times that work for you next week and I can confirm one of them.',
                    'Best,',
                    'Billy',
                ]),
                'status' => 'generated',
                'generation_source' => 'stub',
            ],
            'interested' => [
                'draft_subject' => $latestMessage->subject ?: 'Re: '.$gmailThread->subject,
                'draft_body' => implode("\n\n", [
                    'Hi '.$this->recipientName($latestMessage).',',
                    'Thanks for the note. I appreciate the interest and would be glad to share more details.',
                    'Let me know what context would be most useful, or feel free to send over a few times if a quick call is easier.',
                    'Best,',
                    'Billy',
                ]),
                'status' => 'generated',
                'generation_source' => 'stub',
            ],
            'not_interested' => [
                'draft_subject' => $latestMessage->subject ?: 'Re: '.$gmailThread->subject,
                'draft_body' => implode("\n\n", [
                    'Hi '.$this->recipientName($latestMessage).',',
                    'Thanks for getting back to me. I understand and will close the loop here.',
                    'Appreciate your time.',
                    'Best,',
                    'Billy',
                ]),
                'status' => 'generated',
                'generation_source' => 'stub',
            ],
            default => [
                'draft_subject' => $latestMessage->subject ?: 'Re: '.$gmailThread->subject,
                'draft_body' => implode("\n\n", [
                    'Hi '.$this->recipientName($latestMessage).',',
                    'Thanks for the message. I want to make sure I understand your request correctly before replying in detail.',
                    'I will review this thread and follow up shortly.',
                    'Best,',
                    'Billy',
                ]),
                'status' => 'generated',
                'generation_source' => 'stub',
            ],
        };
    }

    private function recipientName(GmailMessage $latestMessage): string
    {
        return $latestMessage->sender_name ?: $latestMessage->sender_email ?: 'there';
    }
}