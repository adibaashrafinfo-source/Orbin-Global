import type { ColumnDef } from '@tanstack/react-table';
import { Plus, Search } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge, Button, Card, CardBody, CardHeader, Checkbox, DataTable, Drawer, EmptyState, ErrorState, IconButton, Input, KpiCard, Modal, PageHeader, Select, Skeleton, StockBadge, Switch, Textarea, toast } from '@/design/components';
import { formatMoney } from '@/lib/money';

type Row = { id: number; name: string; size: string; stock: number; price: string };
const VARIANTS = ['primary', 'accent', 'secondary', 'ghost', 'danger'] as const;

/** P0 gate: every base component in every state, built only from design tokens. */
export function GalleryPage() {
  const { t } = useTranslation();
  const [drawer, setDrawer] = useState(false);
  const [modal, setModal] = useState(false);
  const [checked, setChecked] = useState(true);
  const [on, setOn] = useState(true);
  const [select, setSelect] = useState('b');
  const rows: Row[] = useMemo(() => [
    { id: 1, name: 'Side Mirror – Toyota Axio NZE141 (RH)', size: '7 × 4 in', stock: 12, price: '12500.00' },
    { id: 2, name: 'Side Mirror – Toyota Axio NZE141 (LH)', size: '7 × 4 in', stock: 2, price: '12500.00' },
    { id: 3, name: 'Mirror Glass – Honda Vezel (Convex)', size: '180 × 110 mm', stock: 0, price: '3200.00' },
  ], []);
  const columns = useMemo<ColumnDef<Row, unknown>[]>(() => [
    { accessorKey: 'name', header: t('common.name') },
    { accessorKey: 'size', header: 'Size' },
    { accessorKey: 'stock', header: 'Stock', meta: { align: 'right' }, cell: ({ row }) => <StockBadge state={row.original.stock === 0 ? 'out' : row.original.stock < 5 ? 'low' : 'in'} label={row.original.stock === 0 ? t('gallery.outOfStock') : row.original.stock < 5 ? `${t('gallery.lowStock')} ${row.original.stock}` : `${t('gallery.inStock')} ${row.original.stock}`} /> },
    { accessorKey: 'price', header: 'Price', meta: { align: 'right' }, cell: ({ getValue }) => formatMoney(getValue<string>()) },
  ], [t]);

  return (
    <>
      <PageHeader title={t('gallery.title')} description={t('gallery.subtitle')} />
      <div className="flex flex-col gap-6">
        <Card>
          <CardHeader title={t('gallery.buttons')} />
          <CardBody className="overflow-x-auto">
            <table className="text-sm">
              <thead><tr>{['', t('gallery.default'), t('gallery.hover'), t('gallery.focus'), t('gallery.loading'), t('gallery.disabled')].map((h, i) => <th key={i} className="px-3 pb-2 text-left text-xs font-semibold uppercase text-ink-muted">{h}</th>)}</tr></thead>
              <tbody>
                {VARIANTS.map((v) => (
                  <tr key={v}>
                    <th scope="row" className="px-3 py-2 text-left font-medium capitalize">{v}</th>
                    <td className="px-3 py-2"><Button variant={v}>{v}</Button></td>
                    <td className="px-3 py-2"><Button variant={v} className="brightness-95">{v}</Button></td>
                    <td className="px-3 py-2"><Button variant={v} className="focus-ring-light">{v}</Button></td>
                    <td className="px-3 py-2"><Button variant={v} loading>{v}</Button></td>
                    <td className="px-3 py-2"><Button variant={v} disabled>{v}</Button></td>
                  </tr>
                ))}
              </tbody>
            </table>
            <div className="mt-4 flex flex-wrap items-center gap-3">
              <Button size="sm">sm</Button><Button size="md">md</Button><Button size="lg">lg</Button><Button size="xl" variant="accent">xl · Pay {formatMoney('12500')}</Button>
              <IconButton label={t('common.add')}><Plus className="h-5 w-5" aria-hidden="true" /></IconButton>
              <IconButton label={t('common.search')} variant="secondary"><Search className="h-5 w-5" aria-hidden="true" /></IconButton>
            </div>
          </CardBody>
        </Card>

        <Card>
          <CardHeader title={t('gallery.inputs')} />
          <CardBody className="grid gap-4 md:grid-cols-3">
            <Input label={t('gallery.default')} placeholder="Placeholder" />
            <Input label={t('gallery.focus')} defaultValue="Focused" className="focus-ring-light border-navy-600" />
            <Input label={t('gallery.disabled')} disabled defaultValue="Disabled" />
            <Input label="Error" defaultValue="wrong" error={t('errors.validation')} />
            <Input label="Currency" type="number" prefix="৳" defaultValue="12500" />
            <Input label="POS (44px)" pos placeholder="Scan or type…" />
            <Select label="Select" value={select} onChange={setSelect} options={[{ value: 'a', label: 'Option A' }, { value: 'b', label: 'Option B' }, { value: 'c', label: 'Disabled', disabled: true }]} />
            <Textarea label="Textarea" placeholder="Notes…" />
            <div className="flex flex-col gap-3">
              <Checkbox checked={checked} onChange={setChecked} label="Checkbox" />
              <Checkbox checked={false} onChange={() => undefined} label={t('gallery.disabled')} disabled />
              <Switch checked={on} onChange={setOn} label="Switch" description="With description" />
            </div>
          </CardBody>
        </Card>

        <Card>
          <CardHeader title={t('gallery.badges')} />
          <CardBody className="flex flex-wrap items-center gap-3">
            <Badge tone="navy">navy</Badge><Badge tone="orange">orange</Badge><Badge tone="success">success</Badge><Badge tone="warning">warning</Badge><Badge tone="danger">danger</Badge><Badge tone="neutral">neutral</Badge>
            <StockBadge state="in" label={t('gallery.inStock')} /><StockBadge state="low" label={t('gallery.lowStock')} /><StockBadge state="out" label={t('gallery.outOfStock')} />
          </CardBody>
        </Card>

        <section aria-labelledby="gal-cards" className="flex flex-col gap-3">
          <h2 id="gal-cards" className="font-heading text-base font-bold text-navy-900">{t('gallery.cards')}</h2>
          <div className="grid gap-4 md:grid-cols-3">
          <KpiCard label={t('dashboard.kpi.todaySales')} value={formatMoney('125000')} numeric={125000} formatter={(n) => formatMoney(Math.round(n))} delta={{ value: '12.5%', positive: true }} />
          <KpiCard label={t('dashboard.kpi.dues')} value={formatMoney('43250.50')} numeric={43250.5} formatter={(n) => formatMoney(n.toFixed(2))} delta={{ value: '3.1%', positive: false }} />
          <Card interactive className="mirror-shine"><CardBody><p className="font-heading font-bold text-navy-900">{t('gallery.kpi')}</p><p className="text-13 text-ink-muted">Interactive card: lift + shadow + Mirror Shine on hover.</p></CardBody></Card>
          </div>
        </section>

        <section aria-labelledby="gal-tables" className="flex flex-col gap-3">
          <h2 id="gal-tables" className="font-heading text-base font-bold text-navy-900">{t('gallery.tables')}</h2>
          <DataTable columns={columns} data={rows} caption={t('gallery.tables')} />
        </section>

        <Card>
          <CardHeader title={t('gallery.overlays')} />
          <CardBody className="flex flex-wrap gap-3">
            <Button variant="secondary" onClick={() => setDrawer(true)}>{t('gallery.openDrawer')}</Button>
            <Button variant="secondary" onClick={() => setModal(true)}>{t('gallery.openModal')}</Button>
            <Button variant="secondary" onClick={() => toast.success(t('gallery.toastTitle'), t('gallery.toastBody'))}>{t('gallery.showToast')}</Button>
            <Button variant="secondary" onClick={() => toast.error(t('errors.unknown'))}>{t('gallery.showToast')} · danger</Button>
            <Button variant="secondary" onClick={() => { const id = toast.progress('Download started', 0); let p = 0; const iv = window.setInterval(() => { p += 20; toast.update(id, { progress: p }); if (p >= 100) window.clearInterval(iv); }, 400); }}>{t('gallery.showToast')} · progress</Button>
          </CardBody>
        </Card>

        <div className="grid gap-4 md:grid-cols-3">
          <Card><CardHeader title={t('gallery.skeletons')} /><CardBody className="flex flex-col gap-3"><Skeleton className="h-6 w-2/3" /><Skeleton /><Skeleton className="w-1/2" /></CardBody></Card>
          <Card><EmptyState title={t('gallery.emptyTitle')} body={t('gallery.emptyBody')} action={<Button size="sm" variant="secondary">{t('common.add')}</Button>} /></Card>
          <Card><ErrorState title={t('gallery.errorTitle')} body={t('gallery.errorBody')} requestId="01HXYZ" onRetry={() => undefined} /></Card>
        </div>
      </div>
      <Drawer open={drawer} onOpenChange={setDrawer} title={t('gallery.drawerTitle')} description="480 px · slides in 320 ms" footer={<Button onClick={() => setDrawer(false)}>{t('common.close')}</Button>}><Input label={t('common.name')} /></Drawer>
      <Modal open={modal} onOpenChange={setModal} title={t('gallery.modalTitle')} description="scale .98 → 1 · backdrop blur 6 px" footer={<><Button variant="secondary" onClick={() => setModal(false)}>{t('common.cancel')}</Button><Button onClick={() => setModal(false)}>{t('common.confirm')}</Button></>}><p className="text-sm">{t('gallery.subtitle')}</p></Modal>
    </>
  );
}
