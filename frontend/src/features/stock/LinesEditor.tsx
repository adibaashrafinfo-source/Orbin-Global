import { X } from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { IconButton, Input, Select, Switch } from '@/design/components';
import type { DocLine } from './types';

export interface Column {
  key: string;
  label: string;
  kind: 'qty' | 'money' | 'text' | 'switch' | 'select' | 'readonly';
  /** For 'select'. */
  options?: { value: string; label: string }[];
  /** For 'readonly': what to show. */
  render?: (line: DocLine, index: number) => ReactNode;
  min?: number;
  width?: string;
  /** Per-row error message for this cell, from the server's field errors. */
  error?: (index: number) => string | undefined;
}

interface Props {
  lines: DocLine[];
  columns: Column[];
  onChange: (lines: DocLine[]) => void;
  disabled?: boolean;
  caption: string;
  /** Anything extra under a line's SKU (last cost hints, stock). */
  note?: (line: DocLine) => ReactNode;
  empty?: string;
}

/** The spreadsheet-like grid every document shares: SKU, editable cells per column, remove. Values are strings, as the API sends them. */
export function LinesEditor({ lines, columns, onChange, disabled, caption, note, empty }: Props) {
  const { t } = useTranslation();
  const set = (i: number, key: string, value: string | boolean) => onChange(lines.map((l, n) => (n === i ? { ...l, [key]: value } : l)));
  if (lines.length === 0) return <p className="rounded-card border border-dashed border-navy-200 px-4 py-6 text-center text-sm text-ink-muted">{empty ?? t('stock.noLines')}</p>;
  return (
    <div className="overflow-x-auto rounded-card border border-navy-100 bg-surface">
      <table className="w-full min-w-[640px] text-sm">
        <caption className="sr-only">{caption}</caption>
        <thead className="bg-navy-900 text-xs uppercase tracking-wide text-surface">
          <tr>
            <th scope="col" className="px-3 py-2 text-start">{t('products.fields.sku')}</th>
            {columns.map((c) => <th key={c.key} scope="col" className="px-3 py-2 text-start" style={c.width ? { width: c.width } : undefined}>{c.label}</th>)}
            {!disabled && <th scope="col" className="w-10 px-2 py-2"><span className="sr-only">{t('common.actions')}</span></th>}
          </tr>
        </thead>
        <tbody>
          {lines.map((l, i) => (
            <tr key={`${l.variant_id}-${i}`} className="border-t border-navy-100 align-top odd:bg-navy-50/40">
              <th scope="row" className="px-3 py-2 text-start font-normal">
                <code className="text-xs font-semibold">{l.sku}</code>
                {l.variant_name && <div className="max-w-[16rem] truncate text-xs text-ink-muted">{l.variant_name}</div>}
                {note?.(l)}
              </th>
              {columns.map((c) => (
                <td key={c.key} className="px-3 py-1.5">
                  {c.kind === 'readonly' ? <span className="tabular whitespace-nowrap">{c.render?.(l, i)}</span>
                    : c.kind === 'select' ? <div className="min-w-[9rem]"><Select aria-label={`${l.sku} ${c.label}`} disabled={disabled} value={String(l[c.key] ?? '') || '__none__'} onChange={(v) => set(i, c.key, v === '__none__' ? '' : v)} options={[{ value: '__none__', label: '—' }, ...(c.options ?? [])]} /></div>
                    : c.kind === 'switch' ? <Switch label={<span className="sr-only">{c.label}</span>} checked={Boolean(l[c.key])} disabled={disabled} onChange={(v) => set(i, c.key, v)} />
                    : <Input aria-label={`${l.sku} ${c.label}`} className="min-w-[6rem]" inputMode={c.kind === 'text' ? undefined : 'decimal'} disabled={disabled} value={String(l[c.key] ?? '')} onChange={(e) => set(i, c.key, e.target.value)} error={c.error?.(i)} />}
                </td>
              ))}
              {!disabled && <td className="px-2 py-1.5"><IconButton label={t('common.remove')} onClick={() => onChange(lines.filter((_, n) => n !== i))}><X className="h-4 w-4" aria-hidden="true" /></IconButton></td>}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
