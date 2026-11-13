import { useTranslation } from 'react-i18next';
import { Badge } from '@/design/components';

type Tone = 'success' | 'danger' | 'navy' | 'orange' | 'warning' | 'neutral';

const TONES: Record<string, Tone> = {
  draft: 'neutral', approved: 'navy', pending_approval: 'warning', partially_received: 'orange', received: 'success', closed: 'neutral', cancelled: 'neutral', posted: 'success',
  rejected: 'danger', in_transit: 'orange', counting: 'orange', review: 'warning',
};

/** One badge for every document status; the label comes from stock.status.<key>. */
export function StatusBadge({ status }: { status: string }) {
  const { t } = useTranslation();
  return <Badge tone={TONES[status] ?? 'neutral'}>{t(`stock.status.${status}`, { defaultValue: status })}</Badge>;
}
