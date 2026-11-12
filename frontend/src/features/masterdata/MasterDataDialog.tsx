import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Button, Input, Modal, Select, Switch, toast } from '@/design/components';
import { masterApi } from '@/features/catalog/api';
import { useLookupOptions, useMaster } from '@/features/catalog/hooks';
import type { MasterRow } from '@/features/catalog/types';
import { fieldMessage, str, useDerivedState } from '@/features/catalog/util';
import { ApiError } from '@/lib/api';
import { RESOURCES, type FieldDef, type ResourceName } from './config';

type Values = Record<string, string | boolean>;
const NONE = '__none__';

function initial(name: ResourceName, row: MasterRow | 'new', presetMake: string): Values {
  const out: Values = {};
  for (const f of RESOURCES[name].fields) {
    const raw = row === 'new' ? undefined : row[f.key];
    if (f.kind === 'switch') out[f.key] = raw === undefined ? false : Boolean(raw);
    else if (raw === undefined || raw === null) out[f.key] = f.kind === 'make' ? presetMake : f.kind === 'lookup' || f.kind === 'parent' ? NONE : '';
    else out[f.key] = str(raw);
  }
  out.is_active = row === 'new' ? true : Boolean(row.is_active);
  return out;
}

function payload(name: ResourceName, v: Values): Record<string, unknown> {
  const body: Record<string, unknown> = { is_active: v.is_active };
  for (const f of RESOURCES[name].fields) {
    const raw = v[f.key];
    if (f.kind === 'switch') body[f.key] = raw;
    else if (f.kind === 'integer' || f.kind === 'make') body[f.key] = raw === '' || raw === NONE ? null : Number(raw);
    else if (f.kind === 'lookup' || f.kind === 'parent') body[f.key] = raw === NONE || raw === '' ? null : Number(raw);
    else if (f.kind === 'decimal') body[f.key] = raw === '' ? null : String(raw);
    else body[f.key] = String(raw).trim() === '' && !f.required ? null : String(raw).trim();
  }
  return body;
}

interface Props {
  resource: ResourceName;
  row: MasterRow | 'new' | null;
  /** Make pre-selected when adding a model while a make filter is active. */
  presetMake?: string;
  onClose: () => void;
}

/** One dialog for all simple master lists, driven by config.ts. Server validation errors are shown per field. */
export function MasterDataDialog({ resource, row, presetMake = '', onClose }: Props) {
  const { t, i18n } = useTranslation();
  const client = useQueryClient();
  const def = RESOURCES[resource];
  const [values, setValues] = useDerivedState<Values>(`${resource}:${row === 'new' || row === null ? String(row) : row.id}`, () => (row ? initial(resource, row, presetMake) : {}));
  const makes = useMaster('vehicle-makes');
  const categories = useMaster('categories');
  const bodyTypes = useLookupOptions('body_type');
  const countries = useLookupOptions('origin_country');

  const save = useMutation({
    mutationFn: () => {
      const body = payload(resource, values);
      return row && row !== 'new' ? masterApi.update(resource, row.id, body) : masterApi.create(resource, body);
    },
    onSuccess: () => {
      void client.invalidateQueries({ queryKey: ['master'] });
      toast.success(t('masterData.saved'));
      onClose();
    },
    onError: (e) => {
      if (!(e instanceof ApiError && e.code === 'VALIDATION')) toast.error(t(`errors.${e instanceof ApiError ? e.code : 'unknown'}`, { defaultValue: t('errors.unknown') }));
    },
  });

  const [submitted, setSubmitted] = useDerivedState<boolean>(row, () => false);
  const fieldErrors = save.error instanceof ApiError ? save.error.fields : {};
  const set = (key: string, v: string | boolean) => setValues((s) => ({ ...s, [key]: v }));
  const err = (f: FieldDef): string | undefined => {
    const msg = fieldMessage(t, fieldErrors[f.key]?.[0]);
    if (msg) return msg;
    if (f.required && submitted && String(values[f.key] ?? '').trim() === '') return t('common.required');
    return undefined;
  };

  const lang = i18n.language;
  const categoryOptions = [
    { value: NONE, label: t('masterData.noParent') },
    ...(categories.data ?? [])
      .filter((c) => (row === 'new' || !row ? true : c.id !== row.id))
      .map((c) => ({ value: String(c.id), label: String((lang === 'bn' && c.name_bn) || c.name_en) })),
  ];
  const optional = (opts: { value: string; label: string }[]) => [{ value: NONE, label: t('common.none') }, ...opts];

  const control = (f: FieldDef) => {
    const label = t(`masterData.fields.${f.label}`);
    const locked = f.lockedOnEdit === true && row !== 'new';
    const value = String(values[f.key] ?? '');
    switch (f.kind) {
      case 'switch':
        return <Switch key={f.key} label={label} checked={Boolean(values[f.key])} onChange={(v) => set(f.key, v)} />;
      case 'make':
        return <Select key={f.key} label={label} required={f.required} value={value || undefined} onChange={(v) => set(f.key, v)} error={err(f)} options={(makes.data ?? []).filter((m) => m.is_active || String(m.id) === value).map((m) => ({ value: String(m.id), label: String(m.name) }))} />;
      case 'parent':
        return <Select key={f.key} label={label} value={value || NONE} onChange={(v) => set(f.key, v)} error={err(f)} options={categoryOptions} />;
      case 'lookup':
        return <Select key={f.key} label={label} value={value || NONE} onChange={(v) => set(f.key, v)} error={err(f)} options={optional(f.lookup === 'origin_country' ? countries : bodyTypes)} />;
      case 'integer':
        return <Input key={f.key} label={label} type="number" inputMode="numeric" value={value} onChange={(e) => set(f.key, e.target.value)} error={err(f)} />;
      case 'decimal':
        return <Input key={f.key} label={label} type="number" inputMode="decimal" step="0.01" min="0" max="100" required={f.required} value={value} onChange={(e) => set(f.key, e.target.value)} error={err(f)} suffix={<span className="text-xs text-ink-muted">%</span>} />;
      default:
        return <Input key={f.key} label={label} required={f.required} maxLength={f.maxLength} disabled={locked} value={value} onChange={(e) => set(f.key, e.target.value)} error={err(f)} />;
    }
  };

  const title = row === 'new' ? t('masterData.addTitle', { item: t(`masterData.singular.${resource}`) }) : t('masterData.editTitle', { item: t(`masterData.singular.${resource}`) });
  return (
    <Modal
      open={row !== null}
      onOpenChange={(o) => !o && onClose()}
      title={title}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
          <Button type="submit" form="master-form" loading={save.isPending}>{t('common.save')}</Button>
        </>
      }
    >
      <form
        id="master-form"
        noValidate
        className="grid gap-4"
        onSubmit={(e) => {
          e.preventDefault();
          setSubmitted(true);
          if (def.fields.some((f) => f.required && String(values[f.key] ?? '').trim() === '')) return;
          save.mutate();
        }}
      >
        {def.fields.map(control)}
        {def.hasActiveSwitch && <Switch label={t('common.active')} checked={Boolean(values.is_active)} onChange={(v) => set('is_active', v)} />}
      </form>
    </Modal>
  );
}
