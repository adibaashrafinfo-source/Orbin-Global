import { cva, type VariantProps } from 'class-variance-authority';
import type { HTMLAttributes } from 'react';
import { cn } from '@/lib/cn';

/** Soft *-100 background with *-800 text (designSystem.components.badge). */
export const badgeVariants = cva('inline-flex items-center gap-1 rounded-pill px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap', {
  variants: {
    tone: {
      navy: 'bg-navy-100 text-navy-800',
      orange: 'bg-orange-100 text-orange-800',
      success: 'bg-success/10 text-ink',
      warning: 'bg-warning/10 text-ink',
      danger: 'bg-danger/10 text-ink',
      neutral: 'bg-navy-50 text-ink-muted',
    },
    pulse: { true: 'animate-pulse-glow shadow-glow-orange', false: '' },
  },
  defaultVariants: { tone: 'navy', pulse: false },
});

export function Badge({ className, tone, pulse, ...props }: HTMLAttributes<HTMLSpanElement> & VariantProps<typeof badgeVariants>) {
  return <span className={cn(badgeVariants({ tone, pulse }), className)} {...props} />;
}

export type StockState = 'in' | 'low' | 'out';

/** Stock chip: In stock (green), Low (orange pulse), Out (red). */
export function StockBadge({ state, label }: { state: StockState; label: string }) {
  const tone = state === 'in' ? 'success' : state === 'low' ? 'orange' : 'danger';
  return (
    <Badge tone={tone} pulse={state === 'low'}>
      {label}
    </Badge>
  );
}
