import { useTranslation } from 'react-i18next';
import { EmptyState } from '@/design/components';
import type { StepProps } from './steps';
import { VariantGrid, type GridCol } from './VariantGrid';

/** Step 6. Reorder levels and serial tracking per variant. Quantities on hand arrive with inventory in Phase 2. */
export function StockStep({ product, canManage }: StepProps) {
  const { t } = useTranslation();
  const variants = product.variants ?? [];
  if (variants.length === 0) return <EmptyState body={t('products.variantsEmpty')} />;
  const cols: GridCol[] = [
    { key: 'reorder_level', label: t('products.fields.reorderLevel'), kind: 'number' },
    { key: 'reorder_qty', label: t('products.fields.reorderQty'), kind: 'number' },
    { key: 'max_stock', label: t('products.fields.maxStock'), kind: 'number', nullable: true },
    { key: 'track_serial', label: t('products.fields.trackSerial'), kind: 'switch' },
  ];
  return (
    <section aria-labelledby="stock-h" className="grid gap-3">
      <div>
        <h2 id="stock-h" className="font-heading text-lg font-bold text-navy-900">{t('products.steps.stock')}</h2>
        <p className="text-sm text-ink-muted">{t('products.stockHint')}</p>
      </div>
      <VariantGrid productId={product.id} variants={variants} cols={cols} editable={canManage} caption={t('products.steps.stock')} extra={[{ label: t('products.fields.onHand'), render: (v) => v.stock_on_hand ?? t('products.phase2') }]} />
    </section>
  );
}
