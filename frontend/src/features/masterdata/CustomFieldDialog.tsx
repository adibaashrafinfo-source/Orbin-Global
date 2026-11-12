import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Plus, X } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Button, IconButton, Input, Modal, Select, Switch, toast } from '@/design/components';
import { customFieldApi, KEYS } from '@/features/catalog/api';
import { useLookups } from '@/features/catalog/hooks';
import { CUSTOM_FIELD_TYPES, type CustomFieldDefRow, type CustomFieldEntity, type CustomFieldType } from '@/features/catalog/types';
import { fieldMessage, useDerivedState } from '@/features/catalog/util';
import { ApiError } from '@/lib/api';

interface Opt { value: string; label_en: string; label_bn: string }
interface Form {
  key: string; label_en: string; label_bn: string; field_type: CustomFieldType;
  mode: 'own' | 'lookup'; lookup: string; options: Opt[];
  is_required: boolean; show_on_pos_card: boolean; show_on_invoice: boolean; is_searchable: boolean; is_active: boolean;
}

const blank: Form = { key: '', label_en: '', label_bn: '', field_type: 'text', mode: 'own', lookup: '', options: [{ value: '', label_en: '', label_bn: '' }], is_required: false, show_on_pos_card: false, show_on_invoice: false, is_searchable: false, is_active: true };

function fromRow(r: CustomFieldDefRow): Form {
  const o = r.options;
  const isLookup = !!o && !Array.isArray(o) && 'lookup' in o;
  return {
    ...blank, key: r.key, label_en: r.label_en, label_bn: r.label_bn ?? '', field_type: r.field_type,
    mode: isLookup ? 'lookup' : 'own', lookup: isLookup ? o.lookup : '',
    options: Array.isArray(o) && o.length ? o.map((x) => ({ value: x.value, label_en: x.label_en, label_bn: x.label_bn ?? '' })) : blank.options,
    is_required: r.is_required, show_on_pos_card: r.show_on_pos_card, show_on_invoice: r.show_on_invoice, is_searchable: r.is_searchable, is_active: r.is_active,
  };
}

