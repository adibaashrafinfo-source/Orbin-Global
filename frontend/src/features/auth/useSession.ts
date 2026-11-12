import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect } from 'react';
import { ApiError } from '@/lib/api';
import { can } from '@/lib/permissions';
import { setLanguage } from '@/lib/i18n';
import { authApi, type SessionPayload } from './api';

export const SESSION_KEY = ['auth', 'me'] as const;

/** Server-backed session (TanStack Query). 401 → null session; the API interceptor also broadcasts it. */
export function useSession() {
  const client = useQueryClient();
  const query = useQuery<SessionPayload | null>({
    queryKey: SESSION_KEY,
    queryFn: async () => {
      try {
        const s = await authApi.me();
        setLanguage(s.user.language);
        return s;
      } catch (e) {
        if (e instanceof ApiError && (e.status === 401 || e.status === 403)) return null;
        throw e;
      }
    },
    staleTime: 5 * 60_000,
    retry: false,
  });

  useEffect(() => {
    const handler = () => client.setQueryData(SESSION_KEY, null);
    window.addEventListener('orbin:unauthenticated', handler);
    return () => window.removeEventListener('orbin:unauthenticated', handler);
  }, [client]);

  const session = query.data ?? null;
  return {
    session,
    user: session?.user ?? null,
    loading: query.isLoading,
    error: query.error,
    can: (permission: string | string[]) => can(session, permission),
    refetch: query.refetch,
  };
}

export function useLogin() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: authApi.login,
    onSuccess: (session) => {
      client.setQueryData(SESSION_KEY, session);
      setLanguage(session.user.language);
    },
  });
}

export function useLogout() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: authApi.logout,
    onSettled: () => {
      client.setQueryData(SESSION_KEY, null);
      client.removeQueries({ predicate: (q) => q.queryKey[0] !== 'auth' });
    },
  });
}
