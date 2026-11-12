import type { ReactNode } from 'react';

/** Keeps clicks on row controls (switches, icon buttons) from also triggering the table row's own click. */
export function StopClick({ children, className }: { children: ReactNode; className?: string }) {
  return (
    <div role="presentation" className={className} onClick={(e) => e.stopPropagation()}>
      {children}
    </div>
  );
}
