import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge, Button, Checkbox, Input, Modal, Select, toast } from '@/design/components';
import { ApiError } from '@/lib/api';
import { formatMoney } from '@/lib/money';
import { KEYS, variantApi } from '../api';
import { PRICE_COLUMNS, type PriceColumn } from '../types';
import { useDerivedState } from '../util';
import type { Filters } from './useProductFilters';

type Mode = 'percent' | 'fixed';
type Result = Awaited<ReturnType<typeof variantApi.bulkPrice>>;

/** FR-308 Bulk price change with a dry-run preview before anything is written. Scope = the current list filters. */
export function BulkPriceDialog({ open, onClose, filters }: { open: boolean; onClose: () => void; filters: Filters }) {
  const { t } = useTranslation();
  const client = useQueryClient();
  const [mode, setMode] = useDerivedState<Mode>(open, () => 'percent');
  const [amount, setAmount] = useDerivedState(open, () => '');
  const [columns, setColumns] = useDerivedState<PriceColumn[]>(open, () => ['retail_price']);
  const [preview, setPreview] = useState<Result | null>(null);
  const scope = { category_id: filters.category_id ? Number(filters.category_id) : null, brand_id: filters.brand_id ? Number(filters.brand_id) : null, mirror_type: filters.mirror_type || null };
  const everything = scope.category_id === null && scope.brand_id === null && scope.mirror_type === null;

  const body = (dryRun: boolean) => ({ dry_run: dryRun, mode, amount: amount.trim(), columns, filter: scope });
  const onError = (e: unknown) => {
    if (e instanceof ApiError && e.code === 'VALIDATION') return;
    toast.error(t(`errors.${e instanceof ApiError ? e.code : 'unknown'}`, { defaultValue: t('errors.unknown') }));
  };
  const run = useMutation({ mutationFn: () => variantApi.bulkPrice(body(true)), onSuccess: setPreview, onError });
  const apply = useMutation({
    mutationFn: () => variantApi.bulkPrice(body(false)),
    onSuccess: (r) => {
      void client.invalidateQueries({ queryKey: KEYS.products });
      toast.success(t('products.bulk.applied', { count: r.changed }));
      setPreview(null);
      onClose();
    },
    onError,
  });
  const fieldErr = (k: string) => {
    const e = run.error ?? apply.error;
    return e instanceof ApiError && e.fields[k] ? t('errors.validation') : undefined;
  };
  const toggle = (c: PriceColumn) => { setPreview(null); setColumns(columns.includes(c) ? columns.filter((x) => x !== c) : [...columns, c]); };
  const valid = amount.trim() !== '' && columns.length > 0;

  return (
    <Modal
      open={open}
      onOpenChange={(o) => { if (!o) { setPreview(null); onClose(); } }}
      className="max-w-xl"
      title={t('products.bulk.title')}
      description={everything ? t('products.bulk.scopeAll') : t('products.bulk.scopeFiltered')}
      footer={<>
        <Button variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
        <Button variant="secondary" disabled={!valid} loading={run.isPending} onClick={() => run.mutate()}>{t('products.bulk.preview')}</Button>
        <Button disabled={!valid || !preview || preview.changed === 0} loading={apply.isPending} onClick={() => apply.mutate()}>{t('products.bulk.apply')}</Button>
      </>}
    >
      <div className="grid gap-4">
        <div className="grid grid-cols-2 gap-3">
          <Select label={t('products.bulk.mode')} value={mode} onChange={(v) => { setPreview(null); setMode(v as Mode); }} options={[{ value: 'percent', label: t('products.bulk.percent') }, { value: 'fixed', label: t('products.bulk.fixed') }]} />
          <Input label={t('products.bulk.amount')} hint={t('products.bulk.amountHint')} inputMode="decimal" value={amount} onChange={(e) => { setPreview(null); setAmount(e.target.value); }} error={fieldErr('amount')} />
        </div>
        <fieldset className="grid gap-2">
          <legend className="mb-1 text-sm font-medium text-ink">{t('products.bulk.columns')}</legend>
          {PRICE_COLUMNS.map((c) => <Checkbox key={c} checked={columns.includes(c)} onChange={() => toggle(c)} label={t(`products.fields.${c}`)} />)}
        </fieldset>
        {preview && (
          <div className="rounded-card border border-navy-100 p-3" aria-live="polite">
            <p className="mb-2 text-sm"><Badge tone="navy">{t('products.bulk.willChange', { count: preview.changed })}</Badge> <span className="text-ink-muted">{t('products.bulk.ofVariants', { count: preview.count })}</span></p>
            <ul className="grid gap-1 text-xs">
              {preview.sample.slice(0, 5).map((s) => (
                <li key={s.variant_id} className="flex justify-between gap-2"><code>{s.sku}</code><span className="tabular text-ink-muted">{columns.map((c) => `${formatMoney(s.before[c] ?? '0', { symbol: false })} → ${formatMoney(s.after[c] ?? '0', { symbol: false })}`).join(' · ')}</span></li>
              ))}
            </ul>
          </div>
        )}
      </div>
    </Modal>
  );
}
