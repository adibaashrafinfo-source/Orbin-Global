import { forwardRef, type ButtonHTMLAttributes, type ReactNode } from 'react';
import { cva, type VariantProps } from 'class-variance-authority';
import { motion, useReducedMotion } from 'framer-motion';
import { Loader2 } from 'lucide-react';
import { cn } from '@/lib/cn';
import { pressTap, springSoft } from '../motion';

/**
 * Button per designSystem.components.button. Variants: primary (navy gradient, white text),
 * accent (orange gradient, NAVY text — never white on orange; max one per view), secondary, ghost, danger.
 * States: default, hover, focus-visible, pressed (scale .97 + spring), loading (label morphs into spinner
 * without width change), disabled.
 */
export const buttonVariants = cva(
  'relative inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-button font-semibold select-none transition-[box-shadow,background-color,transform] duration-base ease-standard focus-visible:focus-ring-light disabled:opacity-50 disabled:cursor-not-allowed disabled:pointer-events-none',
  {
    variants: {
      variant: {
        primary: 'mirror-shine bg-gradient-to-b from-navy-800 to-navy-900 text-surface shadow-md hover:from-navy-700 hover:to-navy-900 hover:shadow-lg',
        accent: 'mirror-shine bg-gradient-to-b from-orange-400 to-orange-500 text-navy-900 shadow-md hover:shadow-glow-orange',
        secondary: 'bg-surface border border-navy-200 text-navy-900 hover:bg-navy-50',
        ghost: 'bg-transparent text-navy-700 hover:bg-navy-100',
        danger: 'bg-gradient-to-b from-danger to-danger text-surface shadow-sm hover:brightness-95',
      },
      size: {
        sm: 'h-8 px-3 text-13',
        md: 'h-10 px-4 text-sm',
        lg: 'h-12 px-5 text-base',
        xl: 'h-14 px-6 text-lg',
      },
      block: { true: 'w-full', false: '' },
    },
    defaultVariants: { variant: 'primary', size: 'md', block: false },
  },
);

export interface ButtonProps
  extends Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'onAnimationStart' | 'onDragStart' | 'onDragEnd' | 'onDrag'>,
    VariantProps<typeof buttonVariants> {
  loading?: boolean;
  leftIcon?: ReactNode;
  rightIcon?: ReactNode;
}

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
  { className, variant, size, block, loading = false, disabled, leftIcon, rightIcon, children, type = 'button', ...props },
  ref,
) {
  const reduced = useReducedMotion();
  const isDisabled = disabled || loading;
  return (
    <motion.button
      ref={ref}
      type={type}
      className={cn(buttonVariants({ variant, size, block }), loading && 'disabled:cursor-wait disabled:opacity-100', className)}
      disabled={isDisabled}
      aria-busy={loading || undefined}
      whileTap={reduced || isDisabled ? undefined : pressTap}
      transition={springSoft}
      {...props}
    >
      <span className={cn('inline-flex items-center gap-2', loading && 'invisible')}>
        {leftIcon}
        {children}
        {rightIcon}
      </span>
      {loading && (
        <span className="absolute inset-0 flex items-center justify-center" aria-hidden="true">
          <Loader2 className="h-5 w-5 animate-spin motion-reduce:animate-none" />
        </span>
      )}
    </motion.button>
  );
});
