import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Button, Input, Modal, Select, Switch, toast } from '@/design/components';
import { KEYS, variantApi } from '../api';
import { POSITIONS, type Position, type Variant } from '../types';
import { useApiError } from '../useApiError';
import { useDerivedState } from '../util';

/** Clone a variant. A clone must differ from its source (position, size or finish), otherwise the API reports a duplicate. */
export function CloneVariantDialog({ productId, variant, onClose }: { productId: number; variant: Variant | null; onClose: () => void }) {
  const { t } = useTranslation();
  const client = useQueryClient();
  const onError = useApiError();
  const [position, setPosition] = useDerivedState<Position>(variant?.id, () => variant?.position ?? 'left');
  const [label, setLabel] = useDerivedState(variant?.id, () => variant?.size_label ?? '');
  const [fit, setFit] = useDerivedState(variant?.id, () => true);
  const run = useMutation({
    mutationFn: () => variantApi.clone(variant!.id, { position, size_label: label.trim() || null, copy_fitments: fit }),
    onSuccess: () => { void client.invalidateQueries({ queryKey: KEYS.product(productId) }); toast.success(t('products.variantSaved')); onClose(); },
    onError,
  });
  return (
    <Modal open={variant !== null} onOpenChange={(o) => !o && onClose()} title={t('products.cloneVariant')} description={variant?.sku}
      footer={<><Button variant="secondary" onClick={onClose}>{t('common.cancel')}</Button><Button loading={run.isPending} onClick={() => run.mutate()}>{t('products.clone')}</Button></>}>
      <div className="grid gap-4">
        <Select label={t('products.fields.position')} value={position} onChange={(v) => setPosition(v as Position)} options={POSITIONS.map((p) => ({ value: p, label: t(`products.positions.${p}`) }))} />
        <Input label={t('products.fields.sizeLabel')} value={label} onChange={(e) => setLabel(e.target.value)} />
        <Switch label={t('products.copyFitments')} checked={fit} onChange={setFit} />
      </div>
    </Modal>
  );
}
