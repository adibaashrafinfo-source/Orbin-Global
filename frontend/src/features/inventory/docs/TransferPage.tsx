import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useParams } from 'react-router-dom';
import { Button, Card, CardBody, ConfirmDialog, ErrorState, PageHeader, PageSkeleton, Select, Textarea } from '@/design/components';
import { useSession } from '@/features/auth/useSession';
import { apiMessage, serverFieldError } from '../../catalog/useApiError';
import { str, useDerivedState } from '../../catalog/util';
import { fmtQty } from '../../stock/format';
import { useWarehouses } from '../../stock/hooks';
import { LinesEditor, type Column } from '../../stock/LinesEditor';
import { StatusBadge } from '../../stock/StatusBadge';
import type { Doc, DocLine } from '../../stock/types';
import { toLines, useDoc, useDocAct, useDocDelete, useDocSave } from '../../stock/useDoc';
import { VariantPicker } from '../../stock/VariantPicker';
import { InventoryTabs } from '../InventoryTabs';

const BASE = '/inventory/transfers';

/** FR-405: draft → in transit → received. Receiving is where a short delivery is noticed. */
export function TransferPage() {
  const { t } = useTranslation();
  const { id } = useParams();
  const isNew = !id || id === 'new';
  const doc = useDoc('stock-transfers', id);
  if (!isNew && doc.isLoading) return <PageSkeleton />;
  if (!isNew && (doc.error || !doc.data)) return <ErrorState title={t('errors.NOT_FOUND')} onRetry={() => void doc.refetch()} />;
  return <TransferForm key={doc.data?.id ?? 'new'} doc={isNew ? null : (doc.data as Doc)} />;
}

