import { DashboardClient } from "./dashboard-client";

type SearchParams = Promise<{
  thread?: string;
  gmail_oauth?: string;
  email?: string;
  message?: string;
}>;

type GmailAccount = {
  id: number;
  google_email: string;
  display_name: string | null;
  sync_status: string;
  token_expires_at: string | null;
  last_synced_at: string | null;
  last_error_code: string | null;
  last_error_message: string | null;
};

type GmailThread = {
  id: number;
  gmail_thread_id: string;
  subject: string | null;
  snippet: string | null;
  classification: string | null;
  classification_confidence: string | null;
  classification_reason: string | null;
  latest_message_at: string | null;
  status: string;
  messages_count?: number;
};

type GmailMessage = {
  id: number;
  gmail_message_id: string;
  sender_email: string | null;
  sender_name: string | null;
  subject: string | null;
  snippet: string | null;
  body_text: string | null;
  gmail_received_at: string | null;
  is_unread: boolean;
};

type ReplyDraft = {
  id: number;
  draft_subject: string | null;
  draft_body: string | null;
  status: string;
  generation_source: string;
  approved_at: string | null;
};

type GmailThreadDetail = GmailThread & {
  messages: GmailMessage[];
  drafts: ReplyDraft[];
};

type ApiEnvelope<T> = {
  data: T;
};

const apiBaseUrl =
  process.env.API_URL ?? process.env.NEXT_PUBLIC_API_URL ?? "http://127.0.0.1:8000";

async function fetchJson<T>(path: string): Promise<T | null> {
  try {
    const response = await fetch(`${apiBaseUrl}${path}`, {
      cache: "no-store",
      headers: {
        Accept: "application/json",
      },
    });

    if (!response.ok) {
      return null;
    }

    return (await response.json()) as T;
  } catch {
    return null;
  }
}

function metricValue(count: number, singular: string, plural: string): string {
  return `${count} ${count === 1 ? singular : plural}`;
}

