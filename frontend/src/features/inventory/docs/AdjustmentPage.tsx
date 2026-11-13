import { Camera } from 'lucide-react';
import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate, useParams } from 'react-router-dom';
import { Badge, Button, Card, CardBody, ConfirmDialog, ErrorState, Input, Modal, PageHeader, PageSkeleton, Select, Textarea, toast } from '@/design/components';
import { useSession } from '@/features/auth/useSession';
import { useLookupOptions } from '../../catalog/hooks';
import { apiMessage, serverFieldError, useApiError } from '../../catalog/useApiError';
import { str, useDerivedState } from '../../catalog/util';
import { adjustmentApi } from '../../stock/api';
import { useSuppliers, useWarehouses } from '../../stock/hooks';
import { LinesEditor, type Column } from '../../stock/LinesEditor';
import { StatusBadge } from '../../stock/StatusBadge';
import type { Doc, DocLine } from '../../stock/types';
import { toLines, useDoc, useDocAct, useDocDelete, useDocSave } from '../../stock/useDoc';
import { VariantPicker } from '../../stock/VariantPicker';
import { InventoryTabs } from '../InventoryTabs';

const BASE = '/inventory/adjustments';

/** FR-403: one adjustment. Draft → (approval if big) → posted. Photos of the damage attach to the draft. */
export function AdjustmentPage() {
  const { t } = useTranslation();
  const { id } = useParams();
  const isNew = !id || id === 'new';
  const doc = useDoc('stock-adjustments', id);
  if (!isNew && doc.isLoading) return <PageSkeleton />;
  if (!isNew && (doc.error || !doc.data)) return <ErrorState title={t('errors.NOT_FOUND')} onRetry={() => void doc.refetch()} />;
  return <AdjustmentForm key={doc.data?.id ?? 'new'} doc={isNew ? null : (doc.data as Doc)} />;
}

