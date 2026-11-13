import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { ChevronRight } from 'lucide-react';

export interface Crumb {
  label: ReactNode;
  to?: string;
}

/** Page header: title + breadcrumb + the page's actions (one accent button per view at most). */
export function PageHeader({ title, description, crumbs, actions }: { title: ReactNode; description?: ReactNode; crumbs?: Crumb[]; actions?: ReactNode }) {
  return (
    <header className="mb-6 flex flex-wrap items-end justify-between gap-4">
      <div>
        {crumbs && crumbs.length > 0 && (
          <nav aria-label="breadcrumb" className="mb-1 flex items-center gap-1 text-xs text-ink-muted">
            {crumbs.map((c, i) => (
              <span key={i} className="flex items-center gap-1">
                {c.to ? (
                  <Link to={c.to} className="rounded hover:text-navy-700 focus-visible:focus-ring-light">
                    {c.label}
                  </Link>
                ) : (
                  <span>{c.label}</span>
                )}
                {i < crumbs.length - 1 && <ChevronRight className="h-3 w-3" aria-hidden="true" />}
              </span>
            ))}
          </nav>
        )}
        <h1 className="font-heading text-2xl font-bold text-navy-900">{title}</h1>
        {description && <p className="mt-1 max-w-2xl text-13 text-ink-muted">{description}</p>}
      </div>
      {actions && <div className="flex items-center gap-2">{actions}</div>}
    </header>
  );
}
