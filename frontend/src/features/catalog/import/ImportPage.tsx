import { useMutation, useQueryClient } from '@tanstack/react-query';
import { CheckCircle2, FileSpreadsheet } from 'lucide-react';
import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge, Button, Card, CardBody, PageHeader, Select, toast } from '@/design/components';
import { useSession } from '@/features/auth/useSession';
import { ApiError } from '@/lib/api';
import { formatBytes } from '@/lib/format';
import { importApi, KEYS, type ImportSummary } from '../api';
import { useApiError } from '../useApiError';
import { ImportErrors } from './ImportErrors';

const KINDS = ['vehicle-makes', 'vehicle-models', 'products', 'fitments', 'opening-stock'] as const;
type Kind = (typeof KINDS)[number];

/** FR-309 Import: choose a kind, download the template, check the file (dry run), then commit. Nothing is written unless every row is valid. */
export function ImportPage() {
  const { t } = useTranslation();
  const { can } = useSession();
  const client = useQueryClient();
  const onError = useApiError();
  const input = useRef<HTMLInputElement>(null);
  const allowed = KINDS.filter((k) => (k === 'vehicle-makes' || k === 'vehicle-models' ? can(['masterdata.manage']) : k === 'products' ? can(['products.import']) && can(['pricing.manage']) : k === 'opening-stock' ? can(['inventory.adjust.approve']) && can(['products.view_cost']) : can(['products.import'])));
  const [kind, setKind] = useState<Kind>(allowed[0] ?? 'products');
  const [file, setFile] = useState<File | null>(null);
  const [token, setToken] = useState<string | null>(null);
  const [summary, setSummary] = useState<ImportSummary | null>(null);
  const [done, setDone] = useState<ImportSummary | null>(null);

  const reset = () => { setToken(null); setSummary(null); setDone(null); };
  const check = useMutation({ mutationFn: () => importApi.dryRun(kind, file as File), onSuccess: (r) => { setToken(r.token); setSummary(r.summary); setDone(null); }, onError });
  const commit = useMutation({
    mutationFn: () => importApi.commit(kind, token as string),
    onSuccess: (r) => { setDone(r); setSummary(null); setToken(null); setFile(null); void client.invalidateQueries({ queryKey: KEYS.products }); void client.invalidateQueries({ queryKey: ['master'] }); toast.success(t('import.committed')); },
    onError: (e) => { if (e instanceof ApiError && e.code === 'IMPORT_NOT_FOUND') reset(); onError(e); },
  });
  const clean = summary !== null && summary.invalid_rows === 0 && summary.total_rows > 0;

  if (allowed.length === 0) return <p className="text-sm text-ink-muted">{t('errors.forbidden')}</p>;
  return (
    <>
      <PageHeader title={t('import.title')} description={t('import.subtitle')} crumbs={[{ label: t('products.title'), to: '/products' }, { label: t('import.title') }]} />
      <div className="grid gap-5">
        <Card><CardBody className="grid gap-4 md:grid-cols-[16rem_minmax(0,1fr)]">
          <Select label={t('import.kind')} value={kind} onChange={(v) => { setKind(v as Kind); setFile(null); reset(); }} options={allowed.map((k) => ({ value: k, label: t(`import.kinds.${k}`) }))} />
          <div className="grid gap-2">
            <p className="text-sm text-ink-muted">{t(`import.hints.${kind}`)}</p>
            <div className="flex flex-wrap gap-2">
              <a className="inline-flex h-8 items-center gap-2 rounded-input border border-navy-200 px-3 text-13 font-semibold text-navy-800 hover:bg-navy-50 focus-visible:focus-ring-light" href={importApi.templateUrl(kind, 'csv')} download>{t('import.templateCsv')}</a>
              <a className="inline-flex h-8 items-center gap-2 rounded-input border border-navy-200 px-3 text-13 font-semibold text-navy-800 hover:bg-navy-50 focus-visible:focus-ring-light" href={importApi.templateUrl(kind, 'xlsx')} download>{t('import.templateXlsx')}</a>
            </div>
          </div>
          <div className="md:col-span-2 flex flex-wrap items-center gap-3">
            <input ref={input} type="file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" className="sr-only" data-testid="import-file" onChange={(e) => { setFile(e.target.files?.[0] ?? null); reset(); }} />
            <Button variant="secondary" leftIcon={<FileSpreadsheet className="h-4 w-4" aria-hidden="true" />} onClick={() => input.current?.click()}>{t('import.chooseFile')}</Button>
            {file && <span className="text-sm text-ink-muted">{file.name} · {formatBytes(file.size)}</span>}
            <Button disabled={!file} loading={check.isPending} onClick={() => check.mutate()}>{t('import.check')}</Button>
          </div>
        </CardBody></Card>

        {summary && (
          <Card><CardBody className="grid gap-4" aria-live="polite">
            <div className="flex flex-wrap items-center gap-3">
              <Badge tone="navy">{t('import.rows', { count: summary.total_rows })}</Badge>
              <Badge tone="success">{t('import.valid', { count: summary.valid_rows })}</Badge>
              {summary.invalid_rows > 0 && <Badge tone="danger">{t('import.invalid', { count: summary.invalid_rows })}</Badge>}
              {clean && <span className="text-sm text-ink-muted">{t('import.wouldCreate', { create: summary.would_create, update: summary.would_update })}</span>}
            </div>
            {summary.invalid_rows > 0 && <><p className="text-sm text-danger">{t('import.fixAndRetry')}</p><ImportErrors summary={summary} /></>}
            <div className="flex justify-end"><Button variant={clean ? 'accent' : 'secondary'} disabled={!clean} loading={commit.isPending} onClick={() => commit.mutate()}>{t('import.commit', { count: summary.total_rows })}</Button></div>
          </CardBody></Card>
        )}
        {done && (
          <Card><CardBody className="flex items-center gap-3" role="status"><CheckCircle2 className="h-5 w-5 text-success" aria-hidden="true" /><span className="text-sm">{t('import.done', { create: done.created ?? 0, update: done.updated ?? 0 })}</span></CardBody></Card>
        )}
      </div>
    </>
  );
}
