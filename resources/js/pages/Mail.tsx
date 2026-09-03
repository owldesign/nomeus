import { useState } from 'react';
import type { MailSummary } from '@/api/types';
import { ApiError } from '@/api/client';
import Button, { ConfirmInline } from '@/components/Button';
import Chip from '@/components/Chip';
import EmptyState from '@/components/EmptyState';
import Panel, { LABEL, PageHeader } from '@/components/Panel';
import { useDeleteMail, useMailMessage, useMailMessages, useMailStatus, useMailTags } from '@/hooks/useApi';

const errorText = (e: unknown) => (e instanceof ApiError ? e.message : String(e));

function when(iso: string) {
  const d = new Date(iso);
  const today = new Date().toDateString() === d.toDateString();
  return today ? d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : d.toLocaleDateString();
}

function addr(a: { Name: string; Address: string } | null) {
  return a ? (a.Name ? `${a.Name} <${a.Address}>` : a.Address) : '—';
}

/**
 * HTML messages render through Mailpit's own view (it resolves inline cid: images); text-only
 * messages — Mail::raw, most notifications' plain part — get the Text part as-is, since the
 * .html view is empty for them.
 */
function Preview({ summary }: { summary: MailSummary }) {
  const { data: msg, isLoading } = useMailMessage(summary.ID);
  const [mode, setMode] = useState<'auto' | 'html' | 'text'>('auto');
  const hasHtml = !!msg?.HTML?.trim();
  const showHtml = mode === 'html' || (mode === 'auto' && hasHtml);

  return (
    <>
      <div className="border-b border-line/55 px-4 py-3">
        <div className="t-sans text-base font-semibold text-text">{summary.Subject || '(no subject)'}</div>
        <div className="text-xs text-dim">
          {addr(summary.From)} → {summary.To.map(addr).join(', ')} · {new Date(summary.Created).toLocaleString()}
          {summary.Attachments ? ` · ${summary.Attachments} attachment${summary.Attachments === 1 ? '' : 's'}` : ''}
        </div>
        <div className="mt-1 flex gap-1 text-xs">
          <a className="px-2 py-[3px] text-mid hover:text-lantern" href={summary.view_url} target="_blank" rel="noreferrer">open in Mailpit ↗</a>
          {msg && hasHtml && msg.Text && <Button onClick={() => setMode(showHtml ? 'text' : 'html')}>{showHtml ? 'show text' : 'show html'}</Button>}
        </div>
      </div>
      {isLoading && <div className="p-3 text-dim">loading…</div>}
      {msg && showHtml && <iframe title={summary.Subject} src={summary.view_url} className="min-h-0 flex-1 bg-white" sandbox="allow-same-origin" />}
      {msg && !showHtml && (
        <pre className="m-0 min-h-0 flex-1 overflow-auto whitespace-pre-wrap bg-inset p-4 text-xs leading-[1.7] text-mid">{msg.Text || '(empty message)'}</pre>
      )}
    </>
  );
}

