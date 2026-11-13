import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useDebouncedValue } from '@/app/useDebouncedValue';
import { Input } from '@/design/components';
import { STOCK_KEYS, stockApi } from '@/features/stock/api';

/** Pick one SKU for the stock card and the price trend: type part of a SKU or a name, choose from the list. */
export function VariantPicker({ value, onChange }: { value: string; onChange: (id: string) => void }) {
  const { t } = useTranslation();
  const [text, setText] = useState('');
  const [chosen, setChosen] = useState('');
  const q = useDebouncedValue(text, 200);
  const list = useQuery({ queryKey: [...STOCK_KEYS.inventory, 'pick-variant', q], queryFn: () => stockApi.overview({ page: 1, per_page: 6, search: q }), enabled: q.trim().length >= 2 && value === '' });
  const rows = list.data?.data ?? [];
  if (value !== '') {
    return <div className="flex h-10 items-center justify-between gap-2 rounded-input border border-navy-200 bg-navy-50 px-3 text-sm"><span className="truncate font-medium text-navy-900">{chosen || value}</span><button type="button" className="text-xs font-semibold text-navy-800 hover:underline focus-visible:focus-ring-light" onClick={() => { setText(''); setChosen(''); onChange(''); }}>{t('ledger.changeParty')}</button></div>;
  }

  return (
    <div className="relative">
      <Input type="search" label={t('reports.filters.variant')} value={text} onChange={(e) => setText(e.target.value)} placeholder={t('reports.filters.variantHint')} />
      {rows.length > 0 && (
        <ul className="absolute z-20 mt-1 w-full divide-y divide-navy-100 rounded-card border border-navy-100 bg-surface shadow-md" aria-label={t('reports.filters.variant')}>
          {rows.map((r) => <li key={r.variant_id}><button type="button" className="block w-full px-3 py-2 text-start text-sm hover:bg-navy-50 focus-visible:focus-ring-light" onClick={() => { setChosen(`${r.sku} · ${r.product_name}`); onChange(String(r.variant_id)); }}><span className="font-semibold">{r.sku}</span> <span className="text-ink-muted">{r.product_name}</span></button></li>)}
        </ul>
      )}
    </div>
  );
}
