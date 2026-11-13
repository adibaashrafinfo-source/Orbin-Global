import { useQuery } from '@tanstack/react-query';
import { categoryApi, LEDGER_KEYS } from './api';

export function useCategories() {
  return useQuery({ queryKey: LEDGER_KEYS.categories, queryFn: categoryApi.list, staleTime: 60_000 });
}

/** The label of a category in the current language, falling back to English. */
export const categoryName = (c: { name_en: string; name_bn: string | null }, lang: string): string => (lang === 'bn' && c.name_bn ? c.name_bn : c.name_en);
