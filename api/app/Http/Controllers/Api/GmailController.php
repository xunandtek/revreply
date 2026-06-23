<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GmailAccount;
use App\Models\GmailThread;
use App\Models\ReplyDraft;
use App\Services\GmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class GmailController extends Controller
{
    public function __construct(
        private readonly GmailService $gmailService,
    ) {
    }

    public function connect(): Response
    {
        return $this->gmailService->connect();
    }

    public function callback(Request $request): Response
    {
        return $this->gmailService->callback($request);
    }

    public function accounts(): JsonResponse
    {
        return $this->gmailService->accounts();
    }

    public function sync(GmailAccount $gmailAccount): JsonResponse
    {
        return $this->gmailService->sync($gmailAccount);
    }

    public function threads(): JsonResponse
    {
        return $this->gmailService->threads();
    }

    public function thread(GmailThread $gmailThread): JsonResponse
    {
        return $this->gmailService->thread($gmailThread);
    }

    public function updateDraft(Request $request, ReplyDraft $replyDraft): JsonResponse
    {
        return $this->gmailService->updateDraft($request, $replyDraft);
    }
}