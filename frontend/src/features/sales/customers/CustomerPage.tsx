import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AnimatePresence, motion, useReducedMotion } from 'framer-motion';
import { HandCoins, Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { Badge, Button, Card, CardBody, ConfirmDialog, EmptyState, ErrorState, IconButton, Input, KpiCard, PageHeader, PageSkeleton, toast } from '@/design/components';
import { useSession } from '@/features/auth/useSession';
import { useMaster } from '@/features/catalog/hooks';
import { serverFieldError, useApiError } from '@/features/catalog/useApiError';
import { str } from '@/features/catalog/util';
import { cn } from '@/lib/cn';
import { formatMoney } from '@/lib/money';
import { RecentPayments } from '@/features/ledger/RecentPayments';
import { customerApi, SALES_KEYS } from '../api';
import { fmtDateTime, SALE_TONE } from '../format';
import { CustomerDialog } from './CustomerDialog';

const TABS = ['overview', 'invoices', 'payments', 'ledger', 'vehicles', 'notes'] as const;
type Tab = (typeof TABS)[number];

/** FR-604, PRD §8.8: one customer at a glance, with the invoices, the ledger and the vehicles behind it. */
export function CustomerPage() {
  const { t, i18n } = useTranslation();
  const { id = '' } = useParams();
  const { can } = useSession();
  const reduced = useReducedMotion();
  const navigate = useNavigate();
  const [tab, setTab] = useState<Tab>('overview');
  const [edit, setEdit] = useState(false);
  const query = useQuery({ queryKey: [...SALES_KEYS.customers, 'overview', id], queryFn: () => customerApi.overview(id) });
  if (query.isLoading) return <PageSkeleton />;
  if (query.isError || !query.data) return <ErrorState onRetry={() => void query.refetch()} />;
  const o = query.data;
  const c = o.customer;
  const canManage = can(['customers.manage']);
  return (
    <>
      <PageHeader title={c.name} description={`${c.code}${c.company_name ? ` · ${c.company_name}` : ''}`} crumbs={[{ label: t('nav.customers'), to: '/customers' }, { label: c.code }]}
        actions={!c.is_walk_in ? <>
          {can(['ledger.receive_payment']) && <Button variant="primary" leftIcon={<HandCoins className="h-4 w-4" aria-hidden="true" />} onClick={() => navigate(`/ledger/receive?type=customer&id=${c.id}&code=${encodeURIComponent(c.code)}&name=${encodeURIComponent(c.name)}`)} data-testid="receive-payment">{t('ledger.receivePayment')}</Button>}
          {canManage && <Button variant="secondary" leftIcon={<Pencil className="h-4 w-4" aria-hidden="true" />} onClick={() => setEdit(true)}>{t('common.edit')}</Button>}
        </> : undefined} />
      <div className="mb-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <KpiCard label={t('customers.due')} value={formatMoney(o.due)} />
        <KpiCard label={t('customers.advance')} value={formatMoney(o.advance)} />
        <KpiCard label={t('customers.lifetime')} value={formatMoney(o.lifetime_value)} />
        <KpiCard label={t('customers.invoices')} value={String(o.invoice_count)} />
      </div>
      <div role="tablist" aria-label={t('nav.customers')} className="mb-4 flex flex-wrap gap-1 border-b border-navy-100 pb-2">
        {TABS.map((x) => <button key={x} role="tab" type="button" aria-selected={tab === x} onClick={() => setTab(x)} className={cn('rounded-input px-3 py-1.5 text-sm font-medium focus-visible:focus-ring-light', tab === x ? 'bg-navy-900 text-surface' : 'text-ink-muted hover:bg-navy-100')}>{t(`customers.tabs.${x}`)}</button>)}
      </div>
      <AnimatePresence mode="wait" initial={false}>
        <motion.div key={tab} role="tabpanel" initial={reduced ? false : { opacity: 0 }} animate={{ opacity: 1 }} exit={reduced ? undefined : { opacity: 0 }} transition={{ duration: 0.12 }}>
          {tab === 'overview' && (
            <div className="grid gap-4 lg:grid-cols-2">
              <Card><CardBody>
                <dl className="grid grid-cols-[8rem_1fr] gap-y-2 text-sm">
                  <dt className="text-ink-muted">{t('customers.fields.phones')}</dt><dd>{c.phones.join(', ') || '—'}</dd>
                  <dt className="text-ink-muted">{t('common.email')}</dt><dd>{c.email ?? '—'}</dd>
                  <dt className="text-ink-muted">{t('customers.fields.address1')}</dt><dd>{[c.address_line1, c.address_line2, c.area, c.district, c.postcode].filter(Boolean).join(', ') || '—'}</dd>
                  <dt className="text-ink-muted">{t('customers.fields.tier')}</dt><dd><Badge tone="navy">{t(`customers.tiers.${c.price_tier}`)}</Badge></dd>
                  <dt className="text-ink-muted">{t('customers.fields.creditLimit')}</dt><dd className="tabular">{Number(c.credit_limit) > 0 ? formatMoney(c.credit_limit) : '—'}</dd>
                  <dt className="text-ink-muted">{t('customers.lastPurchase')}</dt><dd>{fmtDateTime(o.last_purchase_at, i18n.language)}</dd>
                </dl>
              </CardBody></Card>
              <Card><CardBody>
                <h3 className="mb-2 text-sm font-semibold text-navy-900">{t('customers.topProducts')}</h3>
                {o.top_products.length === 0 ? <p className="text-sm text-ink-muted">{t('customers.noPurchases')}</p> : <ol className="space-y-1.5 text-sm">{o.top_products.map((p) => <li key={p.name} className="flex justify-between gap-3"><span className="truncate">{p.name}</span><span className="tabular text-ink-muted">{Number(p.qty)}</span></li>)}</ol>}
              </CardBody></Card>
            </div>
          )}
          {tab === 'invoices' && (o.invoices.length === 0 ? <EmptyState body={t('customers.noInvoices')} /> : (
            <Card><table className="w-full text-sm"><caption className="sr-only">{t('customers.tabs.invoices')}</caption><thead><tr className="text-start text-xs text-ink-muted"><th scope="col" className="px-4 py-2 text-start font-medium">{t('sales.invoice')}</th><th scope="col" className="text-start font-medium">{t('sales.date')}</th><th scope="col" className="text-start font-medium">{t('common.status')}</th><th scope="col" className="text-end font-medium">{t('sales.total')}</th><th scope="col" className="px-4 text-end font-medium">{t('sales.due')}</th></tr></thead>
              <tbody>{o.invoices.map((i) => <tr key={i.id} className="border-t border-navy-100"><td className="px-4 py-2"><Link className="font-semibold text-navy-800 hover:underline" to={`/sales/${i.id}`}>{i.invoice_no}</Link></td><td>{fmtDateTime(i.sale_date, i18n.language)}</td><td><Badge tone={SALE_TONE[i.status] ?? 'neutral'}>{t(`sales.status.${i.status}`)}</Badge></td><td className="tabular text-end">{formatMoney(i.grand_total)}</td><td className="tabular px-4 text-end">{Number(i.due_total) > 0 ? formatMoney(i.due_total) : '—'}</td></tr>)}</tbody></table></Card>
          ))}
          {tab === 'payments' && (can(['ledger.view']) ? <RecentPayments partyType="customer" partyId={c.id} /> : <EmptyState body={t('customers.paymentsHidden')} />)}
          {tab === 'ledger' && (o.ledger.length === 0 ? <EmptyState body={t('customers.noLedger')} /> : (
            <Card><table className="w-full text-sm"><caption className="sr-only">{t('customers.tabs.ledger')}</caption><thead><tr className="text-xs text-ink-muted"><th scope="col" className="px-4 py-2 text-start font-medium">{t('sales.date')}</th><th scope="col" className="text-start font-medium">{t('customers.entry')}</th><th scope="col" className="text-start font-medium">{t('customers.reference')}</th><th scope="col" className="text-end font-medium">{t('customers.debit')}</th><th scope="col" className="px-4 text-end font-medium">{t('customers.credit')}</th></tr></thead>
              <tbody>{o.ledger.map((l) => <tr key={l.id} className="border-t border-navy-100"><td className="px-4 py-2">{l.entry_date}</td><td>{t(`customers.entryTypes.${l.entry_type}`, { defaultValue: l.entry_type })}</td><td className="text-ink-muted">{l.ref_no ?? ''}</td><td className="tabular text-end">{Number(l.debit) > 0 ? formatMoney(l.debit) : ''}</td><td className="tabular px-4 text-end">{Number(l.credit) > 0 ? formatMoney(l.credit) : ''}</td></tr>)}</tbody></table></Card>
          ))}
          {tab === 'vehicles' && <Vehicles customerId={c.id} canManage={canManage && !c.is_walk_in} />}
          {tab === 'notes' && <Card><CardBody><p className="whitespace-pre-wrap text-sm">{c.notes || <span className="text-ink-muted">{t('customers.noNotes')}</span>}</p></CardBody></Card>}
        </motion.div>
      </AnimatePresence>
      <CustomerDialog customer={edit ? c : null} canManage={canManage} onClose={() => setEdit(false)} />
    </>
  );
}

function Vehicles({ customerId, canManage }: { customerId: number; canManage: boolean }) {
  const { t } = useTranslation();
  const client = useQueryClient();
  const onError = useApiError();
  const makes = useMaster('vehicle-makes');
  const customer = useQuery({ queryKey: [...SALES_KEYS.customers, customerId], queryFn: () => customerApi.show(customerId) });
  const [reg, setReg] = useState('');
  const [makeText, setMakeText] = useState('');
  const [year, setYear] = useState('');
  const [del, setDel] = useState<number | null>(null);
  const refresh = () => { void client.invalidateQueries({ queryKey: SALES_KEYS.customers }); };
  const add = useMutation({
    mutationFn: () => {
      const make = makes.data?.find((m) => str(m.name).toLowerCase() === makeText.trim().toLowerCase());
      return customerApi.addVehicle(customerId, { reg_no: reg.trim(), make_id: make?.id ?? null, year: year ? Number(year) : null, note: make || makeText.trim() === '' ? null : makeText.trim() });
    },
    onSuccess: () => { refresh(); setReg(''); setMakeText(''); setYear(''); toast.success(t('common.saved')); },
    onError,
  });
  const remove = useMutation({ mutationFn: (id: number) => customerApi.removeVehicle(id), onSuccess: () => { refresh(); setDel(null); }, onError });
  const cars = customer.data?.vehicles ?? [];
  return (
    <div className="flex flex-col gap-4">
      {canManage && (
        <form noValidate className="flex flex-wrap items-end gap-3" onSubmit={(e) => { e.preventDefault(); add.mutate(); }}>
          <div className="w-48"><Input label={t('customers.vehicle.reg')} required value={reg} onChange={(e) => setReg(e.target.value)} error={serverFieldError(t, add.error, 'reg_no')} /></div>
          <div className="w-40"><Input label={t('customers.vehicle.make')} list="vehicle-make-list" value={makeText} onChange={(e) => setMakeText(e.target.value)} /><datalist id="vehicle-make-list">{(makes.data ?? []).map((m) => <option key={m.id} value={str(m.name)} />)}</datalist></div>
          <div className="w-24"><Input label={t('customers.vehicle.year')} inputMode="numeric" maxLength={4} value={year} onChange={(e) => setYear(e.target.value.replace(/\D/g, ''))} /></div>
          <Button type="submit" loading={add.isPending} disabled={reg.trim() === ''}>{t('customers.vehicle.add')}</Button>
        </form>
      )}
      {cars.length === 0 ? <EmptyState body={t('customers.noVehicles')} /> : (
        <ul className="divide-y divide-navy-100 rounded-card border border-navy-100 bg-surface">
          {cars.map((v) => <li key={v.id} className="flex items-center justify-between px-4 py-2.5 text-sm"><span><span className="font-semibold">{v.reg_no}</span> <span className="text-ink-muted">{[v.make, v.model, v.year].filter(Boolean).join(' ')}</span></span>{canManage && <IconButton label={t('common.delete')} onClick={() => setDel(v.id)}><Trash2 className="h-4 w-4" aria-hidden="true" /></IconButton>}</li>)}
        </ul>
      )}
      <ConfirmDialog open={del !== null} onOpenChange={(o) => !o && setDel(null)} danger title={t('customers.vehicle.deleteTitle')} body={t('customers.vehicle.deleteBody')} confirmLabel={t('common.delete')} loading={remove.isPending} onConfirm={() => { if (del) remove.mutate(del); }} />
    </div>
  );
}
