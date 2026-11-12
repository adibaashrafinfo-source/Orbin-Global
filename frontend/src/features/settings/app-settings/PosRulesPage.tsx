import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button, Card, CardBody, CardHeader, ErrorState, Input, PageHeader, PageSkeleton, Select, Switch, toast } from '@/design/components';
import { ApiError } from '@/lib/api';
import { useUnsavedChangesGuard } from '@/app/useUnsavedChangesGuard';
import { appSettingsApi, APP_SETTINGS_KEY, type SettingValue } from './api';

const GROUPS = ['sales', 'stock', 'session', 'invoice', 'alerts'] as const;

/** FR-107 App settings grouped by domain; validated server-side against SettingsService::SCHEMA. */
export function PosRulesPage() {
  const { t } = useTranslation();
  const client = useQueryClient();
  const query = useQuery({ queryKey: APP_SETTINGS_KEY, queryFn: appSettingsApi.show });
  // Unsaved edits only; the effective form is server data overlaid with edits.
  const [edits, setEdits] = useState<Record<string, SettingValue>>({});
  const draft = useMemo(() => ({ ...(query.data?.data ?? {}), ...edits }), [query.data, edits]);
  const changed = useMemo(() => {
    if (!query.data) return {};
    const out: Record<string, SettingValue> = {};
    for (const [k, v] of Object.entries(edits)) if (v !== query.data.data[k]) out[k] = v;
    return out;
  }, [edits, query.data]);
  const dirty = Object.keys(changed).length > 0;
  useUnsavedChangesGuard(dirty);
  const save = useMutation({
    mutationFn: () => appSettingsApi.update(changed),
    onSuccess: (data) => {
      client.setQueryData(APP_SETTINGS_KEY, data);
      setEdits({});
      toast.success(t('appSettings.saved'));
    },
    onError: (e) => toast.error(t(`errors.${e instanceof ApiError ? e.code : 'unknown'}`, { defaultValue: t('errors.unknown') })),
  });

  if (query.isLoading) return <PageSkeleton rows={12} />;
  if (query.error || !query.data) return <ErrorState onRetry={() => void query.refetch()} />;
  const schema = query.data.meta.schema;
  const serverErr = save.error instanceof ApiError ? save.error.fields : {};
  const set = (k: string, v: SettingValue) => setEdits((d) => ({ ...d, [k]: v }));

  return (
    <>
      <PageHeader title={t('appSettings.title')} description={t('appSettings.subtitle')} crumbs={[{ label: t('settings.title') }, { label: t('appSettings.title') }]} actions={<Button onClick={() => save.mutate()} loading={save.isPending} disabled={!dirty}>{t('common.save')}</Button>} />
      <div className="grid gap-6 lg:grid-cols-2">
        {GROUPS.map((group) => {
          const keys = Object.keys(schema).filter((k) => schema[k]?.group === group);
          if (keys.length === 0) return null;
          return (
            <Card key={group}>
              <CardHeader title={t(`appSettings.groups.${group}`)} />
              <CardBody className="grid gap-4">
                {keys.map((key) => {
                  const def = schema[key]!;
                  const label = t(`appSettings.keys.${key}`, { defaultValue: key });
                  const value = draft[key];
                  const error = serverErr[key] ? t('errors.validation') : undefined;
                  if (def.type === 'bool') return <Switch key={key} label={label} checked={Boolean(value)} onChange={(v) => set(key, v)} />;
                  if (def.type === 'enum')
                    return <Select key={key} label={label} value={value === null || value === undefined ? '' : String(value)} onChange={(v) => set(key, v)} options={(def.options ?? []).map((o) => ({ value: String(o), label: t(`appSettings.options.${String(o)}`, { defaultValue: String(o) }) }))} error={error} />;
                  if (def.type === 'int')
                    return <Input key={key} label={label} type="number" min={def.min} max={def.max} value={value === null || value === undefined ? '' : String(value)} onChange={(e) => set(key, e.target.value === '' ? null : Number(e.target.value))} error={error} />;
                  if (def.type === 'date')
                    return <Input key={key} label={label} type="date" value={typeof value === 'string' ? value : ''} onChange={(e) => set(key, e.target.value === '' ? null : e.target.value)} error={error} />;
                  return <Input key={key} label={label} value={typeof value === 'string' ? value : ''} onChange={(e) => set(key, e.target.value)} error={error} />;
                })}
              </CardBody>
            </Card>
          );
        })}
      </div>
    </>
  );
}
