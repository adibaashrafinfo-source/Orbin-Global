import { http, type Envelope } from '@/lib/api';
import type { Role } from '@/lib/permissions';

export interface RoleMatrix {
  roles: Role[];
  keys: string[];
  matrix: Record<Role, Record<string, boolean>>;
  defaults: Record<Role, Record<string, boolean>>;
}

export const ROLES_KEY = ['role-permissions'] as const;

export const rolesApi = {
  matrix: () => http.get<Envelope<RoleMatrix>>('/role-permissions').then((r) => r.data.data),
  update: (role: Role, permissions: Record<string, boolean>) => http.put<Envelope<RoleMatrix>>(`/role-permissions/${role}`, { permissions }).then((r) => r.data.data),
  reset: (role: Role) => http.post<Envelope<RoleMatrix>>(`/role-permissions/${role}/reset`).then((r) => r.data.data),
};
