import * as RadixLabel from '@radix-ui/react-label';
import type { ComponentPropsWithoutRef } from 'react';
import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/cn';

export function Label({ className, required, children, ...props }: ComponentPropsWithoutRef<typeof RadixLabel.Root> & { required?: boolean }) {
  const { t } = useTranslation();
  return (
    <RadixLabel.Root className={cn('text-13 font-medium text-ink-muted', className)} {...props}>
      {children}
      {required && (
        <span className="ml-0.5 text-orange-700" aria-label={t('a11y.requiredField')}>
          *
        </span>
      )}
    </RadixLabel.Root>
  );
}
