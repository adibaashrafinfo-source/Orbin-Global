import { useTranslation } from 'react-i18next';
import { Checkbox, Input, Select, Switch, Textarea } from '@/design/components';
import type { CustomFieldDefRow } from '../types';
import { useFieldOptions } from './customFieldUtils';


interface FieldProps {
  def: CustomFieldDefRow;
  value: unknown;
  error?: string | undefined;
  disabled?: boolean;
  onChange: (v: unknown) => void;
}

const NONE = '__none__';

/** One input for one custom field, chosen by its type. */
export function CustomFieldInput({ def, value, error, disabled, onChange }: FieldProps) {
  const { i18n, t } = useTranslation();
  const options = useFieldOptions(def);
  const label = i18n.language === 'bn' && def.label_bn ? def.label_bn : def.label_en;
  const text = typeof value === 'string' || typeof value === 'number' ? String(value) : '';
  switch (def.field_type) {
    case 'long_text':
      return <Textarea label={label} required={def.is_required} rows={3} disabled={disabled} value={text} onChange={(e) => onChange(e.target.value)} error={error} />;
    case 'boolean':
      return <Switch label={label} checked={value === true} disabled={disabled} onChange={onChange} />;
    case 'select':
      return <Select label={label} required={def.is_required} disabled={disabled} value={text || NONE} onChange={(v) => onChange(v === NONE ? '' : v)} error={error} options={[{ value: NONE, label: t('common.none') }, ...options]} />;
    case 'multi_select': {
      const chosen = Array.isArray(value) ? (value as string[]) : [];
      return (
        <fieldset className="grid gap-2">
          <legend className="mb-1 text-sm font-medium text-ink">{label}{def.is_required && <span className="text-danger"> *</span>}</legend>
          <div className="flex flex-wrap gap-4">{options.map((o) => <Checkbox key={o.value} checked={chosen.includes(o.value)} disabled={disabled} onChange={(c) => onChange(c ? [...chosen, o.value] : chosen.filter((x) => x !== o.value))} label={o.label} />)}</div>
          {error && <p role="alert" className="text-xs text-danger">{error}</p>}
        </fieldset>
      );
    }
    default:
      return <Input label={label} required={def.is_required} disabled={disabled} type={def.field_type === 'number' ? 'number' : def.field_type === 'date' ? 'date' : def.field_type === 'url' ? 'url' : 'text'} value={text} onChange={(e) => onChange(e.target.value)} error={error} />;
  }
}

