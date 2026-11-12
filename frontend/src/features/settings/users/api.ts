import { http, type Envelope, type Paginated } from '@/lib/api';
import type { AuthUser, Role } from '@/lib/permissions';

export type User = AuthUser & { created_at: string | null };

export interface UserInput {
  full_name: string;
  email: string;
  phone: string | null;
  role: Role;
  default_branch_id: number | null;
  branch_ids: number[] | null;
  language: 'en' | 'bn';
  password?: string;
  must_change_password: boolean;
  is_active: boolean;
}

export const USERS_KEY = ['users'] as const;

export const usersApi = {
  list: (params: { page: number; per_page: number; search?: string; role?: string; is_active?: boolean }) =>
    http
      .get<Paginated<User>>('/users', {
        params: { page: params.page, per_page: params.per_page, search: params.search || undefined, 'filter[role]': params.role || undefined, 'filter[is_active]': params.is_active === undefined ? undefined : params.is_active ? 1 : 0 },
      })
      .then((r) => r.data),
  create: (body: UserInput) => http.post<Envelope<User>>('/users', body).then((r) => r.data.data),
  update: (id: number, body: Partial<UserInput>) => http.put<Envelope<User>>(`/users/${id}`, body).then((r) => r.data.data),
  resetPassword: (id: number, password: string) => http.post<Envelope<User>>(`/users/${id}/reset-password`, { password, must_change_password: true }).then((r) => r.data.data),
  setPin: (id: number, pin: string | null) => http.post<Envelope<User>>(`/users/${id}/pin`, { pin }).then((r) => r.data.data),
};
