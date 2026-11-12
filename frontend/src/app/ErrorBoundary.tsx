import { Component, type ErrorInfo, type ReactNode } from 'react';
import i18n from '@/lib/i18n';

interface State {
  error: Error | null;
  errorId: string;
}

/** Friendly error page with a copyable error id (PRD §8.13). */
export class ErrorBoundary extends Component<{ children: ReactNode }, State> {
  state: State = { error: null, errorId: '' };

  static getDerivedStateFromError(error: Error): State {
    return { error, errorId: Math.random().toString(36).slice(2, 10).toUpperCase() };
  }

  componentDidCatch(error: Error, info: ErrorInfo): void {
    console.error('[orbin] ui error', this.state.errorId, error, info.componentStack);
  }

  render(): ReactNode {
    if (!this.state.error) return this.props.children;
    const t = i18n.t.bind(i18n);
    return (
      <div className="flex min-h-screen items-center justify-center bg-background p-6">
        <div className="card max-w-md p-8 text-center" role="alert">
          <h1 className="font-heading text-xl font-bold text-navy-900">{t('errors.boundaryTitle')}</h1>
          <p className="mt-2 text-sm text-ink-muted">{t('errors.boundaryBody')}</p>
          <p className="mt-4 text-xs text-ink-subtle">
            {t('errors.requestId')}: <code className="rounded bg-navy-50 px-1">{this.state.errorId}</code>
          </p>
          <div className="mt-6 flex justify-center gap-2">
            <button type="button" className="rounded-button border border-navy-200 px-4 py-2 text-sm font-semibold text-navy-900 hover:bg-navy-50" onClick={() => void navigator.clipboard.writeText(this.state.errorId)}>
              {t('common.copy')}
            </button>
            <button type="button" className="rounded-button bg-navy-900 px-4 py-2 text-sm font-semibold text-surface" onClick={() => window.location.reload()}>
              {t('errors.reload')}
            </button>
          </div>
        </div>
      </div>
    );
  }
}
