import { motion, useReducedMotion } from 'framer-motion';
import { Check, Download, MessageCircle, Printer, Receipt, ShoppingCart } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import { Button, toast } from '@/design/components';
import { formatMoney } from '@/lib/money';
import { saleApi } from '../api';
import type { PosConfig, Sale } from '../types';
import { downloadBlob, printPdf } from './files';

type FileState = 'working' | 'done' | 'failed';

/** FR-712: invoice number, amount and change, the PDF saved by itself, and every follow-up in one place. */
export function SuccessScreen({ sale, config, onNewSale }: { sale: Sale; config: PosConfig; onNewSale: () => void }) {
  const { t } = useTranslation();
  const reduced = useReducedMotion();
  const [file, setFile] = useState<FileState>(config.settings.pos_auto_download_invoice ? 'working' : 'done');
  const [secondsLeft, setSecondsLeft] = useState(config.settings.pos_auto_reset_seconds);
  const started = useRef(false);
  const name = sale.download_name ?? `Invoice_${sale.invoice_no}.pdf`;

  const download = async (): Promise<void> => {
    try {
      downloadBlob(await saleApi.pdf(sale.id), name);
      setFile('done');
    } catch {
      setFile('failed');
      toast.warning(t('pos.success.downloadFailed'));
    }
  };
  useEffect(() => {
    if (started.current || !config.settings.pos_auto_download_invoice) return;
    started.current = true;
    void (async () => {
      try {
        downloadBlob(await saleApi.pdf(sale.id), name);
        setFile('done');
        toast.success(t('pos.success.downloaded', { name }));
      } catch {
        setFile('failed');
      }
    })();
  }, [sale.id, name, config.settings.pos_auto_download_invoice, t]);
  useEffect(() => {
    if (secondsLeft <= 0) return;
    const id = setTimeout(() => (secondsLeft === 1 ? onNewSale() : setSecondsLeft(secondsLeft - 1)), 1000);
    return () => clearTimeout(id);
  }, [secondsLeft, onNewSale]);

  const print = async (kind: 'a4' | 'receipt'): Promise<void> => {
    try {
      printPdf(kind === 'a4' ? await saleApi.pdf(sale.id, { reprint: true }) : await (await fetch(saleApi.receiptUrl(sale.id), { credentials: 'include' })).blob());
    } catch {
      toast.error(t('errors.unknown'));
    }
  };
  const change = Number(sale.change_given) > 0 ? sale.change_given : null;
  const phone = sale.customer_snapshot.is_walk_in ? '' : (sale.customer_snapshot.phones?.[0] ?? '');
  const text = t('pos.success.shareText', { no: sale.invoice_no, amount: formatMoney(sale.grand_total), link: `${window.location.origin}/verify/${sale.verify_token}` });
  const wa = `https://wa.me/${phone ? `88${phone.replace(/^\+?88/, '')}` : ''}?text=${encodeURIComponent(text)}`;
  return (
    <div role="dialog" aria-modal="true" aria-label={t('pos.success.title')} className="fixed inset-0 z-[60] flex items-center justify-center bg-navy-900/80 p-4" data-testid="success-screen">
      <motion.div initial={reduced ? false : { opacity: 0, scale: 0.96 }} animate={{ opacity: 1, scale: 1 }} transition={{ duration: 0.2 }} className="mirror-shine w-full max-w-md rounded-modal bg-surface p-8 text-center shadow-lg">
        <motion.div initial={reduced ? false : { scale: 0.4, opacity: 0 }} animate={{ scale: 1, opacity: 1 }} transition={{ type: 'spring', stiffness: 260, damping: 16 }} className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-pill bg-success text-surface"><Check className="h-9 w-9" strokeWidth={3} aria-hidden="true" /></motion.div>
        <h2 className="font-heading text-xl font-bold text-navy-900">{t('pos.success.title')}</h2>
        <p className="mt-1 text-sm text-ink-muted" data-testid="success-invoice">{sale.invoice_no}</p>
        <p className="tabular my-3 text-3xl font-extrabold text-navy-900" data-testid="success-amount">{formatMoney(sale.grand_total)}</p>
        {change && <p className="tabular mb-1 text-lg font-bold text-orange-700" data-testid="success-change">{t('pos.success.change', { amount: formatMoney(change) })}</p>}
        {Number(sale.due_total) > 0 && <p className="mb-1 text-sm font-semibold text-orange-700">{t('pos.success.due', { amount: formatMoney(sale.due_total) })}</p>}
        <p role="status" className="mb-5 text-xs text-ink-muted">{file === 'working' ? t('pos.success.downloading') : file === 'failed' ? t('pos.success.downloadFailed') : t('pos.success.ready', { name })}</p>
        <div className="grid grid-cols-2 gap-2">
          <Button variant="secondary" leftIcon={<Download className="h-4 w-4" aria-hidden="true" />} onClick={() => void download()} data-testid="download-again">{file === 'failed' ? t('pos.success.download') : t('pos.success.downloadAgain')}</Button>
          <Button variant="secondary" leftIcon={<Printer className="h-4 w-4" aria-hidden="true" />} onClick={() => void print('a4')}>{t('pos.success.printA4')}</Button>
          <Button variant="secondary" leftIcon={<Receipt className="h-4 w-4" aria-hidden="true" />} onClick={() => void print('receipt')}>{t('pos.success.printReceipt')}</Button>
          <a href={wa} target="_blank" rel="noopener noreferrer" className="inline-flex h-10 items-center justify-center gap-2 rounded-button border border-navy-200 bg-surface px-4 text-sm font-semibold text-navy-900 hover:bg-navy-50 focus-visible:focus-ring-light"><MessageCircle className="h-4 w-4" aria-hidden="true" />{t('pos.success.whatsapp')}</a>
        </div>
        <div className="mt-2 flex gap-2">
          <Link to={`/sales/${sale.id}`} className="inline-flex h-10 flex-1 items-center justify-center rounded-button text-sm font-semibold text-navy-700 hover:bg-navy-100 focus-visible:focus-ring-light">{t('pos.success.view')}</Link>
          <Button ref={(el) => el?.focus()} className="flex-1" leftIcon={<ShoppingCart className="h-4 w-4" aria-hidden="true" />} onClick={onNewSale} data-testid="new-sale">{t('pos.success.newSale')}{secondsLeft > 0 ? ` (${secondsLeft})` : ''}</Button>
        </div>
      </motion.div>
    </div>
  );
}
