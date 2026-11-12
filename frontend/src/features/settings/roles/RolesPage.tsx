import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge, Button, Card, Checkbox, ErrorState, PageHeader, PageSkeleton, toast } from '@/design/components';
import { ApiError } from '@/lib/api';
import { ROLES, type Role } from '@/lib/permissions';
import { useUnsavedChangesGuard } from '@/app/useUnsavedChangesGuard';
import { rolesApi, ROLES_KEY } from './api';

/** FR-102 Role × permission grid grouped by module. super_admin is read-only (always everything). */
export function RolesPage() {
  const { t } = useTranslation();
  const client = useQueryClient();
  const query = useQuery({ queryKey: ROLES_KEY, queryFn: rolesApi.matrix });
  // Local edits only; the effective grid is server data overlaid with unsaved edits.
  const [edits, setEdits] = useState<Record<string, Record<string, boolean>>>({});
  const draft = useMemo(() => {
    const base = query.data?.matrix;
    if (!base) return {};
    const out: Record<string, Record<string, boolean>> = {};
    for (const role of ROLES) out[role] = { ...base[role], ...edits[role] };
    return out;
  }, [query.data, edits]);

  const dirtyRoles = useMemo(() => {
    if (!query.data) return [] as Role[];
    return ROLES.filter((r) => r !== 'super_admin' && query.data.keys.some((k) => draft[r]?.[k] !== query.data.matrix[r][k]));
  }, [draft, query.data]);
  useUnsavedChangesGuard(dirtyRoles.length > 0);

  const save = useMutation({
    mutationFn: async () => {
      let latest = query.data!;
      for (const role of dirtyRoles) {
        const changes: Record<string, boolean> = {};
        for (const k of latest.keys) if (draft[role]?.[k] !== latest.matrix[role][k]) changes[k] = draft[role]?.[k] ?? false;
        latest = await rolesApi.update(role, changes);
        toast.success(t('roles.saved', { role: t(`users.roles.${role}`) }));
      }
      return latest;
    },
    onSuccess: (data) => {
      client.setQueryData(ROLES_KEY, data);
      setEdits({});
    },
    onError: (e) => toast.error(t(`errors.${e instanceof ApiError ? e.code : 'unknown'}`, { defaultValue: t('errors.unknown') })),
  });
  const reset = useMutation({
    mutationFn: rolesApi.reset,
    onSuccess: (data, role) => {
      client.setQueryData(ROLES_KEY, data);
      setEdits((e) => ({ ...e, [role]: {} }));
    },
  });

  if (query.isLoading) return <PageSkeleton rows={12} />;
  if (query.error || !query.data) return <ErrorState onRetry={() => void query.refetch()} />;
  const { keys } = query.data;
  const modules = Array.from(new Set(keys.map((k) => k.split('.')[0] ?? k)));

  return (
    <>
      <PageHeader
        title={t('roles.title')}
        description={t('roles.subtitle')}
        crumbs={[{ label: t('settings.title') }, { label: t('roles.title') }]}
        actions={<Button onClick={() => save.mutate()} loading={save.isPending} disabled={dirtyRoles.length === 0}>{t('common.save')}</Button>}
      />
      <Card className="overflow-x-auto">
        <table className="w-full border-collapse text-sm">
          <thead className="table-head sticky top-0 z-10">
            <tr>
              <th scope="col" className="sticky left-0 z-20 bg-navy-900 px-4 py-3 text-left">{t('roles.permission')}</th>
              {ROLES.map((role) => (
                <th key={role} scope="col" className="px-3 py-3 text-center">
                  <div className="flex flex-col items-center gap-1">
                    <span>{t(`users.roles.${role}`)}</span>
                    {role !== 'super_admin' && (
                      <button type="button" onClick={() => reset.mutate(role)} className="rounded text-[10px] font-normal normal-case tracking-normal text-navy-300 underline-offset-2 hover:text-surface hover:underline focus-visible:focus-ring-dark">
                        {t('roles.resetDefaults')}
                      </button>
                    )}
                  </div>
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {modules.map((mod) => (
              <ModuleRows key={mod} mod={mod} keys={keys.filter((k) => k.startsWith(mod + '.'))} draft={draft} onToggle={(role, key, value) => setEdits((d) => ({ ...d, [role]: { ...d[role], [key]: value } }))} />
            ))}
          </tbody>
        </table>
      </Card>
    </>
  );
}

function ModuleRows({ mod, keys, draft, onToggle }: { mod: string; keys: string[]; draft: Record<string, Record<string, boolean>>; onToggle: (role: Role, key: string, value: boolean) => void }) {
  const { t } = useTranslation();
  return (
    <>
      <tr className="bg-navy-50">
        <th scope="rowgroup" colSpan={ROLES.length + 1} className="sticky left-0 px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-navy-700">
          {t(`roles.modules.${mod}`, { defaultValue: mod })}
        </th>
      </tr>
      {keys.map((key) => (
        <tr key={key} className="h-10 border-b border-navy-100 hover:bg-navy-100/60">
          <th scope="row" className="sticky left-0 bg-surface px-4 text-left font-normal">
            <code className="text-13 text-ink">{key}</code>
          </th>
          {ROLES.map((role) => (
            <td key={role} className="text-center">
              <div className="flex justify-center">
                {role === 'super_admin' ? (
                  <Badge tone="success">✓</Badge>
                ) : (
                  <Checkbox checked={draft[role]?.[key] ?? false} onChange={(v) => onToggle(role, key, v)} aria-label={`${t(`users.roles.${role}`)} · ${key}`} />
                )}
              </div>
            </td>
          ))}
        </tr>
      ))}
    </>
  );
}
