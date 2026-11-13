import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Card, CardBody } from '@/design/components';
import { formatMoney } from '@/lib/money';
import { useDebouncedValue } from '@/app/useDebouncedValue';
import { purchaseApi } from '../stock/api';
import { fmtQty, trimCost } from '../stock/format';
import type { DocLine, ExtraCost, Preview } from '../stock/types';

interface Props {
  lines: DocLine[];
  extras: ExtraCost[];
  basis: 'value' | 'quantity';
  /** A posted receipt shows what was booked, not a preview. */
  posted?: boolean;
}

const usable = (l: DocLine) => Number(l.qty_received) >= 0 && String(l.qty_received ?? '') !== '' && String(l.unit_cost ?? '') !== '';

/**
 * The live landed-cost panel (PRD §8.7): as lines and extra costs change it asks the server to do the allocation, so what
 * the screen shows is exactly what posting will book.
 */
export function LandedCostPanel({ lines, extras, basis, posted }: Props) {
  const { t } = useTranslation();
  const payload = {
    allocation_basis: basis,
    items: lines.filter(usable).map((l) => ({ variant_id: l.variant_id, qty_received: String(l.qty_received), qty_damaged: String(l.qty_damaged || '0'), unit_cost: String(l.unit_cost) })),
    extra_costs: extras.filter((e) => e.amount !== '' && Number(e.amount) >= 0).map((e) => ({ cost_type: e.cost_type, amount: e.amount, paid_to: e.paid_to || null })),
  };
  const key = useDebouncedValue(JSON.stringify(payload), 300);
  const query = useQuery({ queryKey: ['landed-preview', key], queryFn: () => purchaseApi.preview(JSON.parse(key) as typeof payload), enabled: !posted && payload.items.length > 0, placeholderData: (prev) => prev });
  const preview: Preview | undefined = query.data;
  const skuOf = (variantId: number, i: number) => lines.filter(usable)[i]?.sku ?? String(variantId);
  if (posted) {
    return (
      <Card><CardBody>
        <h3 className="mb-2 font-heading text-base font-bold text-navy-900">{t('purchasing.landedCost')}</h3>
        <PanelTable rows={lines.map((l) => ({ sku: String(l.sku), good: fmtQty(String(Number(l.qty_received) - Number(l.qty_damaged))), unit: String(l.unit_cost), extra: String(l.allocated_extra), landed: String(l.landed_unit_cost) }))} />
      </CardBody></Card>
    );
  }
  return (
    <Card><CardBody aria-live="polite">
      <div className="mb-2 flex items-center justify-between">
        <h3 className="font-heading text-base font-bold text-navy-900">{t('purchasing.landedCost')}</h3>
        <span className="text-xs text-ink-subtle">{basis === 'value' ? t('purchasing.byValue') : t('purchasing.byQuantity')}</span>
      </div>
      {!preview ? <p className="text-sm text-ink-muted">{t('purchasing.landedEmpty')}</p> : (
        <>
          <PanelTable rows={preview.lines.map((l, i) => ({ sku: skuOf(l.variant_id, i), good: fmtQty(l.qty_good), unit: l.unit_cost, extra: l.allocated_extra, landed: l.landed_unit_cost }))} />
          <dl className="mt-3 ms-auto grid max-w-sm gap-1 text-sm">
            <div className="flex justify-between"><dt>{t('purchasing.itemsTotal')}</dt><dd className="tabular">{formatMoney(preview.items_total)}</dd></div>
            <div className="flex justify-between"><dt>{t('purchasing.extraCosts')}</dt><dd className="tabular">{formatMoney(preview.extra_cost_total)}</dd></div>
            <div className="flex justify-between border-t border-navy-100 pt-1 font-semibold"><dt>{t('purchasing.landedTotal')}</dt><dd className="tabular">{formatMoney(preview.grand_total)}</dd></div>
            <div className="flex justify-between text-ink-muted"><dt>{t('purchasing.supplierOwed')}</dt><dd className="tabular">{formatMoney(String((Number(preview.items_total) + Number(preview.billed_extras)).toFixed(2)))}</dd></div>
          </dl>
        </>
      )}
    </CardBody></Card>
  );
}

function PanelTable({ rows }: { rows: { sku: string; good: string; unit: string; extra: string; landed: string }[] }) {
  const { t } = useTranslation();
  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[480px] text-sm">
        <caption className="sr-only">{t('purchasing.landedCost')}</caption>
        <thead className="text-xs uppercase tracking-wide text-ink-subtle"><tr><th scope="col" className="py-1 text-start">{t('products.fields.sku')}</th><th scope="col" className="py-1 text-end">{t('purchasing.goodQty')}</th><th scope="col" className="py-1 text-end">{t('purchasing.unitCost')}</th><th scope="col" className="py-1 text-end">{t('purchasing.allocatedExtra')}</th><th scope="col" className="py-1 text-end">{t('purchasing.landedUnit')}</th></tr></thead>
        <tbody>{rows.map((r, i) => <tr key={i} className="border-t border-navy-100"><th scope="row" className="py-1.5 text-start font-normal"><code className="text-xs font-semibold">{r.sku}</code></th><td className="tabular py-1.5 text-end">{r.good}</td><td className="tabular py-1.5 text-end">{trimCost(r.unit)}</td><td className="tabular py-1.5 text-end">{formatMoney(r.extra)}</td><td className="tabular py-1.5 text-end font-semibold">{trimCost(r.landed)}</td></tr>)}</tbody>
      </table>
    </div>
  );
}
