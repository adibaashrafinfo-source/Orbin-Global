import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { BookmarkPlus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button, IconButton, Input, Modal, Select, toast } from '@/design/components';
import { serverFieldError, useApiError } from '@/features/catalog/useApiError';
import { REPORT_KEYS, reportApi } from './api';
import type { Filters, Preset } from './types';
import { rangeDates } from './util';

const NONE = '__none__';

/** Saved views of a report, private to the person (FR-1100). Saving under an existing name replaces it. */
export function PresetBar({ reportId, filters, onApply }: { reportId: string; filters: Filters; onApply: (patch: Partial<Filters>) => void }) {
  const { t } = useTranslation();
  const client = useQueryClient();
  const onError = useApiError();
  const [selected, setSelected] = useState(NONE);
  const [open, setOpen] = useState(false);
  const [name, setName] = useState('');
  const key = [...REPORT_KEYS.presets, reportId];
  const list = useQuery({ queryKey: key, queryFn: () => reportApi.presets(reportId) });
  const presets = list.data ?? [];
  const apply = (p: Preset) => {
    const f = p.filters;
    const quick = f.range && f.range !== 'custom' ? rangeDates(f.range) : null;
    onApply({ range: f.range ?? 'custom', from: quick?.from ?? f.from ?? filters.from, to: quick?.to ?? f.to ?? filters.to, branchId: f.branch_id ? String(f.branch_id) : '', warehouseId: f.warehouse_id ? String(f.warehouse_id) : '', group: f.group ?? '', compare: Boolean(f.compare), page: 1 });
  };
  const save = useMutation({
    mutationFn: () => reportApi.savePreset({ report_id: reportId, name: name.trim(), filters: { range: filters.range, from: filters.from, to: filters.to, branch_id: filters.branchId ? Number(filters.branchId) : null, warehouse_id: filters.warehouseId ? Number(filters.warehouseId) : null, group: filters.group || null, compare: filters.compare } }),
    onSuccess: (p) => { void client.invalidateQueries({ queryKey: key }); setSelected(String(p.id)); setOpen(false); setName(''); toast.success(t('reports.presets.saved')); },
    onError,
  });
  const remove = useMutation({ mutationFn: (id: number) => reportApi.deletePreset(id), onSuccess: () => { void client.invalidateQueries({ queryKey: key }); setSelected(NONE); toast.success(t('reports.presets.deleted')); }, onError });
  const current = presets.find((p) => String(p.id) === selected);
  return (
    <div className="flex flex-wrap items-end gap-2">
      {presets.length > 0 && (
        <div className="w-52"><Select label={t('reports.presets.label')} value={selected} onChange={(v) => { setSelected(v); const p = presets.find((x) => String(x.id) === v); if (p) apply(p); }} options={[{ value: NONE, label: t('reports.presets.none') }, ...presets.map((p) => ({ value: String(p.id), label: p.name }))]} /></div>
      )}
      {current && <IconButton label={t('reports.presets.delete', { name: current.name })} onClick={() => remove.mutate(current.id)}><Trash2 className="h-4 w-4" aria-hidden="true" /></IconButton>}
      <Button variant="ghost" leftIcon={<BookmarkPlus className="h-4 w-4" aria-hidden="true" />} onClick={() => setOpen(true)} data-testid="save-preset">{t('reports.presets.save')}</Button>
      <Modal open={open} onOpenChange={setOpen} className="max-w-sm" title={t('reports.presets.saveTitle')} description={t('reports.presets.saveBody')}
        footer={<><Button variant="secondary" onClick={() => setOpen(false)}>{t('common.cancel')}</Button><Button loading={save.isPending} disabled={name.trim() === ''} onClick={() => save.mutate()} data-testid="preset-confirm">{t('common.save')}</Button></>}>
        <Input label={t('reports.presets.name')} value={name} maxLength={80} onChange={(e) => setName(e.target.value)} error={serverFieldError(t, save.error, 'name')} />
      </Modal>
    </div>
  );
}
