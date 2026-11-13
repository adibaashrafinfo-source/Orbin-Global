import { useMutation, useQuery } from '@tanstack/react-query';
import { Download, Plus, Printer, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useDebouncedValue } from '@/app/useDebouncedValue';
import { Button, Card, CardBody, EmptyState, IconButton, Input, PageHeader, Select, Switch } from '@/design/components';
import { cn } from '@/lib/cn';
import { labelApi, variantApi } from '../api';
import type { Variant } from '../types';
import { useApiError } from '../useApiError';

type Template = 'a4' | 'roll-50x25';
interface QueueItem { variant_id: number; sku: string; name: string | null; qty: number }
const A4_SLOTS = 27;

/** Which of the 27 A4 slots (3 × 9) printing starts at, so a part-used sheet can be reused. */
function SlotPicker({ value, onChange }: { value: number; onChange: (n: number) => void }) {
  const { t } = useTranslation();
  return (
    <div role="radiogroup" aria-label={t('labels.startPosition')} className="grid w-48 grid-cols-3 gap-1">
      {Array.from({ length: A4_SLOTS }, (_, i) => i + 1).map((n) => (
        <button key={n} type="button" role="radio" aria-checked={value === n} aria-label={t('labels.slot', { n })} onClick={() => onChange(n)}
          className={cn('h-5 rounded-sm border text-[10px] focus-visible:focus-ring-light', n < value ? 'border-navy-100 bg-navy-50 text-ink-muted' : n === value ? 'border-navy-900 bg-navy-900 text-surface' : 'border-navy-300 bg-surface text-ink-muted')}>{n}</button>
      ))}
    </div>
  );
}