/** Create or edit a custom field. Key, entity and (once in use) type are fixed after creation by the API. */
export function CustomFieldDialog({ entity, row, onClose }: { entity: CustomFieldEntity; row: CustomFieldDefRow | 'new' | null; onClose: () => void }) {
  const { t } = useTranslation();
  const client = useQueryClient();
  const lookups = useLookups();
  const [f, setF] = useDerivedState<Form>(row, () => (row && row !== 'new' ? fromRow(row) : blank));
  const isSelect = f.field_type === 'select' || f.field_type === 'multi_select';
  const set = <K extends keyof Form>(k: K, v: Form[K]) => setF((s) => ({ ...s, [k]: v }));
  const setOpt = (i: number, patch: Partial<Opt>) => set('options', f.options.map((o, n) => (n === i ? { ...o, ...patch } : o)));

  const save = useMutation({
    mutationFn: () => {
      const options = !isSelect ? null : f.mode === 'lookup' ? { lookup: f.lookup } : f.options.filter((o) => o.value.trim() && o.label_en.trim()).map((o) => ({ value: o.value.trim(), label_en: o.label_en.trim(), ...(o.label_bn.trim() ? { label_bn: o.label_bn.trim() } : {}) }));
      const body: Partial<CustomFieldDefRow> = { label_en: f.label_en.trim(), label_bn: f.label_bn.trim() || null, field_type: f.field_type, options, is_required: f.is_required, show_on_pos_card: f.show_on_pos_card, show_on_invoice: f.show_on_invoice, is_searchable: f.is_searchable, is_active: f.is_active };
      return row && row !== 'new' ? customFieldApi.update(row.id, body) : customFieldApi.create({ ...body, entity, key: f.key.trim() });
    },
    onSuccess: () => {
      void client.invalidateQueries({ queryKey: KEYS.customFields });
      toast.success(t('masterData.saved'));
      onClose();
    },
    onError: (e) => {
      if (!(e instanceof ApiError && e.code === 'VALIDATION')) toast.error(t(`errors.${e instanceof ApiError ? e.code : 'unknown'}`, { defaultValue: t('errors.unknown') }));
    },
  });
  const fields = save.error instanceof ApiError ? save.error.fields : {};
  const err = (k: string) => fieldMessage(t, fields[k]?.[0]);

  return (
    <Modal open={row !== null} onOpenChange={(o) => !o && onClose()} className="max-w-xl" title={row === 'new' ? t('masterData.addField') : t('masterData.editField')} footer={<><Button variant="secondary" onClick={onClose}>{t('common.cancel')}</Button><Button type="submit" form="cf-form" loading={save.isPending}>{t('common.save')}</Button></>}>
      <form id="cf-form" noValidate className="grid gap-4" onSubmit={(e) => { e.preventDefault(); save.mutate(); }}>
        <Input label={t('masterData.fields.key')} required hint={t('masterData.keyHint')} disabled={row !== 'new'} value={f.key} onChange={(e) => set('key', e.target.value)} error={err('key')} />
        <div className="grid gap-4 sm:grid-cols-2">
          <Input label={t('masterData.fields.nameEn')} required value={f.label_en} onChange={(e) => set('label_en', e.target.value)} error={err('label_en')} />
          <Input label={t('masterData.fields.nameBn')} value={f.label_bn} onChange={(e) => set('label_bn', e.target.value)} error={err('label_bn')} />
        </div>
        <Select label={t('masterData.fields.fieldType')} value={f.field_type} onChange={(v) => set('field_type', v as CustomFieldType)} error={err('field_type')} options={CUSTOM_FIELD_TYPES.map((x) => ({ value: x, label: t(`masterData.fieldTypes.${x}`) }))} />
        {isSelect && (
          <fieldset className="grid gap-3 rounded-card border border-navy-100 p-3">
            <legend className="px-1 text-xs font-semibold text-ink-muted">{t('masterData.fields.options')}</legend>
            <Select value={f.mode} onChange={(v) => set('mode', v as 'own' | 'lookup')} options={[{ value: 'own', label: t('masterData.optionsOwn') }, { value: 'lookup', label: t('masterData.optionsLookup') }]} />
            {f.mode === 'lookup' ? (
              <Select label={t('masterData.lookups')} value={f.lookup || undefined} onChange={(v) => set('lookup', v)} error={err('options')} options={(lookups.data ?? []).map((x) => ({ value: x.key, label: x.name_en }))} />
            ) : (
              <>
                {f.options.map((o, i) => (
                  <div key={i} className="grid grid-cols-[1fr_1fr_1fr_auto] items-end gap-2">
                    <Input label={i === 0 ? t('common.code') : undefined} aria-label={t('common.code')} value={o.value} onChange={(e) => setOpt(i, { value: e.target.value })} />
                    <Input label={i === 0 ? t('masterData.fields.nameEn') : undefined} aria-label={t('masterData.fields.nameEn')} value={o.label_en} onChange={(e) => setOpt(i, { label_en: e.target.value })} />
                    <Input label={i === 0 ? t('masterData.fields.nameBn') : undefined} aria-label={t('masterData.fields.nameBn')} value={o.label_bn} onChange={(e) => setOpt(i, { label_bn: e.target.value })} />
                    <IconButton label={t('common.remove')} disabled={f.options.length === 1} onClick={() => set('options', f.options.filter((_, n) => n !== i))}><X className="h-4 w-4" aria-hidden="true" /></IconButton>
                  </div>
                ))}
                {err('options') && <p role="alert" className="text-xs text-danger">{err('options')}</p>}
                <div><Button size="sm" variant="secondary" leftIcon={<Plus className="h-4 w-4" aria-hidden="true" />} onClick={() => set('options', [...f.options, { value: '', label_en: '', label_bn: '' }])}>{t('masterData.addOption')}</Button></div>
              </>
            )}
          </fieldset>
        )}
        <Switch label={t('masterData.fields.isRequired')} description={t('masterData.requiredHint')} checked={f.is_required} onChange={(v) => set('is_required', v)} />
        <Switch label={t('masterData.fields.showOnPos')} checked={f.show_on_pos_card} onChange={(v) => set('show_on_pos_card', v)} />
        <Switch label={t('masterData.fields.showOnInvoice')} checked={f.show_on_invoice} onChange={(v) => set('show_on_invoice', v)} />
        <Switch label={t('masterData.fields.isSearchable')} checked={f.is_searchable} onChange={(v) => set('is_searchable', v)} />
        <Switch label={t('common.active')} checked={f.is_active} onChange={(v) => set('is_active', v)} />
      </form>
    </Modal>
  );
}
