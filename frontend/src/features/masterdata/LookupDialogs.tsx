import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Button, Input, Modal, Switch, toast } from '@/design/components';
import { KEYS, lookupApi } from '@/features/catalog/api';
import type { LookupTypeRow, LookupValueRow } from '@/features/catalog/types';
import { fieldMessage, useDerivedState } from '@/features/catalog/util';
import { ApiError } from '@/lib/api';

function useFieldErrors(error: unknown) {
  const { t } = useTranslation();
  const fields = error instanceof ApiError ? error.fields : {};
  return (k: string): string | undefined => fieldMessage(t, fields[k]?.[0]);
}

function useToastError() {
  const { t } = useTranslation();
  return (e: unknown) => {
    if (e instanceof ApiError && e.code === 'VALIDATION') return;
    toast.error(t(`errors.${e instanceof ApiError ? e.code : 'unknown'}`, { defaultValue: t('errors.unknown') }));
  };
}

/** New lookup list (the key is permanent; system lists are seeded and cannot be deleted). */
export function LookupTypeDialog({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: (t: LookupTypeRow) => void }) {
  const { t } = useTranslation();
  const client = useQueryClient();
  const [form, setForm] = useDerivedState(open, () => ({ key: '', name_en: '', name_bn: '' }));
  const onError = useToastError();
  const save = useMutation({
    mutationFn: () => lookupApi.createType({ key: form.key.trim(), name_en: form.name_en.trim(), name_bn: form.name_bn.trim() || null }),
    onSuccess: (row) => {
      void client.invalidateQueries({ queryKey: KEYS.lookups });
      toast.success(t('masterData.saved'));
      onCreated(row);
      onClose();
    },
    onError,
  });
  const err = useFieldErrors(save.error);
  return (
    <Modal open={open} onOpenChange={(o) => !o && onClose()} title={t('masterData.addList')} footer={<><Button variant="secondary" onClick={onClose}>{t('common.cancel')}</Button><Button type="submit" form="lookup-type-form" loading={save.isPending}>{t('common.save')}</Button></>}>
      <form id="lookup-type-form" noValidate className="grid gap-4" onSubmit={(e) => { e.preventDefault(); save.mutate(); }}>
        <Input label={t('masterData.fields.key')} required hint={t('masterData.keyHint')} value={form.key} onChange={(e) => setForm({ ...form, key: e.target.value })} error={err('key')} />
        <Input label={t('masterData.fields.nameEn')} required value={form.name_en} onChange={(e) => setForm({ ...form, name_en: e.target.value })} error={err('name_en')} />
        <Input label={t('masterData.fields.nameBn')} value={form.name_bn} onChange={(e) => setForm({ ...form, name_bn: e.target.value })} error={err('name_bn')} />
      </form>
    </Modal>
  );
}

/** Add or edit one value of a lookup list. The code stays fixed after creation. */
export function LookupValueDialog({ type, value, onClose }: { type: LookupTypeRow | null; value: LookupValueRow | 'new' | null; onClose: () => void }) {
  const { t } = useTranslation();
  const client = useQueryClient();
  const [form, setForm] = useDerivedState(value, () => (value && value !== 'new' ? { code: value.code, label_en: value.label_en, label_bn: value.label_bn ?? '', is_active: value.is_active } : { code: '', label_en: '', label_bn: '', is_active: true }));
  const onError = useToastError();
  const save = useMutation({
    mutationFn: () => {
      const body = { label_en: form.label_en.trim(), label_bn: form.label_bn.trim() || null, is_active: form.is_active };
      if (value && value !== 'new') return lookupApi.updateValue(value.id, body);
      return lookupApi.createValue(type!.id, { ...body, code: form.code.trim() });
    },
    onSuccess: () => {
      void client.invalidateQueries({ queryKey: KEYS.lookups });
      toast.success(t('masterData.saved'));
      onClose();
    },
    onError,
  });
  const err = useFieldErrors(save.error);
  return (
    <Modal open={value !== null && type !== null} onOpenChange={(o) => !o && onClose()} title={value === 'new' ? t('masterData.addValue') : t('masterData.editValue')} footer={<><Button variant="secondary" onClick={onClose}>{t('common.cancel')}</Button><Button type="submit" form="lookup-value-form" loading={save.isPending}>{t('common.save')}</Button></>}>
      <form id="lookup-value-form" noValidate className="grid gap-4" onSubmit={(e) => { e.preventDefault(); save.mutate(); }}>
        <Input label={t('common.code')} required hint={t('masterData.keyHint')} disabled={value !== 'new'} value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} error={err('code')} />
        <Input label={t('masterData.fields.nameEn')} required value={form.label_en} onChange={(e) => setForm({ ...form, label_en: e.target.value })} error={err('label_en')} />
        <Input label={t('masterData.fields.nameBn')} value={form.label_bn} onChange={(e) => setForm({ ...form, label_bn: e.target.value })} error={err('label_bn')} />
        <Switch label={t('common.active')} checked={form.is_active} onChange={(v) => setForm({ ...form, is_active: v })} />
      </form>
    </Modal>
  );
}
