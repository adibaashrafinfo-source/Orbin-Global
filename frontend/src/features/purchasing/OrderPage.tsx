import { useQuery } from '@tanstack/react-query';
import { Copy, FileText, MessageCircle, PackagePlus } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate, useParams } from 'react-router-dom';
import { Button, Card, CardBody, ConfirmDialog, ErrorState, Input, PageHeader, PageSkeleton, Select, Textarea, toast } from '@/design/components';
import { useSession } from '@/features/auth/useSession';
import { formatMoney } from '@/lib/money';
import { apiMessage, serverFieldError, useApiError } from '../catalog/useApiError';
import { str, useDerivedState } from '../catalog/util';
import { purchaseApi } from '../stock/api';
import { lineTotalPaisa, paisaToMoney, todayDhaka } from '../stock/format';
import { useSuppliers } from '../stock/hooks';
import { LinesEditor, type Column } from '../stock/LinesEditor';
import { StatusBadge } from '../stock/StatusBadge';
import type { Doc, DocLine } from '../stock/types';
import { toLines, useDoc, useDocAct, useDocDelete, useDocSave } from '../stock/useDoc';
import { VariantPicker } from '../stock/VariantPicker';
import { PurchasingTabs } from './PurchasingTabs';

const BASE = '/purchases/orders';

/** FR-502 / FR-507: build an order with the last purchase costs in view, approve it, send it, receive against it. */
export function OrderPage() {
  const { t } = useTranslation();
  const { id } = useParams();
  const isNew = !id || id === 'new';
  const doc = useDoc('purchase-orders', id);
  if (!isNew && doc.isLoading) return <PageSkeleton />;
  if (!isNew && (doc.error || !doc.data)) return <ErrorState title={t('errors.NOT_FOUND')} onRetry={() => void doc.refetch()} />;
  return <OrderForm key={doc.data?.id ?? 'new'} doc={isNew ? null : (doc.data as Doc)} />;
}

/** The last purchase costs of a variant, shown under its SKU on the line. */
function History({ variantId, supplierId }: { variantId: number; supplierId: number | null }) {
  const { t } = useTranslation();
  const q = useQuery({ queryKey: ['purchase-history', variantId, supplierId], queryFn: () => purchaseApi.history(variantId, supplierId ?? undefined), staleTime: 60_000 });
  if (!q.data || q.data.length === 0) return <div className="text-xs text-ink-subtle">{t('purchasing.noHistory')}</div>;
  return <div className="text-xs text-ink-muted">{t('purchasing.lastCosts')}: {q.data.map((h) => `${formatMoney(h.unit_cost, { symbol: false })} (${h.supplier}, ${h.date})`).join(' · ')}</div>;
}

