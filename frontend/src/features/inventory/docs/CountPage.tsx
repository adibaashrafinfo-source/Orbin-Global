import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useParams } from 'react-router-dom';
import { Button, Card, CardBody, ErrorState, PageHeader, PageSkeleton, Select, Switch, Textarea, toast } from '@/design/components';
import { useSession } from '@/features/auth/useSession';
import { useMasterOptions } from '../../catalog/hooks';
import { apiMessage, serverFieldError, useApiError } from '../../catalog/useApiError';
import { useDerivedState } from '../../catalog/util';
import { countApi, STOCK_KEYS } from '../../stock/api';
import { fmtQty } from '../../stock/format';
import { useWarehouses } from '../../stock/hooks';
import { StatusBadge } from '../../stock/StatusBadge';
import type { Doc } from '../../stock/types';
import { useDoc, useDocAct, useDocSave } from '../../stock/useDoc';
import { VariantPicker } from '../../stock/VariantPicker';
import { InventoryTabs } from '../InventoryTabs';

const BASE = '/inventory/counts';
const SCOPES = ['full', 'category'] as const;

/** FR-406: draft → counting → review → posted. A blind count hides what the system expects until counting is over. */
export function CountPage() {
  const { t } = useTranslation();
  const { id } = useParams();
  const isNew = !id || id === 'new';
  const doc = useDoc('stock-counts', id);
  if (!isNew && doc.isLoading) return <PageSkeleton />;
  if (!isNew && (doc.error || !doc.data)) return <ErrorState title={t('errors.NOT_FOUND')} onRetry={() => void doc.refetch()} />;
  return <CountForm key={doc.data?.id ?? 'new'} doc={isNew ? null : (doc.data as Doc)} />;
}

