import { forwardRef } from 'react';
import { Tooltip } from './Tooltip';
import { Button, type ButtonProps } from './Button';
import { cn } from '@/lib/cn';

export interface IconButtonProps extends Omit<ButtonProps, 'leftIcon' | 'rightIcon' | 'children'> {
  /** Accessible name; also shown as a tooltip (required by the design spec). */
  label: string;
  children: React.ReactNode;
}

/** 40×40 round icon button; tooltip and aria-label are mandatory. */
export const IconButton = forwardRef<HTMLButtonElement, IconButtonProps>(function IconButton(
  { label, className, variant = 'ghost', children, ...props },
  ref,
) {
  return (
    <Tooltip content={label}>
      <Button
        ref={ref}
        variant={variant}
        size="md"
        aria-label={label}
        className={cn('h-10 w-10 rounded-pill px-0', className)}
        {...props}
      >
        {children}
      </Button>
    </Tooltip>
  );
});
