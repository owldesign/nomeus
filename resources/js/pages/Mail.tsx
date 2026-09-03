import { useState } from 'react';
import type { MailSummary } from '@/api/types';
import { ApiError } from '@/api/client';
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
      <div className="border-b border-line px-3 py-2">
        <div className="text-fg">{summary.Subject || '(no subject)'}</div>
        <div className="text-dim">
          {addr(summary.From)} → {summary.To.map(addr).join(', ')} · {new Date(summary.Created).toLocaleString()}
          {summary.Attachments ? ` · ${summary.Attachments} attachment${summary.Attachments === 1 ? '' : 's'}` : ''}
        </div>
        <div className="flex gap-3 text-dim">
          <a className="hover:text-gold" href={summary.view_url} target="_blank" rel="noreferrer">open in Mailpit ↗</a>
          {msg && hasHtml && msg.Text && (
            <button type="button" className="hover:text-gold" onClick={() => setMode(showHtml ? 'text' : 'html')}>{showHtml ? 'show text' : 'show html'}</button>
          )}
        </div>
      </div>
      {isLoading && <div className="p-3 text-dim">loading…</div>}
      {msg && showHtml && <iframe title={summary.Subject} src={summary.view_url} className="min-h-0 flex-1 bg-white" sandbox="allow-same-origin" />}
      {msg && !showHtml && (
        <pre className="min-h-0 flex-1 overflow-auto whitespace-pre-wrap p-4 text-fg">{msg.Text || '(empty message)'}</pre>
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
        <h1 className="mb-2 text-[15px] font-semibold">Mail</h1>
        <p className="text-dim">No mailpit instance yet. <code>nomeus services:create mailpit</code> (or the Services page) — SMTP on 1025, this page reads its inbox.</p>
      </div>
    );
  }
  if (status.data && !available) {
    return (
      <div className="max-w-3xl">
        <h1 className="mb-2 text-[15px] font-semibold">Mail</h1>
        <p className="text-gold">{status.data.instance} is stopped. Start it from the Services page or <code>nomeus services:start {status.data.instance}</code>.</p>
      </div>
    );
  }

  return (
    <div className="grid h-[calc(100vh-7rem)] grid-cols-[160px_minmax(280px,380px)_1fr] gap-0 border border-line bg-panel">
      {/* apps = tags */}
      <aside className="border-r border-line p-3">
        <div className="mb-2 flex items-baseline justify-between">
          <span className="text-dim">apps</span>
          {status.data && <a className="text-dim hover:text-gold" href={status.data.ui_url} target="_blank" rel="noreferrer" title="open Mailpit's own UI">mailpit ↗</a>}
        </div>
        <ul className="space-y-0.5">
          <li>
            <button type="button" onClick={() => { setTag(null); setOpen(null); }} className={`w-full rounded-sm px-2 py-1 text-left ${tag === null ? 'bg-bg text-gold' : 'hover:text-gold'}`}>all</button>
          </li>
          {(tags.data ?? []).map((t) => (
            <li key={t}>
              <button type="button" onClick={() => { setTag(t); setOpen(null); }} className={`w-full rounded-sm px-2 py-1 text-left ${tag === t ? 'bg-bg text-gold' : 'hover:text-gold'}`}>{t}</button>
            </li>
          ))}
        </ul>
        {tags.data && tags.data.length === 0 && (
          <p className="mt-3 text-dim">No tags yet — apps using nomeus/client show up here as they send.</p>
        )}
      </aside>

      {/* list */}
      <section className="flex min-h-0 flex-col border-r border-line">
        <div className="flex items-baseline justify-between border-b border-line px-3 py-2">
          <span className="text-dim">{messages.data ? `${messages.data.total} message${messages.data.total === 1 ? '' : 's'}${messages.data.unread ? ` · ${messages.data.unread} unread` : ''}` : '…'}</span>
          {messages.data && messages.data.total > 0 && (
            <button type="button" className="text-dim hover:text-red" disabled={remove.isPending} onClick={() => { if (confirm(`Delete ${tag ? `all mail tagged ${tag}` : 'all mail'}?`)) remove.mutate(tag); }}>
              {remove.isPending ? 'deleting…' : 'delete all'}
            </button>
          )}
        </div>
        {messages.isError && <p className="p-3 text-red">{errorText(messages.error)}</p>}
        <ul className="min-h-0 flex-1 overflow-auto">
          {(messages.data?.messages ?? []).map((m) => (
            <li key={m.ID}>
              <button
                type="button"
                onClick={() => setOpen(m)}
                className={`block w-full border-b border-dashed border-line px-3 py-2 text-left hover:bg-bg ${open?.ID === m.ID ? 'bg-bg' : ''}`}
              >
                <div className="flex items-baseline justify-between gap-2">
                  <span className={`truncate ${m.Read ? 'text-dim' : 'text-fg'}`}>{m.Subject || '(no subject)'}</span>
                  <span className="shrink-0 text-dim">{when(m.Created)}</span>
                </div>
                <div className="truncate text-dim">{addr(m.From)} → {m.To.map((t) => t.Address).join(', ')}</div>
                {!tag && m.Tags.length > 0 && <div className="mt-0.5 text-blue">{m.Tags.join(' ')}</div>}
              </button>
            </li>
          ))}
          {messages.data && messages.data.messages.length === 0 && <li className="p-3 text-dim">empty</li>}
        </ul>
      </section>

      {/* preview */}
      <section className="flex min-h-0 flex-col">
        {open ? <Preview summary={open} /> : <div className="flex flex-1 items-center justify-center text-dim">select a message</div>}
      </section>
    </div>
  );
}
