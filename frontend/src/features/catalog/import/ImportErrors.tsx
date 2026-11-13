import { useTranslation } from 'react-i18next';
import type { ImportSummary } from '../api';

/** Row-level problems from a dry run: row number, column, what is wrong and the offending value. */
export function ImportErrors({ summary }: { summary: ImportSummary }) {
  const { t } = useTranslation();
  return (
    <div>
      <div className="overflow-x-auto rounded-card border border-navy-100">
        <table className="w-full text-sm">
          <caption className="sr-only">{t('import.errorsCaption')}</caption>
          <thead className="bg-navy-900 text-xs uppercase tracking-wide text-surface"><tr>
            <th scope="col" className="px-3 py-2 text-start">{t('import.row')}</th><th scope="col" className="px-3 py-2 text-start">{t('import.column')}</th>
            <th scope="col" className="px-3 py-2 text-start">{t('import.problem')}</th><th scope="col" className="px-3 py-2 text-start">{t('import.value')}</th>
          </tr></thead>
          <tbody>
            {summary.errors.map((e, i) => (
              <tr key={`${e.row}-${e.field}-${i}`} className="border-t border-navy-100 odd:bg-navy-50/40">
                <td className="tabular px-3 py-1.5">{e.row}</td><td className="px-3 py-1.5"><code className="text-xs">{e.field}</code></td>
                <td className="px-3 py-1.5">{t(`import.codes.${e.code}`, { defaultValue: e.code })}</td><td className="px-3 py-1.5 text-ink-muted">{e.value ?? '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {summary.errors_truncated && <p className="mt-2 text-xs text-ink-muted">{t('import.truncated', { count: summary.error_count })}</p>}
    </div>
  );
}
