import { Delete } from 'lucide-react';
import { useTranslation } from 'react-i18next';

const KEYS = ['7', '8', '9', '4', '5', '6', '1', '2', '3', '00', '0', '.'] as const;

/** Large on-screen keypad for the cash tendered (touch tills). Typing on a real keyboard works in the field as well. */
export function Keypad({ value, onChange }: { value: string; onChange: (v: string) => void }) {
  const { t } = useTranslation();
  const press = (k: string) => {
    if (k === '.' && value.includes('.')) return;
    const next = value === '' && k === '.' ? '0.' : value + k;
    if (/^\d{0,9}(\.\d{0,2})?$/.test(next)) onChange(next.replace(/^0+(?=\d)/, ''));
  };
  return (
    <div role="group" aria-label={t('pos.pay.keypad')} className="grid grid-cols-3 gap-2">
      {KEYS.map((k) => (
        <button key={k} type="button" onClick={() => press(k)} className="tabular h-12 rounded-button border border-navy-200 bg-surface text-lg font-semibold text-navy-900 hover:bg-navy-50 active:bg-navy-100 focus-visible:focus-ring-light">{k}</button>
      ))}
      <button type="button" aria-label={t('pos.pay.backspace')} onClick={() => onChange(value.slice(0, -1))} className="col-span-3 flex h-10 items-center justify-center rounded-button border border-navy-200 text-navy-700 hover:bg-navy-50 focus-visible:focus-ring-light"><Delete className="h-5 w-5" aria-hidden="true" /></button>
    </div>
  );
}
