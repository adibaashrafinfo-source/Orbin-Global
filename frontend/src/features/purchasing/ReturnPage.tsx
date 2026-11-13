import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useParams } from 'react-router-dom';
import { Button, Card, CardBody, ErrorState, Input, PageHeader, PageSkeleton, Select, Textarea } from '@/design/components';
import { useSession } from '@/features/auth/useSession';
import { formatMoney } from '@/lib/money';
import { apiMessage, serverFieldError } from '../catalog/useApiError';
import { str, useDerivedState } from '../catalog/util';
import { docApi } from '../stock/api';
import { lineTotalPaisa, paisaToMoney, todayDhaka } from '../stock/format';
import { useSuppliers, useWarehouses } from '../stock/hooks';
import { LinesEditor, type Column } from '../stock/LinesEditor';
import { StatusBadge } from '../stock/StatusBadge';
import type { Doc, DocLine } from '../stock/types';
import { toLines, useDoc, useDocAct, useDocSave } from '../stock/useDoc';
import { VariantPicker } from '../stock/VariantPicker';
import { PurchasingTabs } from './PurchasingTabs';

const BASE = '/purchases/returns';
const NONE = '__none__';

/** FR-504: send goods back. Stock leaves at the average cost and what we owe the supplier goes down by the returned value. */
export function ReturnPage() {
  const { t } = useTranslation();
  const { id } = useParams();
  const isNew = !id || id === 'new';
  const doc = useDoc('purchase-returns', id);
  if (!isNew && doc.isLoading) return <PageSkeleton />;
  if (!isNew && (doc.error || !doc.data)) return <ErrorState title={t('errors.NOT_FOUND')} onRetry={() => void doc.refetch()} />;
  return <ReturnForm key={doc.data?.id ?? 'new'} doc={isNew ? null : (doc.data as Doc)} />;
}

