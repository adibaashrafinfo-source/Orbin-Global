import { useQuery } from '@tanstack/react-query';
import { STOCK_KEYS, supplierApi, warehouseApi } from './api';

export function useWarehouses() {
  return useQuery({ queryKey: STOCK_KEYS.warehouses, queryFn: warehouseApi.list, staleTime: 5 * 60_000 });
}

/** Active suppliers for pickers (one page is plenty for a shop's supplier list). */
export function useSuppliers() {
  return useQuery({ queryKey: [...STOCK_KEYS.suppliers, 'all'], queryFn: () => supplierApi.list({ per_page: 100, 'filter[is_active]': 1 }).then((r) => r.data), staleTime: 60_000 });
}
