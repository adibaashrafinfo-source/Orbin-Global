import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useParams, useSearchParams } from 'react-router-dom';
import { Button, Card, CardBody, ConfirmDialog, ErrorState, Input, PageHeader, PageSkeleton, Select, Textarea } from '@/design/components';
import { useSession } from '@/features/auth/useSession';
import { apiMessage, serverFieldError } from '../catalog/useApiError';
import { str, useDerivedState } from '../catalog/util';
import { purchaseApi } from '../stock/api';
import { todayDhaka } from '../stock/format';
import { useSuppliers, useWarehouses } from '../stock/hooks';
import { LinesEditor, type Column } from '../stock/LinesEditor';
import { StatusBadge } from '../stock/StatusBadge';
import type { Doc, DocLine, ExtraCost } from '../stock/types';
import { toLines, useDoc, useDocAct, useDocDelete, useDocSave } from '../stock/useDoc';
import { VariantPicker } from '../stock/VariantPicker';
import { ExtraCostsEditor } from './ExtraCostsEditor';
import { LandedCostPanel } from './LandedCostPanel';
import { PurchasingTabs } from './PurchasingTabs';

const BASE = '/purchases/receipts';

/** FR-503: receive goods, add the extra costs, watch the landed cost per unit, post once. */
export function ReceiptPage() {
  const { t } = useTranslation();
  const { id } = useParams();
  const [params] = useSearchParams();
  const isNew = !id || id === 'new';
  const poId = isNew && params.get('po') ? Number(params.get('po')) : null;
  const doc = useDoc('goods-receipts', id);
  const draft = useQuery({ queryKey: ['receipt-draft', poId], queryFn: () => purchaseApi.receiptDraft(poId as number), enabled: poId !== null });
  if (!isNew && doc.isLoading) return <PageSkeleton />;
  if (!isNew && (doc.error || !doc.data)) return <ErrorState title={t('errors.NOT_FOUND')} onRetry={() => void doc.refetch()} />;
  if (poId !== null && draft.isLoading) return <PageSkeleton />;
  if (poId !== null && (draft.error || !draft.data)) return <ErrorState onRetry={() => void draft.refetch()} />;
  return <ReceiptForm key={doc.data?.id ?? `new-${poId ?? ''}`} doc={isNew ? null : (doc.data as Doc)} fromOrder={draft.data ?? null} />;
}

type OrderDraft = NonNullable<Awaited<ReturnType<typeof purchaseApi.receiptDraft>>>;

