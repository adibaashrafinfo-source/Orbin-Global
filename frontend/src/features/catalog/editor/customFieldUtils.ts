import { useTranslation } from 'react-i18next';
import { labelOf, useLookups } from '../hooks';
import type { CustomFieldDefRow } from '../types';

export type CustomValues = Record<string, unknown>;

/** Options of a select field: its own list, or the active values of a lookup list (stored by code). */
export function useFieldOptions(def: CustomFieldDefRow): { value: string; label: string }[] {
  const { i18n } = useTranslation();
  const lookups = useLookups();
  const o = def.options;
  if (!o) return [];
  if (!Array.isArray(o)) return (lookups.data?.find((x) => x.key === o.lookup)?.values ?? []).filter((v) => v.is_active).map((v) => ({ value: v.code, label: labelOf(v, i18n.language) }));
  return o.map((x) => ({ value: x.value, label: i18n.language === 'bn' && x.label_bn ? x.label_bn : x.label_en }));
}

/** Drop empty values so the API treats them as "not set" (required fields are enforced at publish time). */
export function cleanCustom(values: CustomValues): CustomValues {
  return Object.fromEntries(Object.entries(values).filter(([, v]) => v !== '' && v !== null && v !== undefined && !(Array.isArray(v) && v.length === 0)));
}
