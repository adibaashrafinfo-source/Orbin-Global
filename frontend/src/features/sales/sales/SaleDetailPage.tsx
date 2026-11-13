import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Ban, Download, FileText, Printer, Receipt, ShieldAlert, Undo2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import { Badge, Button, Card, CardBody, CardHeader, ErrorState, PageHeader, PageSkeleton, toast } from '@/design/components';
import { useSession } from '@/features/auth/useSession';
import { useApiError } from '@/features/catalog/useApiError';
import { fmtQty } from '@/features/stock/format';
import { addMoney, formatMoney, toPaisa } from '@/lib/money';
import { posApi, SALES_KEYS, saleApi } from '../api';
import { fmtDateTime, SALE_TONE } from '../format';
import { downloadBlob, printPdf } from '../pos/files';
import { SalesTabs } from '../SalesTabs';
import type { SaleItem } from '../types';
import { ClaimDialog } from '../warranty/ClaimDialog';
import { ReturnWizard } from './ReturnWizard';
import { VoidDialog } from './VoidDialog';

const today = () => new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Dhaka' });

/** FR-713, PRD §8.4: one invoice with its lines, payments, returns and history, and the actions that apply to it. */
export function SaleDetailPage() {
  const { t, i18n } = useTranslation();
  const { id = '' } = useParams();
  const [search, setSearch] = useSearchParams();
  const { can } = useSession();
  const client = useQueryClient();
  const onError = useApiError();
  const query = useQuery({ queryKey: [...SALES_KEYS.sales, id], queryFn: () => saleApi.show(id) });
  const config = useQuery({ queryKey: SALES_KEYS.config, queryFn: posApi.config, enabled: can(['pos.sell']), staleTime: 60_000 });
  const [claim, setClaim] = useState<SaleItem | null>(null);
  const confirm = useMutation({ mutationFn: (pid: number) => saleApi.confirmPayment(pid), onSuccess: () => { void client.invalidateQueries({ queryKey: SALES_KEYS.sales }); toast.success(t('sales.paymentConfirmed')); }, onError });
  if (query.isLoading) return <PageSkeleton />;
  if (query.isError || !query.data) return <ErrorState onRetry={() => void query.refetch()} />;
  const s = query.data;
  const open = search.get('action');
  const close = () => setSearch({}, { replace: true });
  const live = s.status === 'completed' || s.status === 'partially_returned';
  const canReturn = live && can(['sales.return.create']);
  const canVoid = s.status === 'completed' && can(['sales.void']);
  const save = async (reprint = false) => { try { downloadBlob(await saleApi.pdf(s.id, { reprint }), s.download_name ?? `Invoice_${s.invoice_no}.pdf`); } catch { toast.error(t('errors.unknown')); } };
  const print = async (kind: 'a4' | 'receipt') => { try { printPdf(kind === 'a4' ? await saleApi.pdf(s.id, { reprint: true }) : await (await fetch(saleApi.receiptUrl(s.id), { credentials: 'include' })).blob()); void client.invalidateQueries({ queryKey: SALES_KEYS.sales }); } catch { toast.error(t('errors.unknown')); } };
  const parts = (s.items ?? []).filter((i) => i.bundle_parent_line !== null);
  const lines = (s.items ?? []).filter((i) => i.bundle_parent_line === null);
  const v = s.vehicle_snapshot;
  return (
    <>
      <PageHeader title={s.invoice_no} description={`${fmtDateTime(s.sale_date, i18n.language)} · ${s.cashier ?? ''}`} crumbs={[{ label: t('nav.sales'), to: '/sales' }, { label: s.invoice_no }]}
        actions={<div className="flex flex-wrap gap-2">
          <Button variant="secondary" leftIcon={<Download className="h-4 w-4" aria-hidden="true" />} onClick={() => void save()} data-testid="sale-download">{t('sales.downloadPdf')}</Button>
          <Button variant="secondary" leftIcon={<Printer className="h-4 w-4" aria-hidden="true" />} onClick={() => void print('a4')}>{t('pos.success.printA4')}</Button>
          <Button variant="secondary" leftIcon={<Receipt className="h-4 w-4" aria-hidden="true" />} onClick={() => void print('receipt')}>{t('pos.success.printReceipt')}</Button>
          {canReturn && <Button variant="secondary" leftIcon={<Undo2 className="h-4 w-4" aria-hidden="true" />} onClick={() => setSearch({ action: 'return' })} data-testid="sale-return">{t('sales.return')}</Button>}
          {canVoid && <Button variant="danger" leftIcon={<Ban className="h-4 w-4" aria-hidden="true" />} onClick={() => setSearch({ action: 'void' })} data-testid="sale-void">{t('sales.void')}</Button>}
        </div>} />
      <SalesTabs />
      <div className="grid gap-4 xl:grid-cols-[1fr_20rem]">
        <div className="flex min-w-0 flex-col gap-4">
          <Card><CardBody>
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div>
                <div className="flex items-center gap-2"><Badge tone={SALE_TONE[s.status] ?? 'neutral'}>{t(`sales.status.${s.status}`)}</Badge><Badge tone={SALE_TONE[s.payment_status] ?? 'neutral'}>{t(`sales.pay.${s.payment_status}`)}</Badge></div>
                <p className="mt-2 text-sm"><Link className="font-semibold text-navy-800 hover:underline" to={`/customers/${s.customer_id}`}>{s.customer_snapshot.name}</Link>{s.customer_snapshot.company_name ? ` · ${s.customer_snapshot.company_name}` : ''}</p>
                {v && <p className="text-sm text-ink-muted">{[v.reg_no, v.make, v.model, v.year, v.color].filter(Boolean).join(' · ')}</p>}
              </div>
              {s.status === 'void' && <div role="alert" className="rounded-card border border-danger px-3 py-2 text-sm"><span className="font-semibold text-danger">{t('sales.status.void')}</span> · {s.void_reason}</div>}
            </div>
          </CardBody></Card>
          <Card>
            <CardHeader title={t('sales.lines')} />
            <div className="overflow-x-auto"><table className="w-full text-sm"><caption className="sr-only">{t('sales.lines')}</caption>
              <thead><tr className="text-xs text-ink-muted"><th scope="col" className="px-4 py-2 text-start font-medium">{t('sales.item')}</th><th scope="col" className="text-end font-medium">{t('sales.qty')}</th><th scope="col" className="text-end font-medium">{t('sales.returned')}</th><th scope="col" className="text-end font-medium">{t('sales.unitPrice')}</th><th scope="col" className="text-end font-medium">{t('sales.vat')}</th><th scope="col" className="px-4 text-end font-medium">{t('sales.amount')}</th></tr></thead>
              <tbody>{lines.map((i) => {
                const claimable = live && !!i.warranty_until && i.warranty_until >= today() && can(['sales.return.create']);
                return (
                  <tr key={i.id} className="border-t border-navy-100 align-top">
                    <td className="px-4 py-2"><div className="font-medium">{i.product_name_snapshot}</div><div className="text-xs text-ink-muted">{i.sku_snapshot}{i.warranty_months > 0 ? ` · ${t('sales.warrantyTo', { date: i.warranty_until ?? '' })}` : ''}</div>
                      {parts.filter((p) => p.bundle_parent_line === i.line_no).map((p) => <div key={p.id} className="text-xs text-ink-muted">↳ {fmtQty(p.qty)} × {p.sku_snapshot}</div>)}
                      {claimable && <button type="button" className="mt-1 inline-flex items-center gap-1 text-xs font-semibold text-navy-700 hover:underline focus-visible:focus-ring-light" onClick={() => setClaim(i)}><ShieldAlert className="h-3 w-3" aria-hidden="true" />{t('warranty.raise')}</button>}</td>
                    <td className="tabular text-end">{fmtQty(i.qty)}</td><td className="tabular text-end">{Number(i.qty_returned) > 0 ? fmtQty(i.qty_returned) : '—'}</td>
                    <td className="tabular text-end">{formatMoney(i.unit_price, { symbol: false })}</td><td className="tabular text-end">{Number(i.tax_amount) > 0 ? formatMoney(i.tax_amount, { symbol: false }) : '—'}</td><td className="tabular px-4 text-end font-semibold">{formatMoney(i.line_total, { symbol: false })}</td>
                  </tr>
                );
              })}</tbody></table></div>
            <dl className="ms-auto w-full max-w-xs space-y-1 p-4 text-sm">
              <T l={t('pos.subtotal')} v={formatMoney(s.subtotal)} />
              {toPaisa(addMoney(s.line_discount_total, s.cart_discount_amount)) > 0n && <T l={t('sales.discount')} v={`− ${formatMoney(addMoney(s.line_discount_total, s.cart_discount_amount))}`} />}
              <T l={t('pos.vat')} v={formatMoney(s.tax_total)} />
              {Number(s.delivery_charge) > 0 && <T l={t('pos.delivery')} v={formatMoney(s.delivery_charge)} />}
              {Number(s.round_off) !== 0 && <T l={t('pos.roundOff')} v={formatMoney(s.round_off)} />}
              <T l={t('pos.grandTotal')} v={formatMoney(s.grand_total)} strong />
              <T l={t('sales.paid')} v={formatMoney(s.paid_total)} />
              {Number(s.due_total) > 0 && <T l={t('sales.due')} v={formatMoney(s.due_total)} accent />}
            </dl>
          </Card>
          <Card>
            <CardHeader title={t('sales.payments')} />
            {(s.payments ?? []).length === 0 ? <p className="p-4 text-sm text-ink-muted">{t('sales.noPayments')}</p> : (
              <ul className="divide-y divide-navy-100">{(s.payments ?? []).map((p) => (
                <li key={p.id} className="flex flex-wrap items-center justify-between gap-2 px-4 py-2.5 text-sm">
                  <span><span className="font-semibold">{t(`pos.pay.methods.${p.method}`)}</span><span className="block text-xs text-ink-muted">{p.trx_id ? `${p.trx_id} · ${p.sender_number ?? ''}` : p.bank_ref ? `${p.bank_name ?? ''} · ${p.bank_ref}` : ''}</span></span>
                  <span className="flex items-center gap-2">{p.verification === 'pending' && <><Badge tone="warning">{t('sales.pending')}</Badge>{can(['payments.verify']) && <Button size="sm" variant="secondary" loading={confirm.isPending} onClick={() => confirm.mutate(p.id)}>{t('sales.confirmPayment')}</Button>}</>}<span className="tabular font-semibold">{formatMoney(p.amount)}</span></span>
                </li>))}</ul>
            )}
          </Card>
        </div>
        <div className="flex flex-col gap-4">
          <Card><CardHeader title={t('sales.history')} /><ol className="space-y-2 p-4 pt-0 text-sm">
            {(s.timeline ?? []).map((e, i) => <li key={`${e.occurred_at}-${i}`} className="flex flex-col"><span className="font-medium">{t(`sales.events.${e.action}`, { defaultValue: e.action })}</span><span className="text-xs text-ink-muted">{fmtDateTime(e.occurred_at, i18n.language)}{e.reason ? ` · ${e.reason}` : ''}</span></li>)}
            {s.reprint_count > 0 && <li className="text-xs text-ink-muted">{t('sales.reprints', { count: s.reprint_count })}</li>}
          </ol></Card>
          {(s.returns ?? []).length > 0 && <Card><CardHeader title={t('sales.returnsTitle')} /><ul className="divide-y divide-navy-100">{(s.returns ?? []).map((r) => <li key={r.id} className="flex items-center justify-between px-4 py-2 text-sm"><Link className="font-semibold text-navy-800 hover:underline" to={`/sales/returns/${r.id}`}>{r.return_no}</Link><Badge tone={SALE_TONE[r.status] ?? 'neutral'}>{t(`sales.returnStatus.${r.status}`)}</Badge></li>)}</ul></Card>}
          <Card><CardBody><a className="inline-flex items-center gap-2 text-sm font-semibold text-navy-800 hover:underline" href={saleApi.pdfUrl(s.id)} target="_blank" rel="noopener noreferrer"><FileText className="h-4 w-4" aria-hidden="true" />{t('sales.openPdf')}</a><p className="mt-2 text-xs text-ink-muted">{t('sales.verifyNote')}</p></CardBody></Card>
        </div>
      </div>
      <ReturnWizard sale={s} config={config.data} open={open === 'return'} onClose={close} onDone={() => close()} />
      <VoidDialog sale={s} open={open === 'void'} onClose={close} />
      <ClaimDialog item={claim} onClose={() => setClaim(null)} />
    </>
  );
}

function T({ l, v, strong, accent }: { l: string; v: string; strong?: boolean; accent?: boolean }) {
  return <div className="flex justify-between"><dt className="text-ink-muted">{l}</dt><dd className={`tabular font-medium ${strong ? 'text-base font-extrabold text-navy-900' : ''} ${accent ? 'text-orange-700' : ''}`}>{v}</dd></div>;
}