function CountForm({ doc }: { doc: Doc | null }) {
  const { t } = useTranslation();
  const { can } = useSession();
  const client = useQueryClient();
  const onError = useApiError();
  const warehouses = useWarehouses();
  const categories = useMasterOptions('categories', 'name_en', 'name_bn');
  const save = useDocSave('stock-counts', BASE);
  const act = useDocAct('stock-counts');
  const [warehouseId, setWarehouseId] = useState('');
  const [scope, setScope] = useState<(typeof SCOPES)[number]>('full');
  const [scopeRef, setScopeRef] = useState('');
  const [blind, setBlind] = useState(false);
  const [note, setNote] = useState('');
  const [counted, setCounted] = useDerivedState<Record<number, string>>(doc?.updated_at, () => Object.fromEntries((doc?.items ?? []).map((i) => [Number(i.variant_id), i.counted_qty === null || i.counted_qty === undefined ? '' : String(i.counted_qty)])));
  const status = doc?.status ?? 'draft';
  const canCount = can(['inventory.count']);
  const items = doc?.items ?? [];
  const entering = status === 'counting';
  const showSystem = status === 'review' || status === 'posted' || (status === 'counting' && !doc?.is_blind);

  const addItem = useMutation({ mutationFn: (variantId: number) => countApi.addItem(doc?.id ?? 0, variantId), onSuccess: (d) => client.setQueryData(STOCK_KEYS.doc('stock-counts', d.id), d), onError });
  const enter = useMutation({
    mutationFn: () => countApi.enter(doc?.id ?? 0, items.map((i) => ({ variant_id: Number(i.variant_id), counted_qty: (counted[Number(i.variant_id)] ?? '') === '' ? null : String(counted[Number(i.variant_id)]) }))),
    onSuccess: (d) => { client.setQueryData(STOCK_KEYS.doc('stock-counts', d.id), d); toast.success(t('stock.saved')); },
    onError,
  });
  const variance = (i: (typeof items)[number]) => (i.counted_qty === null || i.counted_qty === undefined ? null : Number(counted[Number(i.variant_id)] ?? i.counted_qty) - Number(i.system_qty));

  return (
    <>
      <PageHeader title={doc ? String(doc.count_no) : t('inventory.newCount')} description={doc ? <StatusBadge status={status} /> : t('inventory.countsSubtitle')}
        crumbs={[{ label: t('nav.inventory'), to: '/inventory' }, { label: t('inventory.tabs.counts'), to: BASE }, { label: doc ? String(doc.count_no) : t('stock.new') }]} />
      <InventoryTabs />
      <div className="grid gap-5">
        {!doc ? (
          <Card><CardBody className="grid gap-4 md:grid-cols-2">
            <Select label={t('inventory.warehouse')} required value={warehouseId || undefined} onChange={setWarehouseId} error={serverFieldError(t, save.error, 'warehouse_id')} options={(warehouses.data ?? []).map((w) => ({ value: String(w.id), label: w.name }))} />
            <Select label={t('inventory.scope')} value={scope} onChange={(v) => setScope(v as (typeof SCOPES)[number])} options={SCOPES.map((s) => ({ value: s, label: t(`inventory.scopes.${s}`) }))} />
            {scope === 'category' && <Select label={t('products.fields.category')} required value={scopeRef || undefined} onChange={setScopeRef} error={serverFieldError(t, save.error, 'scope_ref')} options={categories} />}
            <Switch label={t('inventory.blindCount')} description={t('inventory.blindHint')} checked={blind} onChange={setBlind} />
            <div className="md:col-span-2"><Textarea label={t('stock.note')} rows={2} value={note} onChange={(e) => setNote(e.target.value)} /></div>
            <div className="flex justify-end md:col-span-2"><Button disabled={!warehouseId || (scope === 'category' && !scopeRef)} loading={save.isPending} onClick={() => save.mutate({ id: null, body: { warehouse_id: Number(warehouseId), scope, scope_ref: scope === 'category' ? Number(scopeRef) : null, is_blind: blind, note: note.trim() || null } })}>{t('stock.createDraft')}</Button></div>
          </CardBody></Card>
        ) : (
          <>
            <Card><CardBody className="flex flex-wrap items-center gap-x-6 gap-y-1 text-sm">
              <span>{t('inventory.warehouse')}: <strong>{doc.warehouse?.name}</strong></span><span>{t('inventory.scope')}: <strong>{t(`inventory.scopes.${String(doc.scope)}`)}</strong></span>{doc.is_blind ? <span>{t('inventory.blind')}</span> : null}
            </CardBody></Card>
            {status === 'draft' && <p className="text-sm text-ink-muted">{t('inventory.startHint')}</p>}
            {entering && canCount && <Card><CardBody><VariantPicker label={t('inventory.addFound')} exclude={items.map((i) => Number(i.variant_id))} onPick={(v) => addItem.mutate(v.id)} /></CardBody></Card>}
            {items.length > 0 && (
              <div className="overflow-x-auto rounded-card border border-navy-100 bg-surface">
                <table className="w-full min-w-[560px] text-sm">
                  <caption className="sr-only">{t('inventory.tabs.counts')}</caption>
                  <thead className="bg-navy-900 text-xs uppercase tracking-wide text-surface"><tr>
                    <th scope="col" className="px-3 py-2 text-start">{t('products.fields.sku')}</th>
                    {showSystem && <th scope="col" className="px-3 py-2 text-end">{t('inventory.systemQty')}</th>}
                    <th scope="col" className="px-3 py-2 text-end">{t('inventory.countedQty')}</th>
                    {showSystem && <th scope="col" className="px-3 py-2 text-end">{t('inventory.variance')}</th>}
                  </tr></thead>
                  <tbody>{items.map((i) => { const v = variance(i); return (
                    <tr key={Number(i.id)} className="border-t border-navy-100 odd:bg-navy-50/40">
                      <th scope="row" className="px-3 py-2 text-start font-normal"><code className="text-xs font-semibold">{i.sku}</code><div className="text-xs text-ink-muted">{i.variant_name}</div></th>
                      {showSystem && <td className="tabular px-3 py-2 text-end">{fmtQty(String(i.system_qty))}</td>}
                      <td className="px-3 py-1.5 text-end">{entering && canCount ? <input aria-label={`${i.sku} ${t('inventory.countedQty')}`} className="h-9 w-28 rounded-input border border-navy-200 px-2 text-end" inputMode="decimal" value={counted[Number(i.variant_id)] ?? ''} onChange={(e) => setCounted({ ...counted, [Number(i.variant_id)]: e.target.value })} /> : <span className="tabular">{i.counted_qty === null ? '—' : fmtQty(String(i.counted_qty))}</span>}</td>
                      {showSystem && <td className={`tabular px-3 py-2 text-end font-semibold ${v === null || v === 0 ? 'text-ink-muted' : v < 0 ? 'text-danger' : 'text-success'}`}>{v === null ? '—' : `${v > 0 ? '+' : ''}${fmtQty(String(v))}`}</td>}
                    </tr>); })}</tbody>
                </table>
              </div>
            )}
            {act.isError ? <p role="alert" className="text-sm text-danger">{apiMessage(t, act.error)}</p> : null}
            {canCount && (
              <div className="flex flex-wrap items-center justify-end gap-2">
                {['draft', 'counting', 'review'].includes(status) && <Button variant="secondary" loading={act.isPending} onClick={() => act.mutate({ id: doc.id, action: 'cancel' })}>{t('stock.cancelDoc')}</Button>}
                {status === 'draft' && <Button loading={act.isPending} onClick={() => act.mutate({ id: doc.id, action: 'start' })}>{t('inventory.startCount')}</Button>}
                {entering && <Button variant="secondary" loading={enter.isPending} onClick={() => enter.mutate()}>{t('inventory.saveCounts')}</Button>}
                {entering && <Button loading={act.isPending} onClick={() => enter.mutateAsync().then(() => act.mutate({ id: doc.id, action: 'review' })).catch(() => undefined)}>{t('inventory.finishCounting')}</Button>}
                {status === 'review' && <><Button variant="secondary" loading={enter.isPending} onClick={() => enter.mutate()}>{t('inventory.saveCounts')}</Button><Button loading={act.isPending} onClick={() => act.mutate({ id: doc.id, action: 'post' })}>{t('inventory.postCorrections')}</Button></>}
              </div>
            )}
          </>
        )}
      </div>
    </>
  );
}
