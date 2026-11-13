import { useTranslation } from 'react-i18next';
import { Button, Drawer, Select } from '@/design/components';
import { useLookupOptions, useMasterOptions, useMaster } from '../hooks';
import { str } from '../util';
import { MIRROR_TYPES, POSITIONS } from '../types';
import { EMPTY_FILTERS, type Filters, type FilterKey } from './useProductFilters';

const ALL = '__all__';

interface Props {
  open: boolean;
  onOpenChange: (o: boolean) => void;
  value: Filters;
  onChange: (f: Filters) => void;
}

/** Filters drawer (FR-301). Selecting a make narrows the model list; every filter is optional. */
export function ProductFiltersDrawer({ open, onOpenChange, value, onChange }: Props) {
  const { t } = useTranslation();
  const categories = useMasterOptions('categories', 'name_en', 'name_bn');
  const brands = useMasterOptions('product-brands', 'name');
  const makes = useMasterOptions('vehicle-makes', 'name');
  const models = useMaster('vehicle-models');
  const grades = useLookupOptions('quality_grade');
  const glass = useLookupOptions('glass_type');

  const set = (k: FilterKey, v: string) => onChange({ ...value, [k]: v === ALL ? '' : v, ...(k === 'make_id' ? { model_id: '' } : {}) });
  const field = (k: FilterKey, label: string, options: { value: string; label: string }[]) => (
    <Select label={label} value={value[k] || ALL} onChange={(v) => set(k, v)} options={[{ value: ALL, label: t('common.all') }, ...options]} />
  );
  const modelOptions = (models.data ?? [])
    .filter((m) => m.is_active && (!value.make_id || String(m.make_id) === value.make_id))
    .map((m) => ({ value: String(m.id), label: `${str(m.name)}${m.generation_code ? ` (${str(m.generation_code)})` : ''}` }));

  return (
    <Drawer
      open={open}
      onOpenChange={onOpenChange}
      title={t('products.filters')}
      footer={<><Button variant="secondary" onClick={() => onChange(EMPTY_FILTERS)}>{t('products.clearFilters')}</Button><Button onClick={() => onOpenChange(false)}>{t('products.showResults')}</Button></>}
    >
      <div className="grid gap-4">
        {field('mirror_type', t('products.fields.mirrorType'), MIRROR_TYPES.map((m) => ({ value: m, label: t(`products.mirrorTypes.${m}`) })))}
        {field('category_id', t('products.fields.category'), categories)}
        {field('brand_id', t('products.fields.brand'), brands)}
        {field('quality_grade_id', t('products.fields.qualityGrade'), grades)}
        {field('glass_type_id', t('products.fields.glassType'), glass)}
        {field('position', t('products.fields.position'), POSITIONS.map((p) => ({ value: p, label: t(`products.positions.${p}`) })))}
        {field('make_id', t('products.fields.fitsMake'), makes)}
        {field('model_id', t('products.fields.fitsModel'), modelOptions)}
        {field('is_active', t('common.status'), [{ value: '1', label: t('products.published') }, { value: '0', label: t('products.draft') }])}
      </div>
    </Drawer>
  );
}
