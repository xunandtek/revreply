<?php

namespace App\Services;

use App\Models\GmailAccount;
use App\Models\GmailThread;
use App\Models\ReplyDraft;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class GmailService
{
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
}