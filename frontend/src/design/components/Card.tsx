import type { HTMLAttributes, ReactNode } from 'react';
import { cn } from '@/lib/cn';

export function Card({ className, interactive, ...props }: HTMLAttributes<HTMLDivElement> & { interactive?: boolean }) {
  return <div className={cn(interactive ? 'card-interactive' : 'card', className)} {...props} />;
}

export function CardHeader({ title, description, actions, className }: { title: ReactNode; description?: ReactNode; actions?: ReactNode; className?: string }) {
  return (
    <div className={cn('flex items-start justify-between gap-4 border-b border-navy-100 px-5 py-4', className)}>
      <div>
        <h2 className="font-heading text-base font-bold text-navy-900">{title}</h2>
        {description && <p className="mt-0.5 text-13 text-ink-muted">{description}</p>}
      </div>
      {actions}
    </div>
  );
}

export function CardBody({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
  return <div className={cn('px-5 py-4', className)} {...props} />;
}