export default function Mail() {
  const status = useMailStatus();
  const available = status.data?.available ?? false;
  const [tag, setTag] = useState<string | null>(null);
  const [open, setOpen] = useState<MailSummary | null>(null);
  const tags = useMailTags(available);
  const messages = useMailMessages(tag, available);
  const remove = useDeleteMail();

  if (status.data && !status.data.instance) {
    return (
      <div className="max-w-3xl">
        <PageHeader title="Mail" />
        <Panel><EmptyState title="No mailpit instance yet" line="SMTP on 1025; this page reads its inbox, one app per tag." command="nomeus mail --create" /></Panel>
      </div>
    );
  }
  if (status.data && !available) {
    return (
      <div className="max-w-3xl">
        <PageHeader title="Mail" />
        <Panel><EmptyState title={`${status.data.instance} is stopped`} line="Start it from the Services page, or:" command={`nomeus services:start ${status.data.instance}`} /></Panel>
      </div>
    );
  }

  return (
    <div className="flex h-[calc(100vh-7rem)] flex-col">
      <PageHeader title="Mail" summary={messages.data ? `${messages.data.total} message${messages.data.total === 1 ? '' : 's'}${messages.data.unread ? ` · ${messages.data.unread} unread` : ''}` : ''}
        actions={status.data && <a className="text-xs text-dim hover:text-lantern" href={status.data.ui_url} target="_blank" rel="noreferrer" title="open Mailpit's own UI">mailpit ↗</a>} />
      <div className="grid min-h-0 flex-1 grid-cols-[170px_minmax(280px,380px)_1fr] overflow-hidden rounded-lg border border-line bg-panel shadow-panel">
        {/* apps = tags */}
        <aside className="border-r border-line/55 p-3">
          <div className={`${LABEL} mb-2`}>apps</div>
          <ul className="space-y-0.5">
            <li>
              <button type="button" onClick={() => { setTag(null); setOpen(null); }} className={`w-full rounded-sm px-2 py-1 text-left ${tag === null ? 'bg-inset text-lantern' : 'text-mid hover:bg-raised hover:text-text'}`}>all</button>
            </li>
            {(tags.data ?? []).map((t) => (
              <li key={t}>
                <button type="button" onClick={() => { setTag(t); setOpen(null); }} className={`w-full rounded-sm px-2 py-1 text-left ${tag === t ? 'bg-inset text-lantern' : 'text-mid hover:bg-raised hover:text-text'}`}>{t}</button>
              </li>
            ))}
          </ul>
          {tags.data && tags.data.length === 0 && <p className="mt-3 text-xs text-faint">No tags yet — apps using nomeus/client show up here as they send.</p>}
        </aside>

        {/* list */}
        <section className="flex min-h-0 flex-col border-r border-line/55">
          <div className="flex items-center justify-between border-b border-line/55 px-3 py-1.5">
            <span className="text-xs text-dim">{tag ?? 'all apps'}</span>
            {messages.data && messages.data.total > 0 && (
              <ConfirmInline trigger="delete all" question={`delete ${tag ? `all mail tagged ${tag}` : 'all mail'}?`} action="delete" disabled={remove.isPending} onConfirm={() => remove.mutate(tag)} />
            )}
          </div>
          {messages.isError && <p className="p-3 text-fail">{errorText(messages.error)}</p>}
          <ul className="min-h-0 flex-1 overflow-auto">
            {(messages.data?.messages ?? []).map((m) => (
              <li key={m.ID}>
                <button
                  type="button"
                  onClick={() => setOpen(m)}
                  className={`block w-full border-b border-line/55 px-3 py-2 text-left hover:bg-raised/50 ${open?.ID === m.ID ? 'bg-inset' : ''}`}
                >
                  <div className="flex items-baseline justify-between gap-2">
                    <span className={`inline-flex min-w-0 items-center gap-2 ${m.Read ? 'text-dim' : 'text-text'}`}>
                      {!m.Read && <span className="inline-block h-[5px] w-[5px] shrink-0 rounded-full bg-lantern" aria-label="unread" />}
                      <span className="truncate">{m.Subject || '(no subject)'}</span>
                    </span>
                    <span className="shrink-0 text-2xs text-faint">{when(m.Created)}</span>
                  </div>
                  <div className="truncate text-xs text-dim">{addr(m.From)} → {m.To.map((t) => t.Address).join(', ')}</div>
                  {!tag && m.Tags.length > 0 && <div className="mt-1 flex gap-1">{m.Tags.map((t) => <Chip key={t} tint="info">{t}</Chip>)}</div>}
                </button>
              </li>
            ))}
            {messages.data && messages.data.messages.length === 0 && <li><EmptyState title="Nothing here" line={tag ? `no mail tagged ${tag} yet` : 'send something from an app'} /></li>}
          </ul>
        </section>

        {/* preview */}
        <section className="flex min-h-0 flex-col">
          {open ? <Preview summary={open} /> : <div className="flex flex-1 items-center justify-center text-faint">select a message</div>}
        </section>
      </div>
    </div>
  );
}
