import { motion, useReducedMotion } from 'framer-motion';
import { Camera, Flame, Lightbulb, Moon, Radar, Save, Zap } from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge, StockBadge } from '@/design/components';
import { cn } from '@/lib/cn';
import { formatMoney } from '@/lib/money';
import { fmtQty } from '@/features/stock/format';
import type { PosCard, PosVariant } from '../types';
import { chipLabel } from './helpers';

const FEATURE_ICON: Record<string, ReactNode> = {
  heated: <Flame className="h-3.5 w-3.5" aria-hidden="true" />,
  turn_signal: <Lightbulb className="h-3.5 w-3.5" aria-hidden="true" />,
  blind_spot_sensor: <Radar className="h-3.5 w-3.5" aria-hidden="true" />,
  side_camera: <Camera className="h-3.5 w-3.5" aria-hidden="true" />,
  auto_dimming: <Moon className="h-3.5 w-3.5" aria-hidden="true" />,
  memory_tilt: <Save className="h-3.5 w-3.5" aria-hidden="true" />,
  auto_fold: <Zap className="h-3.5 w-3.5" aria-hidden="true" />,
  puddle_light: <Lightbulb className="h-3.5 w-3.5" aria-hidden="true" />,
};

const state = (available: string): 'in' | 'low' | 'out' => (Number(available) <= 0 ? 'out' : Number(available) <= 3 ? 'low' : 'in');

/** FR-704: one product, every fitting size as a chip with its live stock. Out-of-stock chips are visibly disabled. */
export function ProductCard({ card, onAdd, onOpen }: { card: PosCard; onAdd: (v: PosVariant) => void; onOpen: () => void }) {
  const { t } = useTranslation();
  const reduced = useReducedMotion();
  const total = card.variants.reduce((a, v) => a + Number(v.available), 0);
  const features = [...new Set(card.variants.flatMap((v) => v.features))].slice(0, 5);
  const price = card.price_from === card.price_to ? formatMoney(card.price_from) : `${formatMoney(card.price_from, { symbol: false })} – ${formatMoney(card.price_to)}`;
  return (
    <motion.article whileHover={reduced ? undefined : { y: -2 }} className="mirror-shine flex flex-col rounded-card border border-navy-100 bg-surface shadow-sm" aria-label={card.name}>
      <button type="button" onClick={onOpen} className="group block text-start focus-visible:focus-ring-light" aria-label={t('pos.quickView', { name: card.name })}>
        <div className="h-28 w-full overflow-hidden rounded-t-card bg-navy-50">
          {card.image ? <img src={card.image} alt="" loading="lazy" className={cn('h-full w-full object-cover transition-transform duration-base', !reduced && 'group-hover:scale-[1.03]')} /> : <div className="flex h-full items-center justify-center text-xs text-ink-muted">{t('pos.noImage')}</div>}
        </div>
        <div className="flex flex-col gap-1.5 p-3 pb-2">
          <h3 className="line-clamp-2 text-sm font-semibold leading-snug text-navy-900">{card.name}</h3>
          <div className="flex flex-wrap items-center gap-1.5">
            {card.brand && <span className="text-xs text-ink-muted">{card.brand}</span>}
            {card.quality && <Badge tone="navy" className="max-w-full whitespace-normal text-start">{card.quality}</Badge>}
            {features.length > 0 && <span className="ms-auto flex gap-1 text-navy-600" aria-label={t('pos.features')}>{features.map((f) => <span key={f} title={t(`products.features.${f}`, { defaultValue: f })}>{FEATURE_ICON[f]}</span>)}</span>}
          </div>
        </div>
      </button>
      <ul className="flex flex-wrap gap-1.5 px-3 pb-2" aria-label={t('pos.sizes')}>
        {card.variants.map((v) => {
          const out = Number(v.available) <= 0;
          return (
            <li key={v.id}>
              <button type="button" disabled={out} onClick={() => onAdd(v)} title={out ? t('pos.outOfStockHint') : undefined}
                aria-label={out ? t('pos.chipOut', { size: chipLabel(v) }) : t('pos.chipAdd', { size: chipLabel(v), qty: fmtQty(v.available) })}
                className={cn('flex flex-col items-center rounded-input border px-2 py-1 text-xs font-semibold leading-tight transition-colors duration-fast focus-visible:focus-ring-light', out ? 'cursor-not-allowed border-navy-100 bg-navy-50 text-ink-muted line-through' : 'border-navy-200 bg-surface text-navy-900 hover:border-orange-500 hover:bg-orange-50')}>
                <span className="whitespace-nowrap">{chipLabel(v)}</span>
                <span className={cn('tabular whitespace-nowrap font-normal', out ? 'text-danger no-underline' : Number(v.available) <= 3 ? 'text-orange-700' : 'text-ink-muted')}>{out ? t('pos.out') : fmtQty(v.available)}</span>
              </button>
            </li>
          );
        })}
      </ul>
      <div className="mt-auto flex flex-wrap items-center justify-between gap-x-2 gap-y-1 border-t border-navy-100 px-3 py-2">
        <span className="tabular min-w-0 break-words text-sm font-bold text-navy-900">{price}</span>
        <StockBadge state={total <= 0 ? 'out' : state(String(total))} label={total <= 0 ? t('pos.out') : t('pos.inStock', { qty: fmtQty(String(total)) })} />
      </div>
    </motion.article>
  );
}
