import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Button, Input, Switch, toast } from '@/design/components';
import { ApiError } from '@/lib/api';
import { KEYS, variantApi } from '../api';
import type { Variant } from '../types';
import { apiMessage } from '../useApiError';
import { str } from '../util';

export interface GridCol {
  key: string;
  label: string;
  kind: 'money' | 'number' | 'switch';
  /** Money/number columns that may be left empty (saved as null). */
  nullable?: boolean;
}

type Cell = string | boolean;
type Edits = Record<number, Record<string, Cell>>;

const read = (v: Variant, c: GridCol): Cell => {
  const raw = (v as unknown as Record<string, unknown>)[c.key];
  if (c.kind === 'switch') return Boolean(raw);
  return str(raw);
};

function toBody(row: Record<string, Cell>, cols: GridCol[]): Record<string, unknown> {
  const body: Record<string, unknown> = {};
  for (const c of cols) {
    if (!(c.key in row)) continue;
    const v = row[c.key];
    if (c.kind === 'switch') body[c.key] = v;
    else if (c.kind === 'money') body[c.key] = String(v).trim() === '' ? null : String(v).trim();
    else body[c.key] = String(v).trim() === '' ? (c.nullable ? null : 0) : Number(v);
  }
  return body;
}

interface Props {
  productId: number;
  variants: Variant[];
  cols: GridCol[];
  editable: boolean;
  /** Extra read-only columns (e.g. margin, stock on hand). */
  extra?: { label: string; render: (v: Variant, values: Record<string, Cell>) => ReactNode }[];
  caption: string;
}

/** Spreadsheet-style editor shared by the Pricing and Stock-control steps. Only changed rows are sent; failures are shown on their row. */
export function VariantGrid({ productId, variants, cols, editable, extra = [], caption }: Props) {
  const { t } = useTranslation();
  const client = useQueryClient();
  const [edits, setEdits] = useState<Edits>({});
  const [errors, setErrors] = useState<Record<number, string>>({});
  const value = (v: Variant, c: GridCol): Cell => edits[v.id]?.[c.key] ?? read(v, c);
  const setCell = (v: Variant, c: GridCol, cell: Cell) => setEdits((e) => {
    const same = read(v, c) === cell;
    const row = { ...(e[v.id] ?? {}) };
    if (same) delete row[c.key]; else row[c.key] = cell;
    const next = { ...e, [v.id]: row };
    if (Object.keys(row).length === 0) delete next[v.id];
    return next;
  });
  const dirtyIds = Object.keys(edits).map(Number);

  const save = useMutation({
    mutationFn: async () => {
      const failed: Record<number, string> = {};
      let saved = 0;
      for (const id of dirtyIds) {
        try {
          await variantApi.update(id, toBody(edits[id] as Record<string, Cell>, cols));
          saved++;
          setEdits((e) => { const n = { ...e }; delete n[id]; return n; });
        } catch (e) {
          failed[id] = e instanceof ApiError ? apiMessage(t, e) : t('errors.unknown');
        }
      }
      setErrors(failed);
      return { saved, failed: Object.keys(failed).length };
    },
    onSuccess: (r) => {
      void client.invalidateQueries({ queryKey: KEYS.product(productId) });
      if (r.saved > 0) toast.success(t('products.grid.saved', { count: r.saved }));
      if (r.failed > 0) toast.error(t('products.grid.failed', { count: r.failed }));
    },
  });

  return (
    <div className="grid gap-3">
      <div className="overflow-x-auto rounded-card border border-navy-100 bg-surface">
        <table className="w-full min-w-[720px] text-sm">
          <caption className="sr-only">{caption}</caption>
          <thead className="bg-navy-900 text-start text-xs uppercase tracking-wide text-surface">
            <tr>
              <th scope="col" className="px-3 py-2 text-start">{t('products.fields.sku')}</th>
              {cols.map((c) => <th key={c.key} scope="col" className="px-3 py-2 text-start">{c.label}</th>)}
              {extra.map((x) => <th key={x.label} scope="col" className="px-3 py-2 text-start">{x.label}</th>)}
            </tr>
          </thead>
          <tbody>
            {variants.map((v) => (
              <tr key={v.id} className="border-t border-navy-100 align-top odd:bg-navy-50/40">
                <th scope="row" className="px-3 py-2 text-start font-normal"><code className="text-xs font-semibold">{v.sku}</code>{v.variant_name && <div className="max-w-[16rem] truncate text-xs text-ink-muted">{v.variant_name}</div>}{errors[v.id] && <p role="alert" className="mt-1 text-xs text-danger">{errors[v.id]}</p>}</th>
                {cols.map((c) => (
                  <td key={c.key} className="px-3 py-1.5">
                    {c.kind === 'switch'
                      ? <Switch label={<span className="sr-only">{c.label}</span>} checked={Boolean(value(v, c))} disabled={!editable} onChange={(x) => setCell(v, c, x)} />
                      : <Input aria-label={`${v.sku} ${c.label}`} className="min-w-[6.5rem]" type={c.kind === 'number' ? 'number' : 'text'} inputMode="decimal" disabled={!editable} value={String(value(v, c))} onChange={(e) => setCell(v, c, e.target.value)} />}
                  </td>
                ))}
                {extra.map((x) => <td key={x.label} className="px-3 py-2 tabular">{x.render(v, { ...Object.fromEntries(cols.map((c) => [c.key, value(v, c)])) })}</td>)}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {editable && (
        <div className="flex items-center justify-end gap-3">
          {dirtyIds.length > 0 && <span role="status" className="text-xs text-ink-muted">{t('products.grid.changed', { count: dirtyIds.length })}</span>}
          <Button variant="secondary" disabled={dirtyIds.length === 0 || save.isPending} onClick={() => { setEdits({}); setErrors({}); }}>{t('products.grid.discard')}</Button>
          <Button disabled={dirtyIds.length === 0} loading={save.isPending} onClick={() => save.mutate()}>{t('common.save')}</Button>
        </div>
      )}
    </div>
  );
}
