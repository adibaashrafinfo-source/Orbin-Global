import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { Badge, Card, CardBody, CardHeader, EmptyState } from '@/design/components';
import { formatMoney } from '@/lib/money';
import type { DashboardData, TopRow as Row } from './types';

/** A ranked list with a bar behind each line, scaled to the biggest. */
function Ranked({ rows, label }: { rows: Row[]; label: (r: Row) => string }) {
  const max = Math.max(...rows.map((r) => Number(r.revenue)), 1);

  return (
    <ol className="space-y-2">
      {rows.map((r, i) => (
        <li key={`${label(r)}-${i}`} className="relative overflow-hidden rounded-input">
          <span aria-hidden="true" className="absolute inset-y-0 start-0 bg-navy-100" style={{ width: `${(Number(r.revenue) / max) * 100}%` }} />
          <span className="relative flex items-center justify-between gap-3 px-3 py-1.5 text-sm"><span className="truncate font-medium text-navy-900">{label(r)}</span><span className="tabular whitespace-nowrap text-ink-muted">{Number(r.qty)} · {formatMoney(r.revenue)}</span></span>
        </li>
      ))}
    </ol>
  );
}

/** Row 3: top products, top vehicle models and what is low on the shelf (each low item has a way to reorder). */
export function TopRow({ w }: { w: DashboardData['widgets'] }) {
  const { t } = useTranslation();
  const products = w['top-products'];
  const models = w['top-vehicle-models'];
  const low = w['low-stock'];
  if (!products && !models && !low) return null;

  return (
    <div className="mb-5 grid gap-4 lg:grid-cols-3">
      {products && <Card data-testid="top-products"><CardHeader title={t('dashboard.top.products')} /><CardBody>{products.rows.length === 0 ? <EmptyState body={t('dashboard.top.empty')} /> : <Ranked rows={products.rows} label={(r) => r.item ?? ''} />}</CardBody></Card>}
      {models && <Card data-testid="top-models"><CardHeader title={t('dashboard.top.models')} description={t('dashboard.top.modelsHint')} /><CardBody>{models.rows.length === 0 ? <EmptyState body={t('dashboard.top.noModels')} /> : <Ranked rows={models.rows} label={(r) => [r.make, r.model].filter(Boolean).join(' ')} />}</CardBody></Card>}
      {low && (
        <Card data-testid="low-stock"><CardHeader title={t('dashboard.low.title')} description={t('dashboard.low.counts', { low: low.low, out: low.out })} actions={low.rows.length > 0 ? <Link to="/inventory/reorder" className="text-sm font-semibold text-navy-800 hover:underline focus-visible:focus-ring-light">{t('dashboard.low.createPo')}</Link> : undefined} />
          <CardBody>{low.rows.length === 0 ? <EmptyState body={t('dashboard.low.none')} /> : (
            <ul className="space-y-2">
              {low.rows.map((r) => <li key={`${r.variant_id}-${r.status}`} className="flex items-center justify-between gap-2 text-sm"><Link to={`/inventory/card/${r.variant_id}`} className="min-w-0 truncate hover:underline focus-visible:focus-ring-light"><span className="font-semibold text-navy-900">{r.sku}</span> <span className="text-ink-muted">{r.product}</span></Link><Badge tone={r.status === 'out' ? 'danger' : 'warning'} pulse={r.status === 'out'}>{r.status === 'out' ? t('dashboard.low.out') : t('dashboard.low.low', { qty: Number(r.on_hand) })}</Badge></li>)}
            </ul>
          )}</CardBody></Card>
      )}
    </div>
  );
}
