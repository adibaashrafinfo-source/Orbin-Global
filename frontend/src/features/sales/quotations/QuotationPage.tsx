import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { FileText, ShoppingCart } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { Badge, Button, Card, CardBody, ErrorState, PageHeader, PageSkeleton, toast } from '@/design/components';
import { useApiError } from '@/features/catalog/useApiError';
import { fmtQty } from '@/features/stock/format';
import { formatMoney } from '@/lib/money';
import { posApi, quotationApi, SALES_KEYS } from '../api';
import { fmtDateTime, SALE_TONE } from '../format';
import { type CartLine, type LoadedCart, EMPTY_VEHICLE, useCart } from '../pos/cartStore';
import { toCartCustomer } from '../pos/helpers';
import { customerApi } from '../api';
import { SalesTabs } from '../SalesTabs';

/** One quotation, with the actions that apply: send, accept, cancel, and "make a sale", which checks stock and fills the POS cart. */
export function QuotationPage() {
  const { t, i18n } = useTranslation();
  const { id = '' } = useParams();
  const navigate = useNavigate();
  const client = useQueryClient();
  const onError = useApiError();
  const query = useQuery({ queryKey: [...SALES_KEYS.quotations, id], queryFn: () => quotationApi.show(id) });
  const status = useMutation({ mutationFn: (s: string) => quotationApi.status(Number(id), s), onSuccess: () => void client.invalidateQueries({ queryKey: SALES_KEYS.quotations }), onError });
  const convert = useMutation({
    mutationFn: async () => {
      const cfg = await posApi.config();
      const wh = cfg.warehouses.find((w) => w.is_default)?.id ?? cfg.warehouses[0]?.id ?? 0;
      const out = await quotationApi.convert(Number(id), wh);
      const cust = out.cart.customer_id ? await customerApi.show(out.cart.customer_id) : await customerApi.show(cfg.walk_in_customer_id);
      return { out, cust };
    },
    onSuccess: ({ out, cust }) => {
      const lines: CartLine[] = out.cart.lines.flatMap((l) => {
        const it = out.items[String(l.variant_id)];
        return it ? [{ key: crypto.randomUUID(), variant: { ...it.variant, product: it.product.name, image: it.product.image, warranty_months: it.product.warranty_months }, qty: l.qty.replace(/\.?0+$/, '') || '1', unitPrice: l.unit_price, discountType: l.discount_type as CartLine['discountType'], discountValue: l.discount_value, note: '' }] : [];
      });
      const v = out.cart.vehicle;
      const cart: LoadedCart = { lines, customer: toCartCustomer(cust), vehicle: v ? { ...EMPTY_VEHICLE, reg_no: v.reg_no ?? '', make: v.make ?? '', model: v.model ?? '', year: v.year ?? '', color: v.color ?? '' } : EMPTY_VEHICLE, channel: 'counter', cartDiscountType: 'none', cartDiscountValue: '0', delivery: out.cart.delivery_charge, note: '', quotationId: out.cart.quotation_id };
      useCart.getState().load(cart);
      if (out.shortages.length > 0) toast.warning(t('quotations.shortages', { skus: out.shortages.map((s) => `${s.sku} (${fmtQty(s.available)}/${fmtQty(s.requested)})`).join(', ') }));
      navigate('/pos');
    },
    onError,
  });
  if (query.isLoading) return <PageSkeleton />;
  if (query.isError || !query.data) return <ErrorState onRetry={() => void query.refetch()} />;
  const q = query.data;
  const open = ['draft', 'sent', 'accepted'].includes(q.status);
  return (
    <>
      <PageHeader title={q.quote_no} description={t('quotations.validity', { from: fmtDateTime(q.quote_date, i18n.language, false), to: fmtDateTime(q.valid_until, i18n.language, false) })} crumbs={[{ label: t('nav.sales'), to: '/sales' }, { label: t('sales.tabs.quotations'), to: '/quotations' }, { label: q.quote_no }]}
        actions={<div className="flex flex-wrap gap-2">
          <a href={quotationApi.pdfUrl(q.id)} target="_blank" rel="noopener noreferrer" className="inline-flex h-10 items-center gap-2 rounded-button border border-navy-200 bg-surface px-4 text-sm font-semibold text-navy-900 hover:bg-navy-50 focus-visible:focus-ring-light"><FileText className="h-4 w-4" aria-hidden="true" />{t('quotations.pdf')}</a>
          {q.status === 'draft' && <Button variant="secondary" loading={status.isPending} onClick={() => status.mutate('sent')}>{t('quotations.markSent')}</Button>}
          {(q.status === 'draft' || q.status === 'sent') && <Button variant="secondary" loading={status.isPending} onClick={() => status.mutate('accepted')}>{t('quotations.markAccepted')}</Button>}
          {open && <Button variant="secondary" loading={status.isPending} onClick={() => status.mutate('cancelled')}>{t('common.cancel')}</Button>}
          {open && <Button leftIcon={<ShoppingCart className="h-4 w-4" aria-hidden="true" />} loading={convert.isPending} onClick={() => convert.mutate()} data-testid="quotation-convert">{t('quotations.convert')}</Button>}
        </div>} />
      <SalesTabs />
      <Card><CardBody>
        <div className="mb-3 flex flex-wrap items-center gap-3"><Badge tone={SALE_TONE[q.status] ?? 'neutral'}>{t(`quotations.status.${q.status}`)}</Badge><span className="text-sm">{q.customer_snapshot.name}</span>{q.converted_sale_id && <Link className="text-sm font-semibold text-navy-800 hover:underline" to={`/sales/${q.converted_sale_id}`}>{t('quotations.viewSale')}</Link>}</div>
        <table className="w-full text-sm"><caption className="sr-only">{t('sales.lines')}</caption>
          <thead><tr className="text-xs text-ink-muted"><th scope="col" className="py-1 text-start font-medium">{t('sales.item')}</th><th scope="col" className="text-end font-medium">{t('sales.qty')}</th><th scope="col" className="text-end font-medium">{t('sales.unitPrice')}</th><th scope="col" className="text-end font-medium">{t('sales.amount')}</th></tr></thead>
          <tbody>{(q.items ?? []).map((i) => <tr key={i.id} className="border-t border-navy-100"><td className="py-2">{i.description_snapshot}</td><td className="tabular text-end">{fmtQty(i.qty)}</td><td className="tabular text-end">{formatMoney(i.unit_price, { symbol: false })}</td><td className="tabular text-end font-semibold">{formatMoney(i.line_total, { symbol: false })}</td></tr>)}</tbody></table>
        <dl className="ms-auto mt-4 w-full max-w-xs space-y-1 text-sm">
          <div className="flex justify-between"><dt className="text-ink-muted">{t('pos.subtotal')}</dt><dd className="tabular">{formatMoney(q.subtotal)}</dd></div>
          <div className="flex justify-between"><dt className="font-semibold">{t('pos.grandTotal')}</dt><dd className="tabular text-base font-extrabold text-navy-900">{formatMoney(q.grand_total)}</dd></div>
        </dl>
      </CardBody></Card>
    </>
  );
}
