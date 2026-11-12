import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { str } from './util';
import { customFieldApi, KEYS, lookupApi, masterApi } from './api';
import type { CustomFieldEntity, LookupTypeRow, LookupValueRow, MasterRow } from './types';

/** All lookup lists, cached; the admin edits them rarely. */
export function useLookups() {
  return useQuery({ queryKey: KEYS.lookups, queryFn: lookupApi.list, staleTime: 5 * 60_000 });
}

/** Active values of one lookup list as select options in the current language. */
export function useLookupOptions(typeKey: string): { value: string; label: string }[] {
  const { i18n } = useTranslation();
  const { data } = useLookups();
  const type: LookupTypeRow | undefined = data?.find((t) => t.key === typeKey);
  return (type?.values ?? []).filter((v) => v.is_active).map((v) => ({ value: String(v.id), label: labelOf(v, i18n.language) }));
}

export function labelOf(v: { label_en: string; label_bn?: string | null }, lang: string): string {
  return lang === 'bn' && v.label_bn ? v.label_bn : v.label_en;
}

export function lookupLabel(types: LookupTypeRow[] | undefined, typeKey: string, id: number | null | undefined, lang: string): string {
  if (!id) return '';
  const v: LookupValueRow | undefined = types?.find((t) => t.key === typeKey)?.values.find((x) => x.id === id);
  return v ? labelOf(v, lang) : '';
}

/** A simple master list (makes, brands, categories, units, tax rates), cached. */
export function useMaster(resource: string) {
  return useQuery({ queryKey: KEYS.master(resource), queryFn: () => masterApi.list(resource), staleTime: 5 * 60_000 });
}

export function useMasterOptions(resource: string, labelKey: string, labelKeyBn?: string): { value: string; label: string }[] {
  const { i18n } = useTranslation();
  const { data } = useMaster(resource);
  return (data ?? [])
    .filter((r: MasterRow) => r.is_active)
    .map((r) => ({ value: String(r.id), label: (i18n.language === 'bn' && labelKeyBn ? str(r[labelKeyBn]) : '') || str(r[labelKey]) }));
}

export function useCustomFields(entity: CustomFieldEntity) {
  return useQuery({ queryKey: [...KEYS.customFields, entity], queryFn: () => customFieldApi.list(entity), staleTime: 5 * 60_000 });
}