function ReturnForm({ doc }: { doc: Doc | null }) {
  const { t } = useTranslation();
  const { can } = useSession();
  const suppliers = useSuppliers();
  const warehouses = useWarehouses();
  const save = useDocSave('purchase-returns', BASE);
  const act = useDocAct('purchase-returns', 'purchasing.returnPosted');
  const [supplierId, setSupplierId] = useDerivedState(doc?.id, () => String(doc?.supplier?.id ?? ''));
  const [warehouseId, setWarehouseId] = useDerivedState(doc?.id, () => String(doc?.warehouse?.id ?? ''));
  const [grnId, setGrnId] = useDerivedState(doc?.id, () => str(doc?.grn_id, NONE));
  const [returnDate, setReturnDate] = useDerivedState(doc?.id, () => str(doc?.return_date, todayDhaka()));
  const [reason, setReason] = useDerivedState(doc?.id, () => str(doc?.reason, ''));
  const [lines, setLines] = useDerivedState<DocLine[]>(doc?.updated_at, () => toLines(doc ?? undefined));
  const [, setTouched] = useState(false);
  const status = doc?.status ?? 'draft';
  const editable = !doc || status === 'draft';
  const receipts = useQuery({ queryKey: ['return-receipts', supplierId], queryFn: () => docApi('goods-receipts').list({ per_page: 50, 'filter[supplier_id]': supplierId, 'filter[status]': 'posted' }), enabled: supplierId !== '' && !doc });
  const totals = lines.map((l) => lineTotalPaisa(String(l.qty ?? ''), String(l.unit_cost ?? '')) ?? 0n);
  const total = totals.reduce((a, b) => a + b, 0n);
  const columns: Column[] = [
    { key: 'qty', label: t('purchasing.qtyReturned'), kind: 'qty', width: '7rem', error: (i) => serverFieldError(t, save.error, `items.${i}.qty`) },
    { key: 'unit_cost', label: t('purchasing.unitCost'), kind: 'money', width: '8rem' },
    { key: 'total', label: t('purchasing.lineTotal'), kind: 'readonly', render: (_l, i) => formatMoney(paisaToMoney(totals[i] ?? 0n)) },
  ];
  const valid = supplierId !== '' && warehouseId !== '' && lines.length > 0 && lines.every((l) => Number(l.qty) > 0 && String(l.unit_cost ?? '') !== '');
  const body = () => ({
    ...(doc ? {} : { supplier_id: Number(supplierId), warehouse_id: Number(warehouseId), grn_id: grnId === NONE ? null : Number(grnId) }),
    return_date: returnDate, reason: reason.trim() || null, items: lines.map((l) => ({ variant_id: l.variant_id, qty: String(l.qty), unit_cost: String(l.unit_cost) })),
  });
  return (
    <>
      <PageHeader title={doc ? String(doc.return_no) : t('purchasing.newReturn')} description={doc ? <StatusBadge status={status} /> : t('purchasing.returnsSubtitle')}
        crumbs={[{ label: t('nav.purchases') }, { label: t('purchasing.tabs.returns'), to: BASE }, { label: doc ? String(doc.return_no) : t('stock.new') }]} />
      <PurchasingTabs />
      <div className="grid gap-5">
        <Card><CardBody className="grid gap-4 md:grid-cols-3">
          <Select label={t('purchasing.supplier')} required disabled={!!doc} value={supplierId || undefined} onChange={(v) => { setSupplierId(v); setGrnId(NONE); setTouched(true); }} error={serverFieldError(t, save.error, 'supplier_id')} options={(suppliers.data ?? []).map((s) => ({ value: String(s.id), label: s.name }))} />
          <Select label={t('inventory.warehouse')} required disabled={!!doc} value={warehouseId || undefined} onChange={setWarehouseId} error={serverFieldError(t, save.error, 'warehouse_id')} options={(warehouses.data ?? []).map((w) => ({ value: String(w.id), label: w.name }))} />
          <Select label={t('purchasing.againstReceipt')} disabled={!!doc} value={grnId} onChange={setGrnId} error={serverFieldError(t, save.error, 'grn_id')} options={[{ value: NONE, label: t('common.none') }, ...(receipts.data?.data ?? []).map((r) => ({ value: String(r.id), label: `${String(r.grn_no)} · ${String(r.received_date)}` }))]} />
          <Input label={t('purchasing.returnDate')} type="date" required disabled={!editable} value={returnDate} onChange={(e) => setReturnDate(e.target.value)} error={serverFieldError(t, save.error, 'return_date')} />
          <div className="md:col-span-2"><Textarea label={t('purchasing.returnReason')} rows={2} disabled={!editable} value={reason} onChange={(e) => setReason(e.target.value)} /></div>
        </CardBody></Card>
        {editable && <Card><CardBody><VariantPicker exclude={lines.map((l) => l.variant_id)} onPick={(v) => setLines([...lines, { variant_id: v.id, sku: v.sku, variant_name: v.variant_name, qty: '', unit_cost: v.last_cost && Number(v.last_cost) > 0 ? v.last_cost : '' }])} /></CardBody></Card>}
        <LinesEditor lines={lines} columns={columns} onChange={setLines} disabled={!editable} caption={t('purchasing.tabs.returns')} />
        <p className="ms-auto text-sm">{t('purchasing.returnTotal')}: <strong className="tabular">{formatMoney(paisaToMoney(total))}</strong></p>
        {act.isError ? <p role="alert" className="text-sm text-danger">{apiMessage(t, act.error)}</p> : null}
        {can(['purchases.manage']) && (
          <div className="flex flex-wrap items-center justify-end gap-2">
            {doc && editable && <Button variant="secondary" loading={act.isPending} onClick={() => act.mutate({ id: doc.id, action: 'cancel' })}>{t('stock.cancelDoc')}</Button>}
            {editable && <Button variant={doc ? 'secondary' : 'primary'} disabled={!valid} loading={save.isPending} onClick={() => save.mutate({ id: doc?.id ?? null, body: body() })}>{doc ? t('common.save') : t('stock.createDraft')}</Button>}
            {doc && editable && <Button loading={act.isPending} onClick={() => act.mutate({ id: doc.id, action: 'post' })}>{t('purchasing.postReturn')}</Button>}
          </div>
        )}
      </div>
    </>
  );
}
