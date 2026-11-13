import { useTranslation } from 'react-i18next';
import { EmptyState } from '@/design/components';
import { formatMoney, toPaisa } from '@/lib/money';
import type { PriceColumn } from '../types';
import type { StepProps } from './steps';
import { VariantGrid, type GridCol } from './VariantGrid';

const COLS: PriceColumn[] = ['retail_price', 'wholesale_price', 'dealer_price', 'mrp', 'min_price'];

/** Step 5. Price tiers per variant. Needs pricing.manage to edit; cost is only present for users who may see it (AT-019). */
export function PricingStep({ product, canPrice, canCost, canManage }: StepProps) {
  const { t } = useTranslation();
  const variants = product.variants ?? [];
  if (variants.length === 0) return <EmptyState body={t('products.variantsEmpty')} />;
  const cols: GridCol[] = COLS.map((c) => ({ key: c, label: t(`products.fields.${c}`), kind: 'money', nullable: c !== 'retail_price' }));
  const extra = [];
  if (canCost) {
    extra.push({ label: t('products.fields.lastCost'), render: (v: (typeof variants)[number]) => (v.last_cost && toPaisa(v.last_cost) > 0n ? formatMoney(v.last_cost) : '—') });
    extra.push({
      label: t('products.fields.margin'),
      render: (v: (typeof variants)[number], values: Record<string, string | boolean>) => {
        const retail = toPaisa(String(values.retail_price || '0'));
        if (!v.last_cost || toPaisa(v.last_cost) <= 0n || retail <= 0n) return '—';
        const margin = Number(((retail - toPaisa(v.last_cost)) * 1000n) / retail) / 10;
        return `${margin.toFixed(1)}%`;
      },
    });
  }
  return (
    <section aria-labelledby="pricing-h" className="grid gap-3">
      <div>
        <h2 id="pricing-h" className="font-heading text-lg font-bold text-navy-900">{t('products.steps.pricing')}</h2>
        <p className="text-sm text-ink-muted">{canPrice ? t('products.pricingHint') : t('products.pricingLocked')}</p>
      </div>
      <VariantGrid productId={product.id} variants={variants} cols={cols} editable={canPrice && canManage} extra={extra} caption={t('products.steps.pricing')} />
    </section>
  );
}
