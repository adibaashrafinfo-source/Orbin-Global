import { Download, Plus } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { toast, Button, Card, CardBody } from '@/design/components';
import { downloadBlob } from '@/features/sales/pos/files';
import { formatMoney } from '@/lib/money';
import { paymentApi } from './api';
import type { PartyPayment } from './types';

/** What was recorded, with the receipt one tap away. */
export function PaymentSuccess({ payment, onAnother }: { payment: PartyPayment; onAnother: () => void }) {
  const { t } = useTranslation();
  const receive = payment.direction === 'receive';
  const save = async () => {
    try { downloadBlob(await paymentApi.receipt(payment.id), `${payment.payment_no}.pdf`); } catch { toast.error(t('ledger.receiptFailed')); }
  };
  return (
    <Card className="mirror-shine" data-testid="payment-success">
      <CardBody className="flex flex-col items-center gap-3 py-8 text-center">
        <p className="text-sm font-medium text-ink-muted">{t(receive ? 'ledger.received' : 'ledger.paid')}</p>
        <p className="font-heading text-3xl font-bold tabular text-navy-900">{formatMoney(payment.amount)}</p>
        <p className="text-sm"><span className="font-semibold">{payment.payment_no}</span> · {payment.party?.name}</p>
        <p className="text-sm text-ink-muted">{t('ledger.successSplit', { applied: formatMoney(payment.allocated_total), advance: formatMoney(payment.advance_amount) })}</p>
        <div className="mt-2 flex flex-wrap justify-center gap-2">
          <Button variant="secondary" leftIcon={<Download className="h-4 w-4" aria-hidden="true" />} onClick={() => void save()}>{t('ledger.downloadReceipt')}</Button>
          <Button leftIcon={<Plus className="h-4 w-4" aria-hidden="true" />} onClick={onAnother}>{t('ledger.recordAnother')}</Button>
        </div>
      </CardBody>
    </Card>
  );
}