export default async function Home(props: { searchParams: SearchParams }) {
  const searchParams = await props.searchParams;

  const [accountsResponse, threadsResponse] = await Promise.all([
    fetchJson<ApiEnvelope<GmailAccount[]>>("/api/integrations/gmail/accounts"),
    fetchJson<ApiEnvelope<GmailThread[]>>("/api/threads"),
  ]);

  const accounts = accountsResponse?.data ?? [];
  const threads = threadsResponse?.data ?? [];
  const requestedThreadId = Number(searchParams.thread);
  const selectedThreadId = Number.isFinite(requestedThreadId)
    ? requestedThreadId
    : threads[0]?.id;

  const selectedThreadResponse = selectedThreadId
    ? await fetchJson<ApiEnvelope<GmailThreadDetail>>(`/api/threads/${selectedThreadId}`)
    : null;

  const selectedThread = selectedThreadResponse?.data ?? null;
  const oauthBanner = searchParams.gmail_oauth
    ? {
        status: searchParams.gmail_oauth,
        email: searchParams.email,
        message: searchParams.message,
      }
    : null;

  const selectedDraft = selectedThread?.drafts?.[0] ?? null;
  const latestMessage = selectedThread?.messages?.[selectedThread.messages.length - 1] ?? null;

  return (
    <main className="mx-auto flex min-h-screen w-full max-w-[1500px] flex-col px-5 py-6 sm:px-8 lg:px-10">
      <section className="grid gap-6 lg:grid-cols-[1.15fr_0.85fr]">
        <div className="rounded-[32px] border border-line bg-surface px-6 py-6 shadow-[0_28px_80px_rgba(92,65,39,0.10)] backdrop-blur md:px-8 md:py-8">
          <div className="flex flex-wrap items-start justify-between gap-6">
            <div className="max-w-2xl space-y-4">
              <div className="inline-flex items-center rounded-full border border-[rgba(168,79,45,0.16)] bg-accent-soft px-3 py-1 text-xs font-semibold uppercase tracking-[0.22em] text-accent">
                RevReply Take-Home
              </div>
              <div className="space-y-3">
                <h1 className="max-w-3xl text-4xl font-semibold tracking-[-0.04em] text-foreground sm:text-5xl">
                  Review incoming Gmail threads, classify intent, and stage reply drafts in one pass.
                </h1>
                <p className="max-w-2xl text-sm leading-7 text-[rgba(23,32,51,0.72)] sm:text-base">
                  This dashboard is intentionally scoped to a manual single-account workflow: connect Gmail,
                  pull recent inbox threads, inspect the stub classification, and edit the generated draft
                  before anything leaves the system.
                </p>
              </div>
            </div>

            <div className="grid min-w-[260px] gap-3 sm:grid-cols-3 lg:grid-cols-1 xl:grid-cols-3">
              <div className="rounded-3xl border border-line bg-surface-strong px-4 py-4">
                <p className="text-xs uppercase tracking-[0.2em] text-[rgba(23,32,51,0.54)]">Accounts</p>
                <p className="mt-3 text-3xl font-semibold">{accounts.length}</p>
                <p className="mt-2 text-sm text-[rgba(23,32,51,0.64)]">Connected Gmail inboxes</p>
              </div>
              <div className="rounded-3xl border border-line bg-surface-strong px-4 py-4">
                <p className="text-xs uppercase tracking-[0.2em] text-[rgba(23,32,51,0.54)]">Threads</p>
                <p className="mt-3 text-3xl font-semibold">{threads.length}</p>
                <p className="mt-2 text-sm text-[rgba(23,32,51,0.64)]">Synced review candidates</p>
              </div>
              <div className="rounded-3xl border border-line bg-surface-strong px-4 py-4">
                <p className="text-xs uppercase tracking-[0.2em] text-[rgba(23,32,51,0.54)]">Drafts</p>
                <p className="mt-3 text-3xl font-semibold">{selectedDraft ? 1 : 0}</p>
                <p className="mt-2 text-sm text-[rgba(23,32,51,0.64)]">Ready for review in the selected thread</p>
              </div>
            </div>
          </div>
        </div>

        <div className="rounded-[32px] border border-line bg-[rgba(23,32,51,0.94)] px-6 py-6 text-white shadow-[0_28px_80px_rgba(23,32,51,0.22)] md:px-8 md:py-8">
          <div className="flex h-full flex-col justify-between gap-6">
            <div className="space-y-4">
              <div className="inline-flex items-center rounded-full border border-white/15 px-3 py-1 text-xs uppercase tracking-[0.2em] text-white/70">
                Scope Decisions
              </div>
              <h2 className="text-2xl font-semibold tracking-[-0.03em]">Manual sync, draft-first, no live LLM.</h2>
              <p className="max-w-xl text-sm leading-7 text-white/74">
                The implementation favors a credible end-to-end slice over broad infrastructure work.
                OAuth, inbox import, heuristic classification, and draft review are running. Webhooks,
                token refresh automation, and model-backed drafting are left for the README plan.
              </p>
            </div>

            <div className="grid gap-3 sm:grid-cols-3 lg:grid-cols-1 xl:grid-cols-3">
              <div className="rounded-3xl border border-white/10 bg-white/6 px-4 py-4">
                <p className="text-xs uppercase tracking-[0.18em] text-white/54">Primary Account</p>
                <p className="mt-2 text-base font-medium text-white">
                  {accounts[0]?.display_name ?? accounts[0]?.google_email ?? "Not connected"}
                </p>
              </div>
              <div className="rounded-3xl border border-white/10 bg-white/6 px-4 py-4">
                <p className="text-xs uppercase tracking-[0.18em] text-white/54">Selected Thread</p>
                <p className="mt-2 text-base font-medium text-white">
                  {selectedThread?.subject ?? "Pick a thread"}
                </p>
              </div>
              <div className="rounded-3xl border border-white/10 bg-white/6 px-4 py-4">
                <p className="text-xs uppercase tracking-[0.18em] text-white/54">Latest Message</p>
                <p className="mt-2 text-base font-medium text-white">
                  {latestMessage?.sender_name ?? latestMessage?.sender_email ?? "No messages yet"}
                </p>
              </div>
            </div>
          </div>
        </div>
      </section>

      <section className="mt-6 grid gap-6 lg:grid-cols-[360px_minmax(0,1fr)]">
        <aside className="rounded-[30px] border border-line bg-surface px-4 py-4 shadow-[0_20px_60px_rgba(92,65,39,0.08)] md:px-5">
          <div className="mb-4 flex items-center justify-between px-2">
            <div>
              <p className="text-xs uppercase tracking-[0.2em] text-[rgba(23,32,51,0.5)]">Inbox Queue</p>
              <h2 className="mt-2 text-xl font-semibold">Threads awaiting review</h2>
            </div>
            <div className="rounded-full border border-line bg-surface-strong px-3 py-1 text-sm font-medium text-[rgba(23,32,51,0.7)]">
              {metricValue(threads.length, "thread", "threads")}
            </div>
          </div>

          <div className="space-y-3">
            {threads.length === 0 ? (
              <div className="rounded-3xl border border-dashed border-line bg-surface-strong px-4 py-6 text-sm leading-7 text-[rgba(23,32,51,0.62)]">
                No threads are stored yet. Connect Gmail and run a manual sync to populate the review queue.
              </div>
            ) : (
              threads.map((thread) => {
                const isSelected = thread.id === selectedThread?.id;

                return (
                  <div
                    key={thread.id}
                    className={[
                      "rounded-[26px] border px-4 py-4",
                      isSelected
                        ? "border-[rgba(168,79,45,0.42)] bg-[rgba(168,79,45,0.08)] shadow-[0_10px_30px_rgba(168,79,45,0.10)]"
                        : "border-line bg-surface-strong",
                    ].join(" ")}
                  >
                    <div className="flex items-start justify-between gap-3">
                      <div className="min-w-0">
                        <p className="truncate text-sm font-semibold text-foreground">
                          {thread.subject ?? "Untitled thread"}
                        </p>
                        <p className="mt-1 line-clamp-2 text-sm leading-6 text-[rgba(23,32,51,0.64)]">
                          {thread.snippet ?? "No snippet available."}
                        </p>
                      </div>
                      <span className="rounded-full border border-line bg-white/70 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-[rgba(23,32,51,0.62)]">
                        {thread.classification?.replaceAll("_", " ") ?? "pending"}
                      </span>
                    </div>
                    <div className="mt-4 flex items-center justify-between gap-2 text-xs text-[rgba(23,32,51,0.56)]">
                      <span>{metricValue(thread.messages_count ?? 0, "message", "messages")}</span>
                      <span>{thread.latest_message_at ? new Date(thread.latest_message_at).toLocaleDateString() : "No date"}</span>
                    </div>
                  </div>
                );
              })
            )}
          </div>
        </aside>

        <DashboardClient
          accounts={accounts}
          threads={threads}
          initialThread={selectedThread}
          oauthBanner={oauthBanner}
          apiBaseUrl={apiBaseUrl}
        />
      </section>
    </main>
  );
}
