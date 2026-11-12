import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { z } from 'zod';
import { Button, Input, Modal, Select, Switch, toast } from '@/design/components';
import { ApiError } from '@/lib/api';
import { branchesApi, BRANCHES_KEY, locationsApi, LOCATIONS_KEY, warehousesApi, WAREHOUSES_KEY, type Branch, type StorageLocation, type Warehouse } from './api';

const code = z.string().trim().min(1).max(20).regex(/^[A-Z0-9-]+$/);
const branchSchema = z.object({ code, name: z.string().trim().min(1).max(255), address: z.string().max(1000).nullable(), phone: z.string().max(30).nullable(), is_default: z.boolean(), is_active: z.boolean() });
const warehouseSchema = z.object({ branch_id: z.number().int().positive(), code, name: z.string().trim().min(1).max(255), kind: z.enum(['showroom', 'godown']), is_default: z.boolean(), is_sellable: z.boolean(), is_active: z.boolean() });
const locationSchema = z.object({ warehouse_id: z.number().int().positive(), parent_id: z.number().int().nullable(), code: z.string().trim().min(1).max(30), name: z.string().max(255).nullable(), kind: z.enum(['rack', 'shelf', 'bin']), is_active: z.boolean() });

function useSaver<T>(key: readonly string[], create: (b: T) => Promise<unknown>, update: (id: number, b: T) => Promise<unknown>, onDone: () => void) {
  const { t } = useTranslation();
  const client = useQueryClient();
  return useMutation({
    mutationFn: ({ id, body }: { id: number | null; body: T }) => (id ? update(id, body) : create(body)),
    onSuccess: () => {
      void client.invalidateQueries({ queryKey: key });
      toast.success(t('branches.saved'));
      onDone();
    },
    onError: (e) => toast.error(t(`errors.${e instanceof ApiError ? e.code : 'unknown'}`, { defaultValue: t('errors.unknown') })),
  });
}

export function BranchDialog({ item, onClose }: { item: Branch | 'new' | null; onClose: () => void }) {
  const { t } = useTranslation();
  const form = useForm<z.infer<typeof branchSchema>>({ resolver: zodResolver(branchSchema), defaultValues: { code: '', name: '', address: '', phone: '', is_default: false, is_active: true } });
  useEffect(() => {
    if (item === 'new') form.reset({ code: '', name: '', address: '', phone: '', is_default: false, is_active: true });
    else if (item) form.reset({ code: item.code, name: item.name, address: item.address ?? '', phone: item.phone ?? '', is_default: item.is_default, is_active: item.is_active });
  }, [item, form]);
  const save = useSaver<Partial<Branch>>(BRANCHES_KEY, branchesApi.create, branchesApi.update, onClose);
  const serverErr = save.error instanceof ApiError ? save.error.fields : {};
  const err = (k: keyof z.infer<typeof branchSchema>) => (form.formState.errors[k] || serverErr[k] ? t('errors.validation') : undefined);
  return (
    <Modal open={item !== null} onOpenChange={(o) => !o && onClose()} title={item === 'new' ? t('branches.addBranch') : t('branches.editBranch')} footer={<><Button variant="secondary" onClick={onClose}>{t('common.cancel')}</Button><Button type="submit" form="branch-form" loading={save.isPending}>{t('common.save')}</Button></>}>
      <form id="branch-form" onSubmit={form.handleSubmit((v) => save.mutate({ id: item && item !== 'new' ? item.id : null, body: { ...v, address: v.address || null, phone: v.phone || null } }))} className="grid gap-4" noValidate>
        <Input label={t('common.code')} required error={err('code')} {...form.register('code')} disabled={item !== 'new'} />
        <Input label={t('common.name')} required error={err('name')} {...form.register('name')} />
        <Input label={t('branches.address')} {...form.register('address')} />
        <Input label={t('common.phone')} {...form.register('phone')} />
        <Switch label={t('branches.isDefault')} checked={form.watch('is_default')} onChange={(v) => form.setValue('is_default', v)} />
        <Switch label={t('common.active')} checked={form.watch('is_active')} onChange={(v) => form.setValue('is_active', v)} />
      </form>
    </Modal>
  );
}