function OrderForm({ doc }: { doc: Doc | null }) {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { can } = useSession();
  const onError = useApiError();
  const suppliers = useSuppliers();
  const save = useDocSave('purchase-orders', BASE);
  const act = useDocAct('purchase-orders');
  const remove = useDocDelete('purchase-orders', BASE);
  const [supplierId, setSupplierId] = useDerivedState(doc?.id, () => String(doc?.supplier?.id ?? ''));
  const [orderDate, setOrderDate] = useDerivedState(doc?.id, () => str(doc?.order_date, todayDhaka()));
  const [expected, setExpected] = useDerivedState(doc?.id, () => str(doc?.expected_date, ''));
  const [note, setNote] = useDerivedState(doc?.id, () => str(doc?.note, ''));
  const [extra, setExtra] = useDerivedState(doc?.id, () => str(doc?.extra_total, '0'));
  const [lines, setLines] = useDerivedState<DocLine[]>(doc?.updated_at, () => toLines(doc ?? undefined));
  const [confirmDelete, setConfirmDelete] = useState(false);
  const status = doc?.status ?? 'draft';
  const editable = !doc || status === 'draft';
  const canManage = can(['purchases.manage']);
  const sid = supplierId ? Number(supplierId) : null;

  const rowTotals = lines.map((l) => lineTotalPaisa(String(l.qty_ordered ?? ''), String(l.unit_cost ?? '')) ?? 0n);
  const subtotal = rowTotals.reduce((a, b) => a + b, 0n);
  const tax = lines.reduce((a, l, i) => a + ((rowTotals[i] ?? 0n) * BigInt(Math.round(Number(l.tax_rate_percent || 0) * 100)) + 5000n) / 10000n, 0n);
  const extraPaisa = BigInt(Math.round(Number(extra || 0) * 100));
  const columns: Column[] = [
    { key: 'qty_ordered', label: t('purchasing.qtyOrdered'), kind: 'qty', width: '7rem', error: (i) => serverFieldError(t, save.error, `items.${i}.qty_ordered`) },
    { key: 'unit_cost', label: t('purchasing.unitCost'), kind: 'money', width: '8rem', error: (i) => serverFieldError(t, save.error, `items.${i}.unit_cost`) },
    { key: 'tax_rate_percent', label: t('purchasing.taxPercent'), kind: 'money', width: '6rem' },
    { key: 'line_total', label: t('purchasing.lineTotal'), kind: 'readonly', render: (_l, i) => formatMoney(paisaToMoney(rowTotals[i] ?? 0n)) },
    ...(doc && !editable ? [{ key: 'qty_received', label: t('purchasing.qtyReceived'), kind: 'readonly' as const, render: (l: DocLine) => String(l.qty_received ?? '0') }] : []),
  ];
  const valid = supplierId !== '' && lines.length > 0 && lines.every((l) => Number(l.qty_ordered) > 0 && String(l.unit_cost ?? '') !== '');
  const body = () => ({ supplier_id: Number(supplierId), order_date: orderDate, expected_date: expected || null, note: note.trim() || null, extra_total: extra || '0',
    items: lines.map((l) => ({ variant_id: l.variant_id, qty_ordered: String(l.qty_ordered), unit_cost: String(l.unit_cost), tax_rate_percent: String(l.tax_rate_percent ?? '') === '' ? undefined : String(l.tax_rate_percent) })) });

  const share = async () => {
    if (!doc) return;
    try {
      const link = await purchaseApi.shareLink(doc.id);
      await navigator.clipboard?.writeText(link.url);
      toast.success(t('purchasing.linkCopied'));
    } catch (e) {
      onError(e);
    }
  };
  const whatsapp = async () => {
    if (!doc) return;
    try {
      const link = await purchaseApi.shareLink(doc.id);
      window.open(`https://wa.me/?text=${encodeURIComponent(`${t('purchasing.whatsappText', { no: String(doc.po_no) })}\n${link.url}`)}`, '_blank', 'noopener');
    } catch (e) {
      onError(e);
    }
  };

  return (
    <>
      <PageHeader title={doc ? String(doc.po_no) : t('purchasing.newOrder')} description={doc ? <StatusBadge status={status} /> : t('purchasing.ordersSubtitle')}
        crumbs={[{ label: t('nav.purchases') }, { label: t('purchasing.tabs.orders'), to: BASE }, { label: doc ? String(doc.po_no) : t('stock.new') }]}
        actions={doc ? <div className="flex gap-2"><a className="inline-flex h-9 items-center gap-2 rounded-input border border-navy-200 bg-surface px-3 text-sm font-semibold text-navy-800 hover:bg-navy-50 focus-visible:focus-ring-light" href={purchaseApi.pdfUrl(doc.id)} target="_blank" rel="noreferrer"><FileText className="h-4 w-4" aria-hidden="true" />{t('purchasing.pdf')}</a>
          {canManage && <><Button variant="secondary" size="sm" leftIcon={<Copy className="h-4 w-4" aria-hidden="true" />} onClick={() => void share()}>{t('purchasing.copyLink')}</Button><Button variant="secondary" size="sm" leftIcon={<MessageCircle className="h-4 w-4" aria-hidden="true" />} onClick={() => void whatsapp()}>{t('purchasing.whatsapp')}</Button></>}</div> : undefined} />
      <PurchasingTabs />
      <div className="grid gap-5">
        <Card><CardBody className="grid gap-4 md:grid-cols-3">
          <Select label={t('purchasing.supplier')} required disabled={!editable} value={supplierId || undefined} onChange={setSupplierId} error={serverFieldError(t, save.error, 'supplier_id')} options={(suppliers.data ?? []).map((s) => ({ value: String(s.id), label: s.name }))} />
          <Input label={t('purchasing.orderDate')} type="date" required disabled={!editable} value={orderDate} onChange={(e) => setOrderDate(e.target.value)} error={serverFieldError(t, save.error, 'order_date')} />
          <Input label={t('purchasing.expectedDate')} type="date" disabled={!editable} value={expected} onChange={(e) => setExpected(e.target.value)} error={serverFieldError(t, save.error, 'expected_date')} />
          <div className="md:col-span-3"><Textarea label={t('stock.note')} rows={2} disabled={!editable} value={note} onChange={(e) => setNote(e.target.value)} /></div>
        </CardBody></Card>
        {editable && <Card><CardBody><VariantPicker exclude={lines.map((l) => l.variant_id)} onPick={(v) => setLines([...lines, { variant_id: v.id, sku: v.sku, variant_name: v.variant_name, qty_ordered: '', unit_cost: v.last_cost && Number(v.last_cost) > 0 ? v.last_cost : '', tax_rate_percent: '' }])} /></CardBody></Card>}
        <LinesEditor lines={lines} columns={columns} onChange={setLines} disabled={!editable} caption={t('purchasing.tabs.orders')} note={(l) => <History variantId={l.variant_id} supplierId={sid} />} />
        <Card><CardBody className="ms-auto grid w-full max-w-sm gap-1 text-sm">
          <div className="flex justify-between"><span>{t('purchasing.subtotal')}</span><span className="tabular">{formatMoney(paisaToMoney(subtotal))}</span></div>
          <div className="flex justify-between"><span>{t('purchasing.tax')}</span><span className="tabular">{formatMoney(paisaToMoney(tax))}</span></div>
          <div className="flex items-center justify-between gap-3"><span>{t('purchasing.otherCosts')}</span>{editable ? <Input aria-label={t('purchasing.otherCosts')} className="w-32" inputMode="decimal" value={extra} onChange={(e) => setExtra(e.target.value)} /> : <span className="tabular">{formatMoney(str(doc?.extra_total, '0'))}</span>}</div>
          <div className="flex justify-between border-t border-navy-100 pt-1 font-heading text-base font-bold text-navy-900"><span>{t('purchasing.grandTotal')}</span><span className="tabular">{formatMoney(paisaToMoney(subtotal + tax + extraPaisa))}</span></div>
          <p className="text-xs text-ink-subtle">{t('purchasing.serverTotals')}</p>
        </CardBody></Card>
        {act.isError ? <p role="alert" className="text-sm text-danger">{apiMessage(t, act.error)}</p> : null}
        {canManage && (
          <div className="flex flex-wrap items-center justify-end gap-2">
            {doc && editable && <Button variant="secondary" onClick={() => setConfirmDelete(true)}>{t('common.delete')}</Button>}
            {doc && ['draft', 'approved'].includes(status) && <Button variant="secondary" loading={act.isPending} onClick={() => act.mutate({ id: doc.id, action: 'cancel' })}>{t('stock.cancelDoc')}</Button>}
            {doc && ['partially_received', 'received'].includes(status) && <Button variant="secondary" loading={act.isPending} onClick={() => act.mutate({ id: doc.id, action: 'close' })}>{t('purchasing.closeOrder')}</Button>}
            {editable && <Button variant={doc ? 'secondary' : 'primary'} disabled={!valid} loading={save.isPending} onClick={() => save.mutate({ id: doc?.id ?? null, body: body() })}>{doc ? t('common.save') : t('stock.createDraft')}</Button>}
            {doc && editable && <Button loading={act.isPending} onClick={() => act.mutate({ id: doc.id, action: 'approve' })}>{t('purchasing.approve')}</Button>}
            {doc && ['approved', 'partially_received'].includes(status) && can(['purchases.grn', 'purchases.manage']) && <Button leftIcon={<PackagePlus className="h-4 w-4" aria-hidden="true" />} onClick={() => navigate(`/purchases/receipts/new?po=${doc.id}`)}>{t('purchasing.receiveGoods')}</Button>}
          </div>
        )}
      </div>
      <ConfirmDialog open={confirmDelete} onOpenChange={setConfirmDelete} danger title={t('stock.deleteTitle')} body={t('stock.deleteBody')} confirmLabel={t('common.delete')} loading={remove.isPending} onConfirm={() => { if (doc) remove.mutate(doc.id); }} />
    </>
  );
}