function TransferForm({ doc }: { doc: Doc | null }) {
  const { t } = useTranslation();
  const { can } = useSession();
  const warehouses = useWarehouses();
  const save = useDocSave('stock-transfers', BASE);
  const act = useDocAct('stock-transfers');
  const remove = useDocDelete('stock-transfers', BASE);
  const [from, setFrom] = useDerivedState(doc?.id, () => String(doc?.from_warehouse?.id ?? ''));
  const [to, setTo] = useDerivedState(doc?.id, () => String(doc?.to_warehouse?.id ?? ''));
  const [note, setNote] = useDerivedState(doc?.id, () => str(doc?.note, ''));
  const [lines, setLines] = useDerivedState<DocLine[]>(doc?.updated_at, () => toLines(doc ?? undefined));
  const [received, setReceived] = useDerivedState<Record<number, string>>(doc?.id, () => Object.fromEntries((doc?.items ?? []).map((i) => [Number(i.id), String(i.qty_sent)])));
  const [confirmDelete, setConfirmDelete] = useState(false);
  const status = doc?.status ?? 'draft';
  const editable = !doc || status === 'draft';
  const canTransfer = can(['inventory.transfer']);
  const options = (warehouses.data ?? []).map((w) => ({ value: String(w.id), label: w.name }));
  const valid = from !== '' && to !== '' && from !== to && lines.length > 0 && lines.every((l) => Number(l.qty_sent) > 0);
  const body = () => ({ ...(doc ? {} : { from_warehouse_id: Number(from), to_warehouse_id: Number(to) }), note: note.trim() || null, items: lines.map((l) => ({ variant_id: l.variant_id, qty_sent: String(l.qty_sent), note: String(l.note ?? '') || null })) });

  const columns: Column[] = status === 'in_transit'
    ? [{ key: 'qty_sent', label: t('inventory.qtySent'), kind: 'readonly', render: (l) => fmtQty(String(l.qty_sent)) },
      { key: 'recv', label: t('inventory.qtyReceived'), kind: 'readonly', render: (l) => <input aria-label={`${l.sku} ${t('inventory.qtyReceived')}`} className="h-9 w-24 rounded-input border border-navy-200 px-2 text-end" inputMode="decimal" value={received[Number(l.id)] ?? ''} onChange={(e) => setReceived({ ...received, [Number(l.id)]: e.target.value })} /> }]
    : status === 'received'
      ? [{ key: 'qty_sent', label: t('inventory.qtySent'), kind: 'readonly', render: (l) => fmtQty(String(l.qty_sent)) }, { key: 'qty_received', label: t('inventory.qtyReceived'), kind: 'readonly', render: (l) => <span className={Number(l.qty_received) < Number(l.qty_sent) ? 'font-semibold text-danger' : ''}>{fmtQty(String(l.qty_received))}</span> }]
      : [{ key: 'qty_sent', label: t('inventory.qtySent'), kind: 'qty', width: '8rem', error: (i) => serverFieldError(t, save.error, `items.${i}.qty_sent`) }, { key: 'note', label: t('stock.note'), kind: 'text' }];
  const short = status === 'received' && lines.some((l) => Number(l.qty_received) < Number(l.qty_sent));

  return (
    <>
      <PageHeader title={doc ? String(doc.transfer_no) : t('inventory.newTransfer')} description={doc ? <StatusBadge status={status} /> : t('inventory.transfersSubtitle')}
        crumbs={[{ label: t('nav.inventory'), to: '/inventory' }, { label: t('inventory.tabs.transfers'), to: BASE }, { label: doc ? String(doc.transfer_no) : t('stock.new') }]} />
      <InventoryTabs />
      <div className="grid gap-5">
        <Card><CardBody className="grid gap-4 md:grid-cols-2">
          <Select label={t('inventory.fromWarehouse')} required disabled={!!doc} value={from || undefined} onChange={setFrom} options={options} />
          <Select label={t('inventory.toWarehouse')} required disabled={!!doc} value={to || undefined} onChange={setTo} options={options.filter((o) => o.value !== from)} error={serverFieldError(t, save.error, 'to_warehouse_id')} />
          <div className="md:col-span-2"><Textarea label={t('stock.note')} rows={2} disabled={!editable} value={note} onChange={(e) => setNote(e.target.value)} /></div>
        </CardBody></Card>
        {editable && <Card><CardBody><VariantPicker exclude={lines.map((l) => l.variant_id)} onPick={(v) => setLines([...lines, { variant_id: v.id, sku: v.sku, variant_name: v.variant_name, qty_sent: '', note: '' }])} /></CardBody></Card>}
        <LinesEditor lines={lines} columns={columns} onChange={setLines} disabled={!editable} caption={t('inventory.tabs.transfers')} />
        {short && <p role="status" className="rounded-card border border-warning bg-warning/10 px-4 py-3 text-sm">{t('inventory.shortDelivery')}</p>}
        {act.isError ? <p role="alert" className="text-sm text-danger">{apiMessage(t, act.error)}</p> : null}
        {canTransfer && (
          <div className="flex flex-wrap items-center justify-end gap-2">
            {doc && editable && <Button variant="secondary" onClick={() => setConfirmDelete(true)}>{t('common.delete')}</Button>}
            {editable && <Button variant={doc ? 'secondary' : 'primary'} disabled={!valid} loading={save.isPending} onClick={() => save.mutate({ id: doc?.id ?? null, body: body() })}>{doc ? t('common.save') : t('stock.createDraft')}</Button>}
            {doc && editable && <Button disabled={!valid} loading={act.isPending} onClick={() => act.mutate({ id: doc.id, action: 'send' })}>{t('inventory.sendTransfer')}</Button>}
            {doc && status === 'in_transit' && <><Button variant="secondary" loading={act.isPending} onClick={() => act.mutate({ id: doc.id, action: 'cancel' })}>{t('inventory.cancelTransfer')}</Button>
              <Button loading={act.isPending} onClick={() => act.mutate({ id: doc.id, action: 'receive', body: { items: lines.map((l) => ({ item_id: Number(l.id), qty_received: received[Number(l.id)] ?? String(l.qty_sent) })) } })}>{t('inventory.receiveTransfer')}</Button></>}
          </div>
        )}
      </div>
      <ConfirmDialog open={confirmDelete} onOpenChange={setConfirmDelete} danger title={t('stock.deleteTitle')} body={t('stock.deleteBody')} confirmLabel={t('common.delete')} loading={remove.isPending} onConfirm={() => { if (doc) remove.mutate(doc.id); }} />
    </>
  );
}
