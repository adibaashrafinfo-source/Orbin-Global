import { http, type Envelope } from '@/lib/api';

export interface Branch {
  id: number;
  code: string;
  name: string;
  address: string | null;
  phone: string | null;
  is_default: boolean;
  is_active: boolean;
  warehouses_count?: number;
}
export interface Warehouse {
  id: number;
  branch_id: number;
  code: string;
  name: string;
  kind: 'showroom' | 'godown';
  is_default: boolean;
  is_sellable: boolean;
  is_active: boolean;
  locations_count?: number;
}
export interface StorageLocation {
  id: number;
  warehouse_id: number;
  parent_id: number | null;
  code: string;
  name: string | null;
  kind: 'rack' | 'shelf' | 'bin';
  is_active: boolean;
}

export const BRANCHES_KEY = ['branches'] as const;
export const WAREHOUSES_KEY = ['warehouses'] as const;
export const LOCATIONS_KEY = ['storage-locations'] as const;

export const branchesApi = {
  list: () => http.get<Envelope<Branch[]>>('/branches').then((r) => r.data.data),
  create: (body: Partial<Branch>) => http.post<Envelope<Branch>>('/branches', body).then((r) => r.data.data),
  update: (id: number, body: Partial<Branch>) => http.put<Envelope<Branch>>(`/branches/${id}`, body).then((r) => r.data.data),
};
export const warehousesApi = {
  list: () => http.get<Envelope<Warehouse[]>>('/warehouses').then((r) => r.data.data),
  create: (body: Partial<Warehouse>) => http.post<Envelope<Warehouse>>('/warehouses', body).then((r) => r.data.data),
  update: (id: number, body: Partial<Warehouse>) => http.put<Envelope<Warehouse>>(`/warehouses/${id}`, body).then((r) => r.data.data),
};
export const locationsApi = {
  list: () => http.get<Envelope<StorageLocation[]>>('/storage-locations').then((r) => r.data.data),
  create: (body: Partial<StorageLocation>) => http.post<Envelope<StorageLocation>>('/storage-locations', body).then((r) => r.data.data),
  update: (id: number, body: Partial<StorageLocation>) => http.put<Envelope<StorageLocation>>(`/storage-locations/${id}`, body).then((r) => r.data.data),
};
