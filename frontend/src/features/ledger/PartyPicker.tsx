import { useQuery } from '@tanstack/react-query';
import { X } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useDebouncedValue } from '@/app/useDebouncedValue';
import { Button, EmptyState, Input, Skeleton } from '@/design/components';
import { customerApi, SALES_KEYS } from '@/features/sales/api';
import { STOCK_KEYS, supplierApi } from '@/features/stock/api';
import { formatMoney } from '@/lib/money';
import type { PartyType } from './types';

export interface PickedParty {
  type: PartyType;
  id: number;
  code: string;
  name: string;
  balance: string;
}

/** Find a customer or supplier by name, phone or code. A picked party shows as a card with a Change button. */
export function PartyPicker({ type, value, onChange }: { type: PartyType; value: PickedParty | null; onChange: (p: PickedParty | null) => void }) {
  const { t } = useTranslation();
  const [search, setSearch] = useState('');
  const q = useDebouncedValue(search, 200);
  const list = useQuery({
    queryKey: [type === 'customer' ? SALES_KEYS.customers : STOCK_KEYS.suppliers, 'pick', q],
    queryFn: async (): Promise<PickedParty[]> => {
      if (type === 'customer') return (await customerApi.list({ search: q, per_page: 8, 'filter[is_active]': 1 })).data.filter((c) => !c.is_walk_in).map((c) => ({ type, id: c.id, code: c.code, name: c.name, balance: c.balance }));
      return (await supplierApi.list({ search: q, per_page: 8, 'filter[is_active]': 1 })).data.map((s) => ({ type, id: s.id, code: s.code, name: s.name, balance: s.balance }));
    },
    enabled: value === null,
    placeholderData: (p) => p,
  });
  if (value) {
    return (
      <div className="flex items-center justify-between gap-3 rounded-card border border-navy-100 bg-navy-50 px-4 py-3" data-testid="picked-party">
        <div><div className="font-semibold text-navy-900">{value.name}</div><div className="text-xs text-ink-muted">{value.code} · {t(type === 'customer' ? 'ledger.owesUs' : 'ledger.weOwe', { amount: formatMoney(value.balance) })}</div></div>
        <Button variant="ghost" size="sm" leftIcon={<X className="h-4 w-4" aria-hidden="true" />} onClick={() => { setSearch(''); onChange(null); }}>{t('ledger.changeParty')}</Button>
      </div>
    );
  }
  const rows = list.data ?? [];
  return (
    <div className="flex flex-col gap-2">
      <Input type="search" label={t(type === 'customer' ? 'ledger.findCustomer' : 'ledger.findSupplier')} value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('ledger.findHint')} />
      {list.isLoading ? <Skeleton className="h-20" /> : rows.length === 0 ? <EmptyState body={t('ledger.noParties')} /> : (
        <ul className="divide-y divide-navy-100 rounded-card border border-navy-100" aria-label={t(type === 'customer' ? 'nav.customers' : 'purchasing.tabs.suppliers')}>
          {rows.map((p) => (
            <li key={p.id}><button type="button" onClick={() => onChange(p)} className="flex w-full items-center justify-between gap-3 px-3 py-2.5 text-start hover:bg-navy-50 focus-visible:focus-ring-light">
              <span><span className="block font-medium text-navy-900">{p.name}</span><span className="text-xs text-ink-muted">{p.code}</span></span>
              <span className="tabular text-sm font-semibold text-navy-900">{Number(p.balance) !== 0 ? formatMoney(p.balance) : '—'}</span>
            </button></li>
          ))}
        </ul>
      )}
    </div>
  );
}
