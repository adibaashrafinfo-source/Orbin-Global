import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Button, Input, Modal, Select } from '@/design/components';
import { useDerivedState } from '@/features/catalog/util';
import { customerApi, SALES_KEYS } from '../api';
import type { CartVehicle } from './cartStore';
import { EMPTY_VEHICLE } from './cartStore';

const NEW = '__new__';

/** FR-706: pick one of the customer's cars, or type the registration, make, model and year for a one-off. The invoice prints it. */
export function VehicleDialog({ open, customerId, value, onClose, onSave }: { open: boolean; customerId: number | null; value: CartVehicle; onClose: () => void; onSave: (v: CartVehicle) => void }) {
  const { t } = useTranslation();
  const [v, setV] = useDerivedState<CartVehicle>(open, () => value);
  const customer = useQuery({ queryKey: [...SALES_KEYS.customers, customerId], queryFn: () => customerApi.show(customerId ?? 0), enabled: open && customerId !== null });
  const cars = customer.data?.vehicles ?? [];
  const set = (k: keyof CartVehicle, x: string) => setV((s) => ({ ...s, [k]: x, customer_vehicle_id: null }));
  const pick = (id: string) => {
    if (id === NEW) return setV(EMPTY_VEHICLE);
    const c = cars.find((x) => String(x.id) === id);
    if (c) setV({ customer_vehicle_id: c.id, reg_no: c.reg_no, make: c.make ?? '', model: c.model ?? '', year: c.year ? String(c.year) : '', color: c.color ?? '' });
  };
  return (
    <Modal open={open} onOpenChange={(o) => !o && onClose()} className="max-w-lg" title={t('pos.vehicle')}
      footer={<><Button variant="ghost" onClick={() => { onSave(EMPTY_VEHICLE); onClose(); }}>{t('pos.noVehicle')}</Button><Button variant="secondary" onClick={onClose}>{t('common.cancel')}</Button><Button onClick={() => { onSave(v); onClose(); }}>{t('common.save')}</Button></>}>
      <div className="grid gap-3 sm:grid-cols-2">
        {cars.length > 0 && <div className="sm:col-span-2"><Select label={t('pos.savedVehicles')} value={v.customer_vehicle_id ? String(v.customer_vehicle_id) : NEW} onChange={pick} options={[{ value: NEW, label: t('pos.otherVehicle') }, ...cars.map((c) => ({ value: String(c.id), label: `${c.reg_no} · ${[c.make, c.model, c.year].filter(Boolean).join(' ')}` }))]} /></div>}
        <Input label={t('customers.vehicle.reg')} value={v.reg_no} onChange={(e) => set('reg_no', e.target.value)} />
        <Input label={t('customers.vehicle.color')} value={v.color} onChange={(e) => set('color', e.target.value)} />
        <Input label={t('customers.vehicle.make')} value={v.make} onChange={(e) => set('make', e.target.value)} />
        <Input label={t('customers.vehicle.model')} value={v.model} onChange={(e) => set('model', e.target.value)} />
        <Input label={t('customers.vehicle.year')} inputMode="numeric" maxLength={4} value={v.year} onChange={(e) => set('year', e.target.value.replace(/\D/g, ''))} />
      </div>
    </Modal>
  );
}
