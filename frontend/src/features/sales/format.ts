/** Date and time in the shop's timezone (BR-090), in the language of the screen. */
export function fmtDateTime(iso: string | null | undefined, lang: string, withTime = true): string {
  if (!iso) return '—';
  return new Date(iso).toLocaleString(lang === 'bn' ? 'bn-BD' : 'en-GB', {
    timeZone: 'Asia/Dhaka', day: '2-digit', month: 'short', year: 'numeric', ...(withTime ? { hour: '2-digit', minute: '2-digit' } : {}),
  });
}

export const SALE_TONE: Record<string, 'success' | 'danger' | 'navy' | 'orange' | 'warning' | 'neutral'> = {
  completed: 'success', void: 'danger', partially_returned: 'orange', returned: 'neutral', held: 'neutral', draft: 'neutral',
  paid: 'success', partial: 'orange', unpaid: 'danger',
  pending_approval: 'warning', posted: 'success', cancelled: 'neutral',
  sent: 'navy', accepted: 'success', expired: 'neutral', converted: 'navy',
  open: 'orange', in_review: 'warning', replaced: 'success', refunded: 'navy', rejected: 'danger', closed: 'neutral',
};