/** FR-307 Barcode labels: queue variants with quantities, pick A4 (3 × 9) or a 50 × 25 mm roll, preview the PDF and print. */
export function LabelsPage() {
  const { t } = useTranslation();
  const onError = useApiError();
  const [q, setQ] = useState('');
  const debounced = useDebouncedValue(q, 200).trim();
  const [queue, setQueue] = useState<QueueItem[]>([]);
  const [template, setTemplate] = useState<Template>('a4');
  const [showPrice, setShowPrice] = useState(true);
  const [showName, setShowName] = useState(true);
  const [showSize, setShowSize] = useState(true);
  const [generateMissing, setGenerateMissing] = useState(true);
  const [start, setStart] = useState(1);
  const [pdfUrl, setPdfUrl] = useState<string | null>(null);
  const found = useQuery({ queryKey: ['variant-search', debounced, 'labels'], queryFn: () => variantApi.search(debounced, 10), enabled: debounced.length >= 2 });

  useEffect(() => () => { if (pdfUrl) URL.revokeObjectURL(pdfUrl); }, [pdfUrl]);

  const add = (v: Variant) => setQueue((cur) => (cur.some((i) => i.variant_id === v.id) ? cur : [...cur, { variant_id: v.id, sku: v.sku, name: v.variant_name, qty: 1 }]));
  const setQty = (id: number, qty: number) => setQueue((cur) => cur.map((i) => (i.variant_id === id ? { ...i, qty: Math.max(1, Math.min(500, qty || 1)) } : i)));
  const total = queue.reduce((n, i) => n + i.qty, 0);

  const render = useMutation({
    mutationFn: () => labelApi.render({ items: queue.map(({ variant_id, qty }) => ({ variant_id, qty })), template, show_price: showPrice, show_name: showName, show_size: showSize, generate_missing: generateMissing, ...(template === 'a4' ? { start_position: start } : {}) }),
    onSuccess: (blob) => setPdfUrl(URL.createObjectURL(new Blob([blob], { type: 'application/pdf' }))),
    onError,
  });

  return (
    <>
      <PageHeader title={t('labels.title')} description={t('labels.subtitle')} crumbs={[{ label: t('products.title'), to: '/products' }, { label: t('labels.title') }]} />
      <div className="grid gap-6 lg:grid-cols-[1fr_22rem]">
        <div className="grid content-start gap-5">
          <Card><CardBody>
            <Input label={t('labels.find')} type="search" placeholder={t('products.searchPlaceholder')} value={q} onChange={(e) => setQ(e.target.value)} />
            {debounced.length >= 2 && (
              <ul className="mt-2 grid gap-1" aria-label={t('products.bundle.results')}>
                {(found.data ?? []).map((v) => <li key={v.id}><Button variant="ghost" size="sm" className="w-full justify-between" onClick={() => add(v)}><span><code>{v.sku}</code> {v.variant_name}</span><Plus className="h-4 w-4" aria-hidden="true" /></Button></li>)}
                {found.data?.length === 0 && <li className="px-2 text-sm text-ink-muted">{t('common.noResults')}</li>}
              </ul>
            )}
          </CardBody></Card>
          <Card><CardBody>
            <h2 className="mb-3 font-heading text-lg font-bold text-navy-900">{t('labels.queue')} <span className="text-sm font-normal text-ink-muted">({t('labels.total', { count: total })})</span></h2>
            {queue.length === 0 ? <EmptyState body={t('labels.queueEmpty')} /> : (
              <ul className="grid gap-2">
                {queue.map((i) => (
                  <li key={i.variant_id} className="flex items-center justify-between gap-3 rounded-input bg-navy-50 px-3 py-2 text-sm">
                    <span className="min-w-0"><code className="font-semibold">{i.sku}</code> <span className="truncate text-ink-muted">{i.name}</span></span>
                    <span className="flex items-center gap-2">
                      <Input aria-label={t('labels.qtyFor', { sku: i.sku })} className="w-20" type="number" min={1} max={500} value={i.qty} onChange={(e) => setQty(i.variant_id, Number(e.target.value))} />
                      <IconButton label={t('common.remove')} onClick={() => setQueue((cur) => cur.filter((x) => x.variant_id !== i.variant_id))}><X className="h-4 w-4" aria-hidden="true" /></IconButton>
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </CardBody></Card>
          {pdfUrl && (
            <Card><CardBody className="grid gap-3">
              <div className="flex items-center justify-between"><h2 className="font-heading text-lg font-bold text-navy-900">{t('labels.preview')}</h2>
                <a href={pdfUrl} download={`orbin-labels-${template}.pdf`} className="inline-flex h-8 items-center gap-2 rounded-input border border-navy-200 px-3 text-13 font-semibold text-navy-800 hover:bg-navy-50 focus-visible:focus-ring-light"><Download className="h-4 w-4" aria-hidden="true" />{t('common.download')}</a></div>
              <iframe title={t('labels.preview')} src={pdfUrl} className="h-[32rem] w-full rounded-input border border-navy-100" />
            </CardBody></Card>
          )}
        </div>

        <Card className="h-fit"><CardBody className="grid gap-4">
          <Select label={t('labels.template')} value={template} onChange={(v) => { setTemplate(v as Template); setPdfUrl(null); }} options={[{ value: 'a4', label: t('labels.a4') }, { value: 'roll-50x25', label: t('labels.roll') }]} />
          {template === 'a4' && <div><p className="mb-1 text-sm font-medium text-ink">{t('labels.startPosition')}</p><SlotPicker value={start} onChange={setStart} /></div>}
          <Switch label={t('labels.showName')} checked={showName} onChange={setShowName} />
          <Switch label={t('labels.showSize')} checked={showSize} onChange={setShowSize} />
          <Switch label={t('labels.showPrice')} checked={showPrice} onChange={setShowPrice} />
          <Switch label={t('labels.generateMissing')} description={t('labels.generateMissingHint')} checked={generateMissing} onChange={setGenerateMissing} />
          <Button leftIcon={<Printer className="h-4 w-4" aria-hidden="true" />} disabled={queue.length === 0} loading={render.isPending} onClick={() => render.mutate()}>{t('labels.generate')}</Button>
        </CardBody></Card>
      </div>
    </>
  );
}