function AdjustmentForm({ doc }: { doc: Doc | null }) {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { can } = useSession();
  const onError = useApiError();
  const warehouses = useWarehouses();
  const reasons = useLookupOptions('adjustment_reason');
  const suppliers = useSuppliers();
  const save = useDocSave('stock-adjustments', BASE);
  const act = useDocAct('stock-adjustments');
  const remove = useDocDelete('stock-adjustments', BASE);
  const [warehouseId, setWarehouseId] = useDerivedState(doc?.id, () => String(doc?.warehouse?.id ?? ''));
  const [reasonId, setReasonId] = useDerivedState(doc?.id, () => String(doc?.reason?.id ?? ''));
  const [note, setNote] = useDerivedState(doc?.id, () => str(doc?.note, ''));
  const [lines, setLines] = useDerivedState<DocLine[]>(doc?.updated_at, () => toLines(doc ?? undefined));
  const [confirmDelete, setConfirmDelete] = useState(false);
  const [rejecting, setRejecting] = useState(false);
  const [rejectReason, setRejectReason] = useState('');
  const fileRef = useRef<HTMLInputElement>(null);
  const [photoFor, setPhotoFor] = useState<number | null>(null);
  const status = doc?.status ?? 'draft';
  const editable = !doc || status === 'draft' || status === 'rejected';
  const canApprove = can(['inventory.adjust.approve']);
  const requiresApproval = Boolean(doc?.requires_approval);
  const hasCost = lines.some((l) => l.unit_cost !== undefined) || can(['products.view_cost']);

  const body = () => ({
    ...(doc ? {} : { warehouse_id: Number(warehouseId) }), reason_id: Number(reasonId), note: note.trim() || null,
    items: lines.map((l) => ({
      variant_id: l.variant_id, qty_delta: String(l.qty_delta ?? ''), is_breakage: Boolean(l.is_breakage), note: String(l.note ?? '') || null,
      ...(hasCost && String(l.unit_cost ?? '') !== '' ? { unit_cost: String(l.unit_cost) } : {}),
      supplier_id: l.supplier_id ? Number(l.supplier_id) : null, photo_attachment_id: l.photo_attachment_id ? Number(l.photo_attachment_id) : null,
    })),
  });

  const upload = async (file: File | undefined) => {
    if (!file || !doc || photoFor === null) return;
    try {
      const att = await adjustmentApi.photo(doc.id, file);
      setLines(lines.map((l, i) => (i === photoFor ? { ...l, photo_attachment_id: att.id } : l)));
      toast.success(t('inventory.photoAttached'));
    } catch (e) {
      onError(e);
    }
  };

  const columns: Column[] = [
    { key: 'qty_delta', label: t('inventory.qtyChange'), kind: 'qty', width: '8rem', error: (i) => serverFieldError(t, save.error, `items.${i}.qty_delta`) },
    ...(hasCost ? [{ key: 'unit_cost', label: t('inventory.unitCost'), kind: 'money' as const, width: '8rem' }] : []),
    { key: 'is_breakage', label: t('inventory.isBreakage'), kind: 'switch' },
    { key: 'supplier_id', label: t('purchasing.supplierClaim'), kind: 'select', options: (suppliers.data ?? []).map((s) => ({ value: String(s.id), label: s.name })) },
    { key: 'note', label: t('stock.note'), kind: 'text' },
    { key: 'photo', label: t('inventory.photo'), kind: 'readonly', render: (l, i) => (
      <span className="flex items-center gap-2">
        {l.photo_attachment_id ? <a className="text-navy-700 underline" href={adjustmentApi.photoUrl(Number(l.photo_attachment_id))} target="_blank" rel="noreferrer">{t('inventory.viewPhoto')}</a> : null}
        {doc && editable && <Button type="button" size="sm" variant="ghost" leftIcon={<Camera className="h-4 w-4" aria-hidden="true" />} onClick={() => { setPhotoFor(i); fileRef.current?.click(); }}>{t('inventory.attach')}</Button>}
      </span>
    ) },
  ];
  const valid = warehouseId !== '' && reasonId !== '' && lines.length > 0 && lines.every((l) => String(l.qty_delta ?? '').trim() !== '' && Number(l.qty_delta) !== 0);
  const saveDraft = () => save.mutate({ id: doc?.id ?? null, body: body() });
  const busy = save.isPending || act.isPending;

  return (
    <>
      <PageHeader title={doc ? String(doc.adj_no) : t('inventory.newAdjustment')} description={doc ? <span className="flex items-center gap-2"><StatusBadge status={status} />{requiresApproval && <Badge tone="warning">{t('inventory.needsApproval')}</Badge>}</span> : t('inventory.adjustmentsSubtitle')}
        crumbs={[{ label: t('nav.inventory'), to: '/inventory' }, { label: t('inventory.tabs.adjustments'), to: BASE }, { label: doc ? String(doc.adj_no) : t('stock.new') }]} />
      <InventoryTabs />
      <div className="grid gap-5">
        <Card><CardBody className="grid gap-4 md:grid-cols-3">
          <Select label={t('inventory.warehouse')} required disabled={!!doc} value={warehouseId || undefined} onChange={setWarehouseId} error={serverFieldError(t, save.error, 'warehouse_id')} options={(warehouses.data ?? []).map((w) => ({ value: String(w.id), label: w.name }))} />
          <Select label={t('inventory.reason')} required disabled={!editable} value={reasonId || undefined} onChange={setReasonId} error={serverFieldError(t, save.error, 'reason_id')} options={reasons} />
          <div className="md:col-span-3"><Textarea label={t('stock.note')} rows={2} disabled={!editable} value={note} onChange={(e) => setNote(e.target.value)} /></div>
        </CardBody></Card>
        {editable && <Card><CardBody><VariantPicker exclude={lines.map((l) => l.variant_id)} onPick={(v) => setLines([...lines, { variant_id: v.id, sku: v.sku, variant_name: v.variant_name, qty_delta: '', unit_cost: '', is_breakage: false, note: '' }])} /></CardBody></Card>}
        <LinesEditor lines={lines} columns={columns} onChange={setLines} disabled={!editable} caption={t('inventory.tabs.adjustments')} />
        <input ref={fileRef} type="file" accept="image/jpeg,image/png,image/webp" className="sr-only" data-testid="adjustment-photo" onChange={(e) => { void upload(e.target.files?.[0]); e.target.value = ''; }} />
        {status === 'posted' && doc !== null && doc.total_value !== undefined ? <p className="text-sm text-ink-muted">{t('inventory.postedValue', { value: str(doc.total_value) })}</p> : null}
        <div className="flex flex-wrap items-center justify-end gap-2">
          {doc && editable && <Button variant="secondary" onClick={() => setConfirmDelete(true)}>{t('common.delete')}</Button>}
          {editable && <Button variant="secondary" disabled={!valid} loading={save.isPending} onClick={saveDraft}>{t('common.save')}</Button>}
          {doc && editable && !requiresApproval && <Button disabled={busy} loading={act.isPending} onClick={() => act.mutate({ id: doc.id, action: 'post' }, { onSuccess: () => navigate(BASE) })}>{t('inventory.postAdjustment')}</Button>}
          {doc && editable && requiresApproval && canApprove && <Button disabled={busy} loading={act.isPending} onClick={() => act.mutate({ id: doc.id, action: 'post' })}>{t('inventory.approveAndPost')}</Button>}
          {doc && editable && requiresApproval && !canApprove && <Button disabled={busy} loading={act.isPending} onClick={() => act.mutate({ id: doc.id, action: 'submit' })}>{t('inventory.sendForApproval')}</Button>}
          {doc && status === 'pending_approval' && canApprove && <><Button variant="secondary" onClick={() => setRejecting(true)}>{t('inventory.reject')}</Button><Button loading={act.isPending} onClick={() => act.mutate({ id: doc.id, action: 'post' })}>{t('inventory.approveAndPost')}</Button></>}
          {!doc && <Button disabled={!valid} loading={save.isPending} onClick={saveDraft}>{t('stock.createDraft')}</Button>}
        </div>
        {act.isError ? <p role="alert" className="text-sm text-danger">{apiMessage(t, act.error)}</p> : null}
      </div>
      <ConfirmDialog open={confirmDelete} onOpenChange={setConfirmDelete} danger title={t('stock.deleteTitle')} body={t('stock.deleteBody')} confirmLabel={t('common.delete')} loading={remove.isPending} onConfirm={() => { if (doc) remove.mutate(doc.id); }} />
      <Modal open={rejecting} onOpenChange={setRejecting} title={t('inventory.reject')} footer={<><Button variant="secondary" onClick={() => setRejecting(false)}>{t('common.cancel')}</Button><Button variant="danger" loading={act.isPending} onClick={() => doc && act.mutate({ id: doc.id, action: 'reject', body: { reason: rejectReason.trim() || undefined } }, { onSuccess: () => setRejecting(false) })}>{t('inventory.reject')}</Button></>}>
        <Input label={t('inventory.rejectReason')} value={rejectReason} onChange={(e) => setRejectReason(e.target.value)} />
      </Modal>
    </>
  );
}
