<?php

namespace App\Services;

use App\Models\GmailAccount;
use App\Models\GmailMessage;
use App\Models\GmailThread;
use App\Models\ReplyDraft;
use App\Services\DraftGenerationService;
use App\Services\ThreadClassifierService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class GmailService
{
    public function __construct(
        private readonly ThreadClassifierService $threadClassifierService,
        private readonly DraftGenerationService $draftGenerationService,
    ) {
    }

    public function connect(): Response
    {
        if (! $this->hasGoogleOauthConfig()) {
            return response()->json([
                'message' => 'Google OAuth is not configured.',
                'required_env' => [
                    'GOOGLE_CLIENT_ID',
                    'GOOGLE_CLIENT_SECRET',
                    'GOOGLE_REDIRECT_URI',
                ],
            ], 500);
        }

        $state = Str::random(40);

        Cache::put($this->oauthStateCacheKey($state), true, now()->addMinutes(10));

        return redirect()->away($this->googleAuthorizationUrl($state));
    }

    public function callback(Request $request): Response
    {
        if ($request->filled('error')) {
            return $this->redirectToFrontend('error', [
                'message' => $request->string('error')->toString(),
            ]);
        }

        $state = $request->string('state')->toString();

        if ($state === '' || ! Cache::pull($this->oauthStateCacheKey($state))) {
            return $this->redirectToFrontend('error', [
                'message' => 'Invalid or expired OAuth state.',
            ]);
        }

        $code = $request->string('code')->toString();

        if ($code === '') {
            return $this->redirectToFrontend('error', [
                'message' => 'Missing Google authorization code.',
            ]);
        }

        $tokenPayload = Http::asForm()
            ->post('https://oauth2.googleapis.com/token', [
                'code' => $code,
                'client_id' => (string) config('services.google.client_id'),
                'client_secret' => (string) config('services.google.client_secret'),
                'redirect_uri' => (string) config('services.google.redirect_uri'),
                'grant_type' => 'authorization_code',
            ])
            ->throw()
            ->json();

        $profile = Http::withToken($tokenPayload['access_token'])
            ->get('https://gmail.googleapis.com/gmail/v1/users/me/profile')
            ->throw()
            ->json();

        $gmailAccount = GmailAccount::query()->firstOrNew([
            'google_email' => $profile['emailAddress'],
        ]);

        $gmailAccount->fill([
            'display_name' => $gmailAccount->display_name ?: $profile['emailAddress'],
            'scopes' => $this->normalizeScopes($tokenPayload['scope'] ?? null),
            'access_token' => $tokenPayload['access_token'],
            'refresh_token' => $tokenPayload['refresh_token'] ?? $gmailAccount->refresh_token,
            'token_expires_at' => isset($tokenPayload['expires_in'])
                ? Carbon::now()->addSeconds((int) $tokenPayload['expires_in'])
                : null,
            'gmail_history_id' => $profile['historyId'] ?? null,
            'sync_status' => 'connected',
            'last_error_code' => null,
            'last_error_message' => null,
        ]);

        $gmailAccount->save();

        return $this->redirectToFrontend('success', [
            'gmail_account_id' => (string) $gmailAccount->id,
            'email' => $gmailAccount->google_email,
        ]);
    }

    public function sync(GmailAccount $gmailAccount): JsonResponse
    {
        if (! filled($gmailAccount->access_token)) {
            return response()->json([
                'message' => 'Gmail account is missing an access token.',
                'gmail_account_id' => $gmailAccount->id,
            ], 409);
        }

        if ($gmailAccount->token_expires_at !== null && $gmailAccount->token_expires_at->isPast()) {
            return response()->json([
                'message' => 'Gmail access token is expired. Reconnect the account to continue.',
                'gmail_account_id' => $gmailAccount->id,
            ], 409);
        }

        $gmailAccount->forceFill([
            'sync_status' => 'syncing',
            'last_sync_started_at' => now(),
            'last_error_code' => null,
            'last_error_message' => null,
        ])->save();

        try {
            $syncedThreads = $this->syncInboxThreads($gmailAccount);

            $gmailAccount->forceFill([
                'sync_status' => 'connected',
                'last_synced_at' => now(),
            ])->save();

            return response()->json([
                'message' => 'Gmail inbox synced successfully.',
                'gmail_account_id' => $gmailAccount->id,
                'threads_synced' => $syncedThreads->count(),
                'messages_synced' => $syncedThreads->sum(
                    fn (GmailThread $thread) => $thread->messages->count(),
                ),
            ]);
        } catch (Throwable $throwable) {
            $gmailAccount->forceFill([
                'sync_status' => 'sync_failed',
                'last_error_code' => class_basename($throwable),
                'last_error_message' => Str::limit($throwable->getMessage(), 1000),
            ])->save();

            report($throwable);

            return response()->json([
                'message' => 'Gmail sync failed.',
                'gmail_account_id' => $gmailAccount->id,
            ], 502);
        }
    }

    public function threads(): JsonResponse
    {
        return response()->json([
            'data' => GmailThread::query()
                ->withCount('messages')
                ->orderByDesc('latest_message_at')
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    public function thread(GmailThread $gmailThread): JsonResponse
    {
        return response()->json([
            'data' => $gmailThread->load([
                'messages' => fn ($query) => $query->orderBy('gmail_received_at'),
                'drafts' => fn ($query) => $query->latest('created_at'),
            ]),
        ]);
    }

    public function updateDraft(Request $request, ReplyDraft $replyDraft): JsonResponse
    {
        $validated = $request->validate([
            'draft_subject' => ['nullable', 'string', 'max:255'],
            'draft_body' => ['nullable', 'string'],
            'status' => ['nullable', 'in:generated,edited,approved'],
        ]);

        $status = $validated['status'] ?? 'edited';

        $replyDraft->fill([
            'draft_subject' => $validated['draft_subject'] ?? $replyDraft->draft_subject,
            'draft_body' => $validated['draft_body'] ?? $replyDraft->draft_body,
            'status' => $status,
            'approved_at' => $status === 'approved' ? now() : null,
        ]);

        $replyDraft->save();

        return response()->json([
            'message' => 'Draft updated successfully.',
            'data' => $replyDraft->fresh(),
        ]);
    }

    private function hasGoogleOauthConfig(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'))
            && filled(config('services.google.redirect_uri'));
    }

    private function googleAuthorizationUrl(string $state): string
    {
        $query = http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => config('services.google.redirect_uri'),
            'response_type' => 'code',
            'scope' => implode(' ', config('services.google.scopes', [])),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.$query;
    }

    private function normalizeScopes(null|string $scopes): array
    {
        if ($scopes === null || trim($scopes) === '') {
            return [];
        }

        return preg_split('/\s+/', trim($scopes)) ?: [];
    }

    private function oauthStateCacheKey(string $state): string
    {
        return 'gmail-oauth-state:'.$state;
    }

    private function redirectToFrontend(string $status, array $params = []): RedirectResponse|JsonResponse
    {
        $frontendUrl = config('services.google.frontend_redirect_url');

        if (! filled($frontendUrl)) {
            return response()->json([
                'status' => $status,
                ...$params,
            ]);
        }

        $query = http_build_query([
            'gmail_oauth' => $status,
            ...$params,
        ]);

        return redirect()->away(rtrim((string) $frontendUrl, '/').'/'.($query === '' ? '' : '?'.$query));
    }

    private function syncInboxThreads(GmailAccount $gmailAccount): Collection
    {
        $threadIds = collect($this->gmailApi($gmailAccount)
            ->get('https://gmail.googleapis.com/gmail/v1/users/me/threads', [
                'labelIds' => ['INBOX'],
                'maxResults' => 10,
            ])
            ->throw()
            ->json('threads', []))
            ->pluck('id')
            ->filter();

        return $threadIds->map(function (string $threadId) use ($gmailAccount): GmailThread {
            $payload = $this->gmailApi($gmailAccount)
                ->get("https://gmail.googleapis.com/gmail/v1/users/me/threads/{$threadId}", [
                    'format' => 'full',
                ])
                ->throw()
                ->json();

            return $this->persistThreadPayload($gmailAccount, $payload);
        });
    }

    private function persistThreadPayload(GmailAccount $gmailAccount, array $payload): GmailThread
    {
        $normalizedMessages = collect($payload['messages'] ?? [])
            ->map(fn (array $message) => $this->normalizeMessage($message));

        $latestMessage = $normalizedMessages
            ->filter(fn (array $message) => $message['gmail_received_at'] !== null)
            ->sortByDesc('gmail_received_at')
            ->first();

        $gmailThread = GmailThread::query()->updateOrCreate(
            [
                'gmail_account_id' => $gmailAccount->id,
                'gmail_thread_id' => $payload['id'],
            ],
            [
                'subject' => $normalizedMessages->pluck('subject')->filter()->first(),
                'snippet' => $payload['snippet'] ?? null,
                'participants' => $this->buildParticipants($normalizedMessages),
                'latest_message_at' => $latestMessage['gmail_received_at'] ?? null,
                'status' => 'pending_review',
            ],
        );

        $normalizedMessages->each(function (array $message) use ($gmailAccount, $gmailThread): void {
            GmailMessage::query()->updateOrCreate(
                [
                    'gmail_account_id' => $gmailAccount->id,
                    'gmail_message_id' => $message['gmail_message_id'],
                ],
                [
                    'gmail_thread_id' => $gmailThread->id,
                    'sender_email' => $message['sender_email'],
                    'sender_name' => $message['sender_name'],
                    'recipients' => $message['recipients'],
                    'cc' => $message['cc'],
                    'subject' => $message['subject'],
                    'snippet' => $message['snippet'],
                    'body_text' => $message['body_text'],
                    'gmail_received_at' => $message['gmail_received_at'],
                    'is_unread' => $message['is_unread'],
                    'raw_payload' => $message['raw_payload'],
                ],
            );
        });

        $gmailThread->load(['messages' => fn ($query) => $query->orderByDesc('gmail_received_at')]);

        $classification = $this->threadClassifierService->classify($gmailThread);

        $gmailThread->forceFill([
            'classification' => $classification['label'],
            'classification_confidence' => $classification['confidence'],
            'classification_reason' => $classification['reason'],
        ])->save();

        $this->upsertDraft($gmailAccount, $gmailThread);

        return $gmailThread->load(['messages', 'drafts']);
    }

    private function normalizeMessage(array $message): array
    {
        $payload = $message['payload'] ?? [];
        $headers = $this->headersByName($payload['headers'] ?? []);
        $from = $this->parseMailbox($headers['from'] ?? null);

        return [
            'gmail_message_id' => $message['id'],
            'sender_email' => $from['email'],
            'sender_name' => $from['name'],
            'recipients' => $this->parseMailboxList($headers['to'] ?? null),
            'cc' => $this->parseMailboxList($headers['cc'] ?? null),
            'subject' => $headers['subject'] ?? null,
            'snippet' => $message['snippet'] ?? null,
            'body_text' => $this->extractPlainTextBody($payload),
            'gmail_received_at' => isset($message['internalDate'])
                ? Carbon::createFromTimestampMs((int) $message['internalDate'])
                : null,
            'is_unread' => in_array('UNREAD', $message['labelIds'] ?? [], true),
            'raw_payload' => $message,
        ];
    }

    private function headersByName(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $header) {
            if (! isset($header['name'], $header['value'])) {
                continue;
            }

            $normalized[strtolower($header['name'])] = $header['value'];
        }

        return $normalized;
    }

    private function parseMailbox(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return ['name' => null, 'email' => null];
        }

        preg_match('/^(?:(?P<name>.*)\s)?<(?P<email>[^>]+)>$/', trim($value), $matches);

        if ($matches !== []) {
            return [
                'name' => trim(trim($matches['name'] ?? ''), '"') ?: null,
                'email' => $matches['email'] ?? null,
            ];
        }

        return [
            'name' => null,
            'email' => trim($value),
        ];
    }

    private function parseMailboxList(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        return collect(explode(',', $value))
            ->map(fn (string $item) => $this->parseMailbox($item))
            ->filter(fn (array $item) => filled($item['email']))
            ->values()
            ->all();
    }

    private function extractPlainTextBody(array $payload): ?string
    {
        $mimeType = $payload['mimeType'] ?? null;
        $bodyData = $payload['body']['data'] ?? null;

        if ($mimeType === 'text/plain' && is_string($bodyData)) {
            return $this->decodeBody($bodyData);
        }

        foreach ($payload['parts'] ?? [] as $part) {
            $text = $this->extractPlainTextBody($part);

            if (filled($text)) {
                return $text;
            }
        }

        if (is_string($bodyData)) {
            return $this->decodeBody($bodyData);
        }

        return null;
    }

    private function decodeBody(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        if ($decoded === false) {
            return null;
        }

        return trim($decoded) ?: null;
    }

    private function buildParticipants(Collection $normalizedMessages): array
    {
        return $normalizedMessages
            ->flatMap(function (array $message): array {
                return array_values(array_filter([
                    $message['sender_email'] ? [
                        'name' => $message['sender_name'],
                        'email' => $message['sender_email'],
                    ] : null,
                    ...$message['recipients'],
                    ...$message['cc'],
                ]));
            })
            ->unique('email')
            ->values()
            ->all();
    }

    private function gmailApi(GmailAccount $gmailAccount)
    {
        return Http::withToken($gmailAccount->access_token)
            ->acceptJson();
    }

    private function upsertDraft(GmailAccount $gmailAccount, GmailThread $gmailThread): void
    {
        $latestMessage = $gmailThread->messages->sortByDesc('gmail_received_at')->first();

        if (! $latestMessage instanceof GmailMessage) {
            return;
        }

        $draft = $this->draftGenerationService->generate($gmailThread, $latestMessage);

        ReplyDraft::query()->updateOrCreate(
            [
                'gmail_account_id' => $gmailAccount->id,
                'gmail_thread_id' => $gmailThread->id,
                'gmail_message_id' => $latestMessage->id,
            ],
            [
                'draft_subject' => $draft['draft_subject'],
                'draft_body' => $draft['draft_body'],
                'status' => $draft['status'],
                'generation_source' => $draft['generation_source'],
            ],
        );
    }
}