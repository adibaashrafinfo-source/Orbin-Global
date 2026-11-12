import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';
import { useEffect, useMemo, useState } from 'react';
import { useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { z } from 'zod';
import { Button, DataTable, Input, Modal, PageHeader, Select, toast } from '@/design/components';
import { ApiError } from '@/lib/api';
import { numberingApi, NUMBERING_KEY, type NumberSequence } from './api';

const schema = z.object({
  prefix: z.string().trim().min(1).max(20).regex(/^[A-Z0-9-]+$/),
  date_token: z.string().regex(/^(YYYY|YY|MM|DD)*$/),
  padding: z.number().int().min(1).max(10),
  reset_policy: z.enum(['never', 'yearly', 'monthly']),
  next_value: z.number().int().min(1),
});
type Form = z.infer<typeof schema>;

/** FR-106 Number sequence formats (gap-free; next value only moves forward). */
export function NumberingPage() {
  const { t } = useTranslation();
  const client = useQueryClient();
  const query = useQuery({ queryKey: NUMBERING_KEY, queryFn: numberingApi.list });
  const [edit, setEdit] = useState<NumberSequence | null>(null);
  const form = useForm<Form>({ resolver: zodResolver(schema), defaultValues: { prefix: '', date_token: '', padding: 6, reset_policy: 'yearly', next_value: 1 } });
  useEffect(() => {
    if (edit) form.reset({ prefix: edit.prefix, date_token: edit.date_token, padding: edit.padding, reset_policy: edit.reset_policy, next_value: edit.next_value });
  }, [edit, form]);
  const save = useMutation({
    mutationFn: (v: Form) => numberingApi.update(edit!.id, v),
    onSuccess: () => {
      void client.invalidateQueries({ queryKey: NUMBERING_KEY });
      toast.success(t('numbering.saved'));
      setEdit(null);
    },
    onError: (e) => toast.error(t(`errors.${e instanceof ApiError ? e.code : 'unknown'}`, { defaultValue: t('errors.unknown') })),
  });

  const columns = useMemo<ColumnDef<NumberSequence, unknown>[]>(
    () => [
      { accessorKey: 'key', header: t('numbering.key'), cell: ({ getValue }) => <span className="font-medium text-ink">{t(`numbering.keys.${getValue<string>()}`, { defaultValue: getValue<string>() })}</span> },
      { accessorKey: 'prefix', header: t('numbering.prefix') },
      { accessorKey: 'date_token', header: t('numbering.dateToken'), cell: ({ getValue }) => getValue<string>() || '—' },
      { accessorKey: 'padding', header: t('numbering.padding'), meta: { align: 'right' } },
      { accessorKey: 'reset_policy', header: t('numbering.resetPolicy'), cell: ({ getValue }) => t(`numbering.${getValue<string>()}`) },
      { accessorKey: 'next_value', header: t('numbering.nextValue'), meta: { align: 'right' } },
      { accessorKey: 'example', header: t('numbering.example'), cell: ({ getValue }) => <code className="rounded bg-navy-50 px-1.5 py-0.5 text-13 tabular">{getValue<string>()}</code> },
    ],
    [t],
  );
  const serverErr = save.error instanceof ApiError ? save.error.fields : {};
  const err = (k: keyof Form) => (form.formState.errors[k] || serverErr[k] ? t('errors.validation') : undefined);

  return (
    <>
      <PageHeader title={t('numbering.title')} description={t('numbering.subtitle')} crumbs={[{ label: t('settings.title') }, { label: t('numbering.title') }]} />
      <DataTable columns={columns} data={query.data ?? []} loading={query.isLoading} error={query.error ? { message: t('errors.unknown') } : null} onRetry={() => void query.refetch()} onRowClick={setEdit} compact caption={t('numbering.title')} />
      <Modal open={edit !== null} onOpenChange={(o) => !o && setEdit(null)} title={edit ? t(`numbering.keys.${edit.key}`, { defaultValue: edit.key }) : ''} footer={<><Button variant="secondary" onClick={() => setEdit(null)}>{t('common.cancel')}</Button><Button type="submit" form="seq-form" loading={save.isPending}>{t('common.save')}</Button></>}>
        <form id="seq-form" onSubmit={form.handleSubmit((v) => save.mutate(v))} className="grid gap-4 md:grid-cols-2" noValidate>
          <Input label={t('numbering.prefix')} required error={err('prefix')} {...form.register('prefix')} />
          <Input label={t('numbering.dateToken')} placeholder="YYMM" error={err('date_token')} {...form.register('date_token')} />
          <Input label={t('numbering.padding')} type="number" min={1} max={10} required error={err('padding')} {...form.register('padding', { valueAsNumber: true })} />
          <Select label={t('numbering.resetPolicy')} value={form.watch('reset_policy')} onChange={(v) => form.setValue('reset_policy', v as Form['reset_policy'])} options={(['never', 'yearly', 'monthly'] as const).map((p) => ({ value: p, label: t(`numbering.${p}`) }))} />
          <Input label={t('numbering.nextValue')} type="number" min={edit?.next_value ?? 1} required hint={t('numbering.subtitle')} error={err('next_value')} {...form.register('next_value', { valueAsNumber: true })} />
        </form>
      </Modal>
    </>
  );
}