function ReceiptForm({ doc, fromOrder }: { doc: Doc | null; fromOrder: OrderDraft | null }) {
  const { t } = useTranslation();
  const { can } = useSession();
  const suppliers = useSuppliers();
  const warehouses = useWarehouses();
  const save = useDocSave('goods-receipts', BASE);
  const act = useDocAct('goods-receipts', 'purchasing.receiptPosted');
  const remove = useDocDelete('goods-receipts', BASE);
  const initialLines = (): DocLine[] => (doc ? toLines(doc) : (fromOrder?.items ?? []).map((i) => ({ po_item_id: i.po_item_id, variant_id: i.variant_id, sku: i.sku, variant_name: i.variant_name, qty_ordered: i.qty_ordered, qty_so_far: i.qty_received_so_far, qty_received: i.qty_received, qty_damaged: '0', unit_cost: i.unit_cost })));
  const [supplierId, setSupplierId] = useDerivedState(doc?.id, () => String(doc?.supplier?.id ?? fromOrder?.supplier_id ?? ''));
  const [warehouseId, setWarehouseId] = useDerivedState(doc?.id, () => String(doc?.warehouse?.id ?? ''));
  const [receivedDate, setReceivedDate] = useDerivedState(doc?.id, () => str(doc?.received_date, todayDhaka()));
  const [invoiceNo, setInvoiceNo] = useDerivedState(doc?.id, () => str(doc?.supplier_invoice_no, ''));
  const [invoiceDate, setInvoiceDate] = useDerivedState(doc?.id, () => str(doc?.supplier_invoice_date, ''));
  const [basis, setBasis] = useDerivedState<'value' | 'quantity'>(doc?.id, () => (doc?.allocation_basis as 'value' | 'quantity' | undefined) ?? 'value');
  const [note, setNote] = useDerivedState(doc?.id, () => str(doc?.note, ''));
  const [lines, setLines] = useDerivedState<DocLine[]>(doc?.updated_at, initialLines);
  const [extras, setExtras] = useDerivedState<ExtraCost[]>(doc?.updated_at, () => (doc?.extra_costs ?? []).map((e) => ({ ...e, description: e.description ?? '', paid_to: e.paid_to ?? '' })));
  const [confirmDelete, setConfirmDelete] = useState(false);
  const status = doc?.status ?? 'draft';
  const editable = !doc || status === 'draft';
  const poLinked = Boolean(doc?.po_id ?? fromOrder);
  const canPost = can(['purchases.grn', 'purchases.manage']);

  const columns: Column[] = [
    ...(poLinked ? [{ key: 'qty_ordered', label: t('purchasing.qtyOrdered'), kind: 'readonly' as const, render: (l: DocLine) => (l.qty_ordered === undefined ? '—' : `${String(l.qty_ordered)} (${t('purchasing.soFar', { qty: String(l.qty_so_far ?? '0') })})`) }] : []),
    { key: 'qty_received', label: t('purchasing.qtyArrived'), kind: 'qty', width: '7rem', error: (i) => serverFieldError(t, save.error, `items.${i}.qty_received`) },
    { key: 'qty_damaged', label: t('purchasing.qtyDamaged'), kind: 'qty', width: '7rem', error: (i) => serverFieldError(t, save.error, `items.${i}.qty_damaged`) },
    { key: 'unit_cost', label: t('purchasing.unitCost'), kind: 'money', width: '8rem', error: (i) => serverFieldError(t, save.error, `items.${i}.unit_cost`) },
  ];
  const valid = supplierId !== '' && warehouseId !== '' && lines.length > 0 && lines.every((l) => String(l.qty_received ?? '') !== '' && String(l.unit_cost ?? '') !== '') && extras.every((e) => e.amount !== '');
  const body = () => ({
    ...(doc ? {} : { supplier_id: Number(supplierId), warehouse_id: Number(warehouseId), po_id: fromOrder?.po_id ?? null }),
    supplier_invoice_no: invoiceNo.trim() || null, supplier_invoice_date: invoiceDate || null, received_date: receivedDate, allocation_basis: basis, note: note.trim() || null,
    items: lines.map((l) => ({ po_item_id: l.po_item_id ?? null, variant_id: l.variant_id, qty_received: String(l.qty_received), qty_damaged: String(l.qty_damaged || '0'), unit_cost: String(l.unit_cost) })),
    extra_costs: extras.map((e) => ({ cost_type: e.cost_type, description: e.description || null, amount: e.amount, paid_to: e.paid_to || null })),
  });

  return (
    <>
      <PageHeader title={doc ? String(doc.grn_no) : t('purchasing.newReceipt')} description={doc ? <StatusBadge status={status} /> : t('purchasing.receiptsSubtitle')}
        crumbs={[{ label: t('nav.purchases') }, { label: t('purchasing.tabs.receipts'), to: BASE }, { label: doc ? String(doc.grn_no) : t('stock.new') }]} />
      <PurchasingTabs />
      <div className="grid gap-5">
        <Card><CardBody className="grid gap-4 md:grid-cols-3">
          <Select label={t('purchasing.supplier')} required disabled={!!doc || poLinked} value={supplierId || undefined} onChange={setSupplierId} error={serverFieldError(t, save.error, 'supplier_id')} options={(suppliers.data ?? []).map((s) => ({ value: String(s.id), label: s.name }))} />
          <Select label={t('inventory.warehouse')} required disabled={!!doc} value={warehouseId || undefined} onChange={setWarehouseId} error={serverFieldError(t, save.error, 'warehouse_id')} options={(warehouses.data ?? []).map((w) => ({ value: String(w.id), label: w.name }))} />
          <Input label={t('purchasing.receivedDate')} type="date" required disabled={!editable} value={receivedDate} onChange={(e) => setReceivedDate(e.target.value)} error={serverFieldError(t, save.error, 'received_date')} />
          <Input label={t('purchasing.supplierInvoice')} disabled={!editable} value={invoiceNo} onChange={(e) => setInvoiceNo(e.target.value)} />
          <Input label={t('purchasing.supplierInvoiceDate')} type="date" disabled={!editable} value={invoiceDate} onChange={(e) => setInvoiceDate(e.target.value)} />
          <Select label={t('purchasing.allocateBy')} disabled={!editable} value={basis} onChange={(v) => setBasis(v as 'value' | 'quantity')} options={[{ value: 'value', label: t('purchasing.byValue') }, { value: 'quantity', label: t('purchasing.byQuantity') }]} />
          <div className="md:col-span-3"><Textarea label={t('stock.note')} rows={2} disabled={!editable} value={note} onChange={(e) => setNote(e.target.value)} /></div>
        </CardBody></Card>
        {editable && !poLinked && <Card><CardBody><VariantPicker exclude={lines.map((l) => l.variant_id)} onPick={(v) => setLines([...lines, { variant_id: v.id, sku: v.sku, variant_name: v.variant_name, qty_received: '', qty_damaged: '0', unit_cost: v.last_cost && Number(v.last_cost) > 0 ? v.last_cost : '' }])} /></CardBody></Card>}
        <LinesEditor lines={lines} columns={columns} onChange={setLines} disabled={!editable} caption={t('purchasing.tabs.receipts')} />
        <Card><CardBody><h3 className="mb-2 font-heading text-base font-bold text-navy-900">{t('purchasing.extraCosts')}</h3><ExtraCostsEditor costs={extras} onChange={setExtras} disabled={!editable} /></CardBody></Card>
        <LandedCostPanel lines={lines} extras={extras} basis={basis} posted={status === 'posted'} />
        {act.isError ? <p role="alert" className="text-sm text-danger">{apiMessage(t, act.error)}</p> : null}
        {canPost && (
          <div className="flex flex-wrap items-center justify-end gap-2">
            {doc && editable && <Button variant="secondary" onClick={() => setConfirmDelete(true)}>{t('common.delete')}</Button>}
            {doc && editable && <Button variant="secondary" loading={act.isPending} onClick={() => act.mutate({ id: doc.id, action: 'cancel' })}>{t('stock.cancelDoc')}</Button>}
            {editable && <Button variant={doc ? 'secondary' : 'primary'} disabled={!valid} loading={save.isPending} onClick={() => save.mutate({ id: doc?.id ?? null, body: body() })}>{doc ? t('common.save') : t('stock.createDraft')}</Button>}
            {doc && editable && <Button variant="accent" loading={act.isPending} onClick={() => act.mutate({ id: doc.id, action: 'post' })}>{t('purchasing.postReceipt')}</Button>}
          </div>
        )}
      </div>
      <ConfirmDialog open={confirmDelete} onOpenChange={setConfirmDelete} danger title={t('stock.deleteTitle')} body={t('stock.deleteBody')} confirmLabel={t('common.delete')} loading={remove.isPending} onConfirm={() => { if (doc) remove.mutate(doc.id); }} />
    </>
  );
}