export function WarehouseDialog({ item, branches, onClose }: { item: Warehouse | 'new' | null; branches: Branch[]; onClose: () => void }) {
  const { t } = useTranslation();
  const form = useForm<z.infer<typeof warehouseSchema>>({ resolver: zodResolver(warehouseSchema), defaultValues: { branch_id: branches[0]?.id ?? 0, code: '', name: '', kind: 'showroom', is_default: false, is_sellable: true, is_active: true } });
  useEffect(() => {
    if (item === 'new') form.reset({ branch_id: branches.find((b) => b.is_default)?.id ?? branches[0]?.id ?? 0, code: '', name: '', kind: 'showroom', is_default: false, is_sellable: true, is_active: true });
    else if (item) form.reset({ branch_id: item.branch_id, code: item.code, name: item.name, kind: item.kind, is_default: item.is_default, is_sellable: item.is_sellable, is_active: item.is_active });
  }, [item, branches, form]);
  const save = useSaver<Partial<Warehouse>>(WAREHOUSES_KEY, warehousesApi.create, warehousesApi.update, onClose);
  const serverErr = save.error instanceof ApiError ? save.error.fields : {};
  const err = (k: keyof z.infer<typeof warehouseSchema>) => (form.formState.errors[k] || serverErr[k] ? t('errors.validation') : undefined);
  return (
    <Modal open={item !== null} onOpenChange={(o) => !o && onClose()} title={item === 'new' ? t('branches.addWarehouse') : t('branches.editWarehouse')} footer={<><Button variant="secondary" onClick={onClose}>{t('common.cancel')}</Button><Button type="submit" form="wh-form" loading={save.isPending}>{t('common.save')}</Button></>}>
      <form id="wh-form" onSubmit={form.handleSubmit((v) => save.mutate({ id: item && item !== 'new' ? item.id : null, body: v }))} className="grid gap-4" noValidate>
        <Select label={t('common.branch')} required value={String(form.watch('branch_id'))} onChange={(v) => form.setValue('branch_id', Number(v))} options={branches.map((b) => ({ value: String(b.id), label: b.name }))} error={err('branch_id')} />
        <Input label={t('common.code')} required error={err('code')} {...form.register('code')} disabled={item !== 'new'} />
        <Input label={t('common.name')} required error={err('name')} {...form.register('name')} />
        <Select label={t('branches.kind')} value={form.watch('kind')} onChange={(v) => form.setValue('kind', v as 'showroom' | 'godown')} options={[{ value: 'showroom', label: t('branches.showroom') }, { value: 'godown', label: t('branches.godown') }]} />
        <Switch label={t('branches.sellable')} checked={form.watch('is_sellable')} onChange={(v) => form.setValue('is_sellable', v)} />
        <Switch label={t('branches.isDefault')} checked={form.watch('is_default')} onChange={(v) => form.setValue('is_default', v)} />
        <Switch label={t('common.active')} checked={form.watch('is_active')} onChange={(v) => form.setValue('is_active', v)} />
      </form>
    </Modal>
  );
}

export function LocationDialog({ item, warehouses, locations, onClose }: { item: StorageLocation | 'new' | null; warehouses: Warehouse[]; locations: StorageLocation[]; onClose: () => void }) {
  const { t } = useTranslation();
  const form = useForm<z.infer<typeof locationSchema>>({ resolver: zodResolver(locationSchema), defaultValues: { warehouse_id: warehouses[0]?.id ?? 0, parent_id: null, code: '', name: '', kind: 'rack', is_active: true } });
  useEffect(() => {
    if (item === 'new') form.reset({ warehouse_id: warehouses.find((w) => w.is_default)?.id ?? warehouses[0]?.id ?? 0, parent_id: null, code: '', name: '', kind: 'rack', is_active: true });
    else if (item) form.reset({ warehouse_id: item.warehouse_id, parent_id: item.parent_id, code: item.code, name: item.name ?? '', kind: item.kind, is_active: item.is_active });
  }, [item, warehouses, form]);
  const save = useSaver<Partial<StorageLocation>>(LOCATIONS_KEY, locationsApi.create, locationsApi.update, onClose);
  const serverErr = save.error instanceof ApiError ? save.error.fields : {};
  const err = (k: keyof z.infer<typeof locationSchema>) => (form.formState.errors[k] || serverErr[k] ? t('errors.validation') : undefined);
  const wid = form.watch('warehouse_id');
  const parents = locations.filter((l) => l.warehouse_id === wid && (item === 'new' || l.id !== item?.id));
  const NONE = '__none__';
  return (
    <Modal open={item !== null} onOpenChange={(o) => !o && onClose()} title={item === 'new' ? t('branches.addLocation') : t('branches.editLocation')} footer={<><Button variant="secondary" onClick={onClose}>{t('common.cancel')}</Button><Button type="submit" form="loc-form" loading={save.isPending}>{t('common.save')}</Button></>}>
      <form id="loc-form" onSubmit={form.handleSubmit((v) => save.mutate({ id: item && item !== 'new' ? item.id : null, body: { ...v, name: v.name || null } }))} className="grid gap-4" noValidate>
        <Select label={t('branches.warehouse')} required value={String(wid)} onChange={(v) => { form.setValue('warehouse_id', Number(v)); form.setValue('parent_id', null); }} options={warehouses.map((w) => ({ value: String(w.id), label: w.name }))} error={err('warehouse_id')} />
        <Select label={t('branches.parent')} value={form.watch('parent_id') === null ? NONE : String(form.watch('parent_id'))} onChange={(v) => form.setValue('parent_id', v === NONE ? null : Number(v))} options={[{ value: NONE, label: t('common.none') }, ...parents.map((l) => ({ value: String(l.id), label: `${l.code}${l.name ? ` · ${l.name}` : ''}` }))]} error={err('parent_id')} />
        <Input label={t('common.code')} required error={err('code')} {...form.register('code')} />
        <Input label={t('common.name')} {...form.register('name')} />
        <Select label={t('branches.kind')} value={form.watch('kind')} onChange={(v) => form.setValue('kind', v as 'rack' | 'shelf' | 'bin')} options={(['rack', 'shelf', 'bin'] as const).map((k) => ({ value: k, label: t(`branches.${k}`) }))} />
        <Switch label={t('common.active')} checked={form.watch('is_active')} onChange={(v) => form.setValue('is_active', v)} />
      </form>
    </Modal>
  );
}
