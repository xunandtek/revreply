<?php

namespace App\Services;

use App\Models\GmailAccount;
use App\Models\GmailThread;
use App\Models\ReplyDraft;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GmailService
{
    public function connect(): JsonResponse
    {
        return response()->json([
            'message' => 'Gmail OAuth redirect is not wired yet.',
        ], 501);
    }

    public function callback(): JsonResponse
    {
        return response()->json([
            'message' => 'Gmail OAuth callback is not wired yet.',
        ], 501);
    }

    public function sync(GmailAccount $gmailAccount): JsonResponse
    {
        return response()->json([
            'message' => 'Manual Gmail sync is not wired yet.',
            'gmail_account_id' => $gmailAccount->id,
        ], 501);
    }

    public function threads(): JsonResponse
    {
        return response()->json([
            'data' => [],
            'message' => 'Thread listing will be wired in a later step.',
        ]);
    }

    public function thread(GmailThread $gmailThread): JsonResponse
    {
        return response()->json([
            'data' => $gmailThread,
            'message' => 'Thread detail is a placeholder until sync is wired.',
        ]);
    }

    public function updateDraft(Request $request, ReplyDraft $replyDraft): JsonResponse
    {
        return response()->json([
            'message' => 'Draft updates are not wired yet.',
            'draft_id' => $replyDraft->id,
            'payload' => $request->all(),
        ], 501);
    }
}