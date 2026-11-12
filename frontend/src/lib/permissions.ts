export type Role = 'super_admin' | 'manager' | 'cashier' | 'storekeeper' | 'accountant';

export const ROLES: Role[] = ['super_admin', 'manager', 'cashier', 'storekeeper', 'accountant'];

export interface AuthUser {
  id: number;
  full_name: string;
  email: string;
  phone: string | null;
  role: Role;
  default_branch_id: number | null;
  branch_ids: number[] | null;
  avatar_url: string | null;
  language: 'en' | 'bn';
  is_active: boolean;
  must_change_password: boolean;
  has_override_pin: boolean;
  last_login_at: string | null;
}

export interface Session {
  user: AuthUser;
  permissions: string[];
}

/** super_admin holds "*" from the server; all other roles get explicit keys. */
export function can(session: Session | null | undefined, permission: string | string[]): boolean {
  if (!session) return false;
  const perms = session.permissions;
  if (perms.includes('*')) return true;
  const needed = Array.isArray(permission) ? permission : [permission];
  return needed.some((p) => perms.includes(p));
}
