import { useQuery } from '@tanstack/react-query';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useDebouncedValue } from '@/app/useDebouncedValue';
import { Button, Input } from '@/design/components';
import { variantApi } from '@/features/catalog/api';
import type { Variant } from '@/features/catalog/types';

interface Props {
  onPick: (variant: Variant) => void;
  label?: string;
  /** Variants already on the document are shown but marked, so a line is not added twice by accident. */
  exclude?: number[];
  disabled?: boolean;
}

/** Search by SKU, barcode, OEM number or name and add the variant to a document. */
export function VariantPicker({ onPick, label, exclude = [], disabled }: Props) {
  const { t } = useTranslation();
  const [q, setQ] = useState('');
  const debounced = useDebouncedValue(q, 200).trim();
  const found = useQuery({ queryKey: ['variant-search', 'picker', debounced], queryFn: () => variantApi.search(debounced, 8), enabled: debounced.length >= 2 && !disabled });
  return (
    <div className="grid gap-2">
      <Input label={label ?? t('stock.findVariant')} type="search" placeholder={t('products.searchPlaceholder')} disabled={disabled} value={q} onChange={(e) => setQ(e.target.value)} />
      {debounced.length >= 2 && (
        <ul className="grid gap-1" aria-label={t('products.bundle.results')}>
          {(found.data ?? []).map((v) => (
            <li key={v.id}>
              <Button type="button" variant="ghost" size="sm" className="w-full justify-between" onClick={() => { onPick(v); setQ(''); }}>
                <span><code className="font-semibold">{v.sku}</code> <span className="text-ink-muted">{v.variant_name}</span>{exclude.includes(v.id) && <span className="ms-2 text-xs text-warning">{t('stock.alreadyAdded')}</span>}</span>
                <Plus className="h-4 w-4" aria-hidden="true" />
              </Button>
            </li>
          ))}
          {found.data?.length === 0 && <li className="px-2 text-sm text-ink-muted">{t('common.noResults')}</li>}
        </ul>
      )}
    </div>
  );
}
