import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { toast } from '@/design/components';
import { useApiError } from '@/features/catalog/useApiError';
import { useTranslation } from 'react-i18next';
import { docApi, STOCK_KEYS } from './api';
import type { Doc, DocLine } from './types';

/** Load one document (not for "new"). */
export function useDoc(resource: string, id: string | undefined) {
  return useQuery({ queryKey: STOCK_KEYS.doc(resource, id ?? ''), queryFn: () => docApi(resource).show(id as string), enabled: !!id && id !== 'new' });
}

/** Create or update a draft. A new document opens at its own address. */
export function useDocSave(resource: string, base: string) {
  const client = useQueryClient();
  const navigate = useNavigate();
  const onError = useApiError();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: ({ id, body }: { id: number | null; body: Record<string, unknown> }) => (id ? docApi(resource).update(id, body) : docApi(resource).create(body)),
    onSuccess: (doc, vars) => {
      client.setQueryData(STOCK_KEYS.doc(resource, doc.id), doc);
      void client.invalidateQueries({ queryKey: STOCK_KEYS.docs(resource) });
      toast.success(t('stock.saved'));
      if (!vars.id) navigate(`${base}/${doc.id}`, { replace: true });
    },
    onError,
  });
}

/** A named action on a document (approve, post, send, …). Stock changes, so every inventory view refreshes. */
export function useDocAct(resource: string, successKey = 'stock.done') {
  const client = useQueryClient();
  const onError = useApiError();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: ({ id, action, body }: { id: number; action: string; body?: Record<string, unknown> }) => docApi(resource).act(id, action, body),
    onSuccess: (doc) => {
      client.setQueryData(STOCK_KEYS.doc(resource, doc.id), doc);
      void client.invalidateQueries({ queryKey: STOCK_KEYS.docs(resource) });
      void client.invalidateQueries({ queryKey: STOCK_KEYS.inventory });
      void client.invalidateQueries({ queryKey: ['products'] });
      toast.success(t(successKey));
    },
    onError,
  });
}

export function useDocDelete(resource: string, listPath: string) {
  const client = useQueryClient();
  const navigate = useNavigate();
  const onError = useApiError();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (id: number) => docApi(resource).remove(id),
    onSuccess: () => { void client.invalidateQueries({ queryKey: STOCK_KEYS.docs(resource) }); toast.success(t('masterData.deleted')); navigate(listPath, { replace: true }); },
    onError,
  });
}

/** Document items come back with every column as a string or null; the editor keeps them as strings. */
export function toLines(doc: Doc | undefined): DocLine[] {
  return (doc?.items ?? []).map((i) => ({ ...i }));
}
