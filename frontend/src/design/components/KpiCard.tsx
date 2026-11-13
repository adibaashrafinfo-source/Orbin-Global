import { animate, useReducedMotion } from 'framer-motion';
import { useEffect, useRef, type ReactNode } from 'react';
import { cn } from '@/lib/cn';

export interface KpiCardProps {
  label: ReactNode;
  value: string;
  /** Numeric value used for the 600 ms count-up; the formatted `value` is shown once the animation ends. */
  numeric?: number;
  formatter?: (n: number) => string;
  delta?: { value: string; positive: boolean } | undefined;
  icon?: ReactNode;
  /** A small extra under the figure, such as a sparkline. */
  footer?: ReactNode;
  className?: string;
}

/** KPI card with count-up (600 ms) and Mirror Shine on hover. */
export function KpiCard({ label, value, numeric, formatter, delta, icon, footer, className }: KpiCardProps) {
  const ref = useRef<HTMLSpanElement>(null);
  const reduced = useReducedMotion();
  useEffect(() => {
    const el = ref.current;
    if (!el) return;
    if (numeric === undefined || reduced) {
      el.textContent = value;
      return;
    }
    const controls = animate(0, numeric, {
      duration: 0.6,
      ease: 'easeOut',
      onUpdate: (v) => {
        el.textContent = formatter ? formatter(v) : Math.round(v).toLocaleString();
      },
      onComplete: () => {
        el.textContent = value;
      },
    });
    return () => controls.stop();
  }, [numeric, value, formatter, reduced]);

  return (
    <div className={cn('card mirror-shine flex flex-col gap-2 p-5', className)}>
      <div className="flex items-center justify-between text-13 font-medium text-ink-muted">
        <span>{label}</span>
        {icon && <span className="text-navy-400">{icon}</span>}
      </div>
      <span ref={ref} className="font-heading text-2xl font-bold tabular text-navy-900">
        {value}
      </span>
      {delta && (
        <span className={cn('text-xs font-medium', delta.positive ? 'text-success' : 'text-danger')}>
          {delta.positive ? '▲' : '▼'} {delta.value}
        </span>
      )}
      {footer}
    </div>
  );
}
