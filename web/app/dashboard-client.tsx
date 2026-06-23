"use client";

import { useState, useTransition } from "react";

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

type OAuthBanner = {
  status?: string;
  email?: string;
  message?: string;
} | null;

type Props = {
  accounts: GmailAccount[];
  threads: GmailThread[];
  initialThread: GmailThreadDetail | null;
  oauthBanner: OAuthBanner;
  apiBaseUrl: string;
};

function formatClassification(value: string | null): string {
  if (!value) {
    return "Pending";
  }

  return value
    .split("_")
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join(" ");
}

function badgeClasses(value: string | null): string {
  switch (value) {
    case "meeting_request":
      return "border-[rgba(31,122,84,0.18)] bg-[rgba(31,122,84,0.10)] text-success";
    case "interested":
      return "border-[rgba(109,153,214,0.20)] bg-[rgba(109,153,214,0.12)] text-[#245595]";
    case "not_interested":
      return "border-[rgba(170,63,63,0.18)] bg-[rgba(170,63,63,0.10)] text-danger";
    default:
      return "border-[rgba(183,121,31,0.18)] bg-[rgba(183,121,31,0.10)] text-warning";
  }
}

export function DashboardClient({
  accounts,
  threads,
  initialThread,
  oauthBanner,
  apiBaseUrl,
}: Props) {
  const [selectedThreadId, setSelectedThreadId] = useState<number | null>(initialThread?.id ?? threads[0]?.id ?? null);
  const [selectedThread, setSelectedThread] = useState<GmailThreadDetail | null>(initialThread);
  const [draftSubject, setDraftSubject] = useState(initialThread?.drafts?.[0]?.draft_subject ?? "");
  const [draftBody, setDraftBody] = useState(initialThread?.drafts?.[0]?.draft_body ?? "");
  const [statusMessage, setStatusMessage] = useState<string | null>(
    oauthBanner?.status === "success"
      ? `Connected ${oauthBanner.email ?? "Gmail account"} successfully.`
      : oauthBanner?.message ?? null,
  );
  const [isSyncPending, startSyncTransition] = useTransition();
  const [isThreadPending, startThreadTransition] = useTransition();
  const [isDraftPending, startDraftTransition] = useTransition();

  const activeAccount = accounts[0] ?? null;
  const activeDraft = selectedThread?.drafts?.[0] ?? null;
  const latestMessage = selectedThread?.messages?.[selectedThread.messages.length - 1] ?? null;

  function applySelectedThread(thread: GmailThreadDetail | null) {
    setSelectedThread(thread);
    setDraftSubject(thread?.drafts?.[0]?.draft_subject ?? "");
    setDraftBody(thread?.drafts?.[0]?.draft_body ?? "");
  }

  async function loadThread(threadId: number) {
    startThreadTransition(async () => {
      setStatusMessage(null);

      try {
        const response = await fetch(`${apiBaseUrl}/api/threads/${threadId}`, {
          headers: { Accept: "application/json" },
        });

        if (!response.ok) {
          throw new Error("Failed to load thread detail.");
        }

        const payload = (await response.json()) as { data: GmailThreadDetail };
        setSelectedThreadId(threadId);
        applySelectedThread(payload.data);
      } catch (error) {
        setStatusMessage(error instanceof Error ? error.message : "Failed to load thread detail.");
      }
    });
  }

  async function syncInbox() {
    if (!activeAccount) {
      return;
    }

    startSyncTransition(async () => {
      setStatusMessage(null);

      try {
        const response = await fetch(`${apiBaseUrl}/api/integrations/gmail/accounts/${activeAccount.id}/sync`, {
          method: "POST",
          headers: { Accept: "application/json" },
        });

        const payload = (await response.json()) as { message?: string };

        if (!response.ok) {
          throw new Error(payload.message ?? "Manual sync failed.");
        }

        setStatusMessage(payload.message ?? "Manual sync completed. Refresh to load the latest inbox state.");
      } catch (error) {
        setStatusMessage(error instanceof Error ? error.message : "Manual sync failed.");
      }
    });
  }

  async function saveDraft(nextStatus: "edited" | "approved") {
    if (!activeDraft) {
      return;
    }

    startDraftTransition(async () => {
      setStatusMessage(null);

      try {
        const response = await fetch(`${apiBaseUrl}/api/drafts/${activeDraft.id}`, {
          method: "PATCH",
          headers: {
            Accept: "application/json",
            "Content-Type": "application/json",
          },
          body: JSON.stringify({
            draft_subject: draftSubject,
            draft_body: draftBody,
            status: nextStatus,
          }),
        });

        const payload = (await response.json()) as { data?: ReplyDraft; message?: string };

        if (!response.ok || !payload.data) {
          throw new Error(payload.message ?? "Failed to update draft.");
        }

        if (selectedThread) {
          applySelectedThread({
            ...selectedThread,
            drafts: [payload.data, ...selectedThread.drafts.slice(1)],
          });
        }

        setStatusMessage(payload.message ?? "Draft updated.");
      } catch (error) {
        setStatusMessage(error instanceof Error ? error.message : "Failed to update draft.");
      }
    });
  }

  return (
    <section className="rounded-[30px] border border-line bg-surface px-5 py-5 shadow-[0_20px_60px_rgba(92,65,39,0.08)] md:px-6 md:py-6">
      <div className="flex flex-col gap-5">
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div>
            <p className="text-xs uppercase tracking-[0.2em] text-[rgba(23,32,51,0.5)]">Review Workspace</p>
            <h2 className="mt-2 text-2xl font-semibold tracking-[-0.03em]">Draft editor and thread context</h2>
          </div>

          <div className="flex flex-wrap gap-3">
            <a
              href={`${apiBaseUrl}/api/integrations/gmail/connect`}
              className="inline-flex items-center rounded-full bg-accent px-4 py-2 text-sm font-semibold text-white shadow-[0_12px_24px_rgba(168,79,45,0.22)] hover:-translate-y-0.5 hover:bg-[#954627]"
            >
              Connect Gmail
            </a>
            <button
              type="button"
              onClick={syncInbox}
              disabled={!activeAccount || isSyncPending}
              className="inline-flex items-center rounded-full border border-line bg-surface-strong px-4 py-2 text-sm font-semibold text-foreground disabled:cursor-not-allowed disabled:opacity-50"
            >
              {isSyncPending ? "Syncing..." : "Run Manual Sync"}
            </button>
          </div>
        </div>

        {statusMessage ? (
          <div className="rounded-2xl border border-line bg-surface-strong px-4 py-3 text-sm text-[rgba(23,32,51,0.72)]">
            {statusMessage}
          </div>
        ) : null}

        <div className="grid gap-5 xl:grid-cols-[minmax(0,0.94fr)_minmax(320px,0.66fr)]">
          <div className="space-y-5">
            <div className="rounded-[28px] border border-line bg-surface-strong px-5 py-5">
              <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                  <p className="text-xs uppercase tracking-[0.18em] text-[rgba(23,32,51,0.5)]">Account Status</p>
                  <h3 className="mt-2 text-lg font-semibold">
                    {activeAccount?.display_name ?? activeAccount?.google_email ?? "No Gmail account connected"}
                  </h3>
                </div>
                {activeAccount ? (
                  <span className="rounded-full border border-line bg-white/70 px-3 py-1 text-xs font-semibold uppercase tracking-[0.16em] text-[rgba(23,32,51,0.62)]">
                    {activeAccount.sync_status.replaceAll("_", " ")}
                  </span>
                ) : null}
              </div>

              <div className="mt-4 grid gap-3 sm:grid-cols-2">
                <div className="rounded-2xl border border-line bg-white/60 px-4 py-3">
                  <p className="text-xs uppercase tracking-[0.16em] text-[rgba(23,32,51,0.48)]">Last Sync</p>
                  <p className="mt-2 text-sm font-medium text-[rgba(23,32,51,0.74)]">
                    {activeAccount?.last_synced_at
                      ? new Date(activeAccount.last_synced_at).toLocaleString()
                      : "No successful sync yet"}
                  </p>
                </div>
                <div className="rounded-2xl border border-line bg-white/60 px-4 py-3">
                  <p className="text-xs uppercase tracking-[0.16em] text-[rgba(23,32,51,0.48)]">Token State</p>
                  <p className="mt-2 text-sm font-medium text-[rgba(23,32,51,0.74)]">
                    {activeAccount?.token_expires_at
                      ? `Expires ${new Date(activeAccount.token_expires_at).toLocaleString()}`
                      : "Stored without expiry"}
                  </p>
                </div>
              </div>

              {activeAccount?.last_error_message ? (
                <div className="mt-4 rounded-2xl border border-[rgba(170,63,63,0.16)] bg-[rgba(170,63,63,0.08)] px-4 py-3 text-sm text-danger">
                  {activeAccount.last_error_code ? `${activeAccount.last_error_code}: ` : ""}
                  {activeAccount.last_error_message}
                </div>
              ) : null}
            </div>

            <div className="rounded-[28px] border border-line bg-surface-strong px-5 py-5">
              <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                  <p className="text-xs uppercase tracking-[0.18em] text-[rgba(23,32,51,0.5)]">Selected Thread</p>
                  <h3 className="mt-2 text-lg font-semibold">
                    {selectedThread?.subject ?? "Choose a thread to inspect"}
                  </h3>
                </div>
                <span className={["rounded-full border px-3 py-1 text-xs font-semibold uppercase tracking-[0.16em]", badgeClasses(selectedThread?.classification ?? null)].join(" ")}>
                  {formatClassification(selectedThread?.classification ?? null)}
                </span>
              </div>

              <p className="mt-4 text-sm leading-7 text-[rgba(23,32,51,0.68)]">
                {selectedThread?.classification_reason ?? "Classification rationale will appear after a sync runs."}
              </p>

              <div className="mt-5 space-y-3">
                {threads.map((thread) => (
                  <button
                    key={thread.id}
                    type="button"
                    onClick={() => loadThread(thread.id)}
                    disabled={isThreadPending}
                    className={[
                      "flex w-full items-center justify-between rounded-2xl border px-4 py-3 text-left",
                      selectedThreadId === thread.id
                        ? "border-[rgba(168,79,45,0.34)] bg-[rgba(168,79,45,0.08)]"
                        : "border-line bg-white/60",
                    ].join(" ")}
                  >
                    <div className="min-w-0">
                      <p className="truncate text-sm font-semibold text-foreground">
                        {thread.subject ?? "Untitled thread"}
                      </p>
                      <p className="mt-1 truncate text-xs text-[rgba(23,32,51,0.56)]">
                        {thread.snippet ?? "No snippet available."}
                      </p>
                    </div>
                    <span className="ml-3 text-xs font-medium text-[rgba(23,32,51,0.46)]">
                      {thread.latest_message_at ? new Date(thread.latest_message_at).toLocaleDateString() : "No date"}
                    </span>
                  </button>
                ))}
              </div>
            </div>
          </div>

          <div className="rounded-[28px] border border-line bg-[linear-gradient(180deg,rgba(255,255,255,0.72),rgba(255,248,240,0.92))] px-5 py-5">
            <div className="flex h-full flex-col gap-5">
              <div>
                <p className="text-xs uppercase tracking-[0.18em] text-[rgba(23,32,51,0.5)]">Draft Review</p>
                <h3 className="mt-2 text-lg font-semibold">Suggested response workspace</h3>
              </div>

              {selectedThread ? (
                <>
                  <div className="rounded-2xl border border-line bg-white/65 px-4 py-4">
                    <div className="flex items-start justify-between gap-4">
                      <div>
                        <p className="text-xs uppercase tracking-[0.16em] text-[rgba(23,32,51,0.48)]">Latest inbound message</p>
                        <p className="mt-2 text-sm font-semibold text-foreground">
                          {latestMessage?.sender_name ?? latestMessage?.sender_email ?? "Unknown sender"}
                        </p>
                      </div>
                      <span className="rounded-full border border-line bg-white px-2.5 py-1 text-xs text-[rgba(23,32,51,0.58)]">
                        {latestMessage?.gmail_received_at
                          ? new Date(latestMessage.gmail_received_at).toLocaleString()
                          : "No timestamp"}
                      </span>
                    </div>
                    <p className="mt-3 whitespace-pre-wrap text-sm leading-7 text-[rgba(23,32,51,0.72)]">
                      {latestMessage?.body_text ?? latestMessage?.snippet ?? "No message body available."}
                    </p>
                  </div>

                  <div className="space-y-3">
                    <label className="block text-xs font-semibold uppercase tracking-[0.16em] text-[rgba(23,32,51,0.48)]">
                      Draft Subject
                    </label>
                    <input
                      value={draftSubject}
                      onChange={(event) => setDraftSubject(event.target.value)}
                      className="w-full rounded-2xl border border-line bg-white/70 px-4 py-3 text-sm outline-none focus:border-[rgba(168,79,45,0.42)] focus:ring-2 focus:ring-[rgba(168,79,45,0.12)]"
                    />
                  </div>

                  <div className="flex-1 space-y-3">
                    <label className="block text-xs font-semibold uppercase tracking-[0.16em] text-[rgba(23,32,51,0.48)]">
                      Draft Body
                    </label>
                    <textarea
                      value={draftBody}
                      onChange={(event) => setDraftBody(event.target.value)}
                      rows={14}
                      className="min-h-[280px] w-full rounded-[26px] border border-line bg-white/70 px-4 py-4 text-sm leading-7 outline-none focus:border-[rgba(168,79,45,0.42)] focus:ring-2 focus:ring-[rgba(168,79,45,0.12)]"
                    />
                  </div>

                  <div className="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-line bg-white/55 px-4 py-3">
                    <div className="text-sm text-[rgba(23,32,51,0.62)]">
                      <p>
                        Source: <span className="font-semibold text-foreground">{activeDraft?.generation_source ?? "none"}</span>
                      </p>
                      <p>
                        Status: <span className="font-semibold text-foreground">{activeDraft?.status ?? "no draft"}</span>
                      </p>
                    </div>

                    <div className="flex flex-wrap gap-3">
                      <button
                        type="button"
                        onClick={() => saveDraft("edited")}
                        disabled={!activeDraft || isDraftPending}
                        className="rounded-full border border-line bg-white px-4 py-2 text-sm font-semibold text-foreground disabled:cursor-not-allowed disabled:opacity-50"
                      >
                        {isDraftPending ? "Saving..." : "Save Edits"}
                      </button>
                      <button
                        type="button"
                        onClick={() => saveDraft("approved")}
                        disabled={!activeDraft || isDraftPending}
                        className="rounded-full bg-foreground px-4 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50"
                      >
                        Mark Approved
                      </button>
                    </div>
                  </div>
                </>
              ) : (
                <div className="flex min-h-[420px] items-center justify-center rounded-[28px] border border-dashed border-line bg-white/50 px-6 text-center text-sm leading-7 text-[rgba(23,32,51,0.62)]">
                  No synced thread is selected yet. Connect Gmail, run a sync, then choose a thread to inspect the generated draft.
                </div>
              )}
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}