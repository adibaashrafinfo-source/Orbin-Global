import { useTranslation } from 'react-i18next';
import { LOGO_SRC } from '@/design/components';
import { formatMoney } from '@/lib/money';

interface PreviewProps {
  legal_name: string;
  address_line1: string;
  address_line2?: string | null;
  city?: string | null;
  district?: string | null;
  postcode?: string | null;
  phones: string[];
  whatsapp?: string | null;
  email?: string | null;
  website?: string | null;
  bin_no?: string | null;
  tin_no?: string | null;
  trade_license_no?: string | null;
  bank_name?: string | null;
  bank_account_no?: string | null;
  invoice_terms?: string | null;
  footer_note?: string | null;
  tagline?: string | null;
  logoUrl: string | null;
  signatureUrl: string | null;
}

/** Live preview of the invoice header/footer (PRD §7.1 layout) built from the form values. The PDF arrives in P3. */
export function InvoicePreview(p: PreviewProps) {
  const { t } = useTranslation();
  const addr = [p.address_line1, p.address_line2, [p.city, p.district, p.postcode].filter(Boolean).join(' ')].filter((s) => s && s.trim() !== '');
  const regs = [p.bin_no && `BIN: ${p.bin_no}`, p.tin_no && `TIN: ${p.tin_no}`, p.trade_license_no && `TL: ${p.trade_license_no}`].filter(Boolean);
  const contacts = [p.phones.filter(Boolean).join(', '), p.whatsapp && `WhatsApp ${p.whatsapp}`, p.email, p.website].filter(Boolean);
  return (
    <div className="mx-auto w-full max-w-[420px] select-none overflow-hidden rounded-input border border-navy-200 bg-surface shadow-sm" aria-label={t('company.preview')} style={{ aspectRatio: '210 / 297' }}>
      <div className="flex h-full flex-col p-5 text-[9px] leading-[1.35] text-ink">
        <div className="flex items-start justify-between gap-3">
          <img src={p.logoUrl ?? LOGO_SRC} alt="" className="w-[96px] object-contain" />
          <div className="text-right">
            <p className="font-heading text-[11px] font-bold text-navy-900">{p.legal_name.trim() || t('company.placeholderName')}</p>
            {(addr.length ? addr : [t('company.placeholderAddress')]).map((line, i) => (
              <p key={i} className={addr.length ? '' : 'text-orange-700'}>{line}</p>
            ))}
            {(contacts.length ? contacts : [t('company.placeholderPhone')]).map((line, i) => (
              <p key={i} className={contacts.length ? '' : 'text-orange-700'}>{line}</p>
            ))}
            {regs.map((r, i) => (
              <p key={i} className="text-ink-muted">{r}</p>
            ))}
          </div>
        </div>
        <div className="mt-3 flex h-[3px]">
          <span className="w-12 bg-navy-900" />
          <span className="flex-1 bg-orange-500" />
        </div>
        <div className="mt-3 flex items-center justify-between">
          <span className="font-heading text-[18px] font-extrabold tracking-wide text-navy-900">{t('company.invoiceWord')}</span>
          <span className="rounded-pill bg-success/10 px-2 py-0.5 text-[8px] font-bold text-success">PAID</span>
        </div>
        <div className="mt-3 grid grid-cols-2 gap-2">
          <div className="rounded bg-navy-50 p-2">
            <p className="text-[8px] font-semibold uppercase text-ink-muted">{t('company.billTo')}</p>
            <p className="font-semibold">{t('company.customerPlaceholder')}</p>
            <p className="text-ink-muted">01XXXXXXXXX</p>
          </div>
          <div className="rounded border border-navy-100 p-2 text-ink-muted">
            <p>ORB-INV-YYMM-000001</p>
            <p>DD MMM YYYY</p>
          </div>
        </div>
        <table className="mt-3 w-full border-collapse">
          <thead>
            <tr className="bg-navy-900 text-left text-[8px] uppercase text-surface">
              <th className="px-1.5 py-1">#</th>
              <th className="px-1.5 py-1">Item</th>
              <th className="px-1.5 py-1 text-right">Qty</th>
              <th className="px-1.5 py-1 text-right">Amount</th>
            </tr>
          </thead>
          <tbody>
            <tr className="border-b border-navy-100">
              <td className="px-1.5 py-1">1</td>
              <td className="px-1.5 py-1">{t('company.sampleItem')}</td>
              <td className="px-1.5 py-1 text-right tabular">1</td>
              <td className="px-1.5 py-1 text-right tabular">{formatMoney('12500')}</td>
            </tr>
          </tbody>
        </table>
        <div className="mt-2 ml-auto w-1/2 rounded bg-navy-900 px-2 py-1 text-right font-heading text-[10px] font-bold text-surface">
          {t('company.grandTotal')} {formatMoney('12500')}
        </div>
        {p.invoice_terms && <p className="mt-3 whitespace-pre-line text-[8px] text-ink-muted">{p.invoice_terms}</p>}
        {p.bank_name && (
          <p className="mt-1 text-[8px] text-ink-muted">
            {p.bank_name} {p.bank_account_no ? `· ${p.bank_account_no}` : ''}
          </p>
        )}
        <div className="mt-auto">
          <div className="flex justify-between gap-6 pt-6">
            <div className="flex-1 border-t border-navy-300 pt-1 text-center text-[8px] text-ink-muted">{t('company.customerSignature')}</div>
            <div className="flex flex-1 flex-col items-center">
              {p.signatureUrl && <img src={p.signatureUrl} alt="" className="-mb-2 h-8 object-contain" />}
              <div className="w-full border-t border-navy-300 pt-1 text-center text-[8px] text-ink-muted">{t('company.authorizedSignature')}</div>
            </div>
          </div>
          <div className="mt-3 flex items-center justify-between border-t border-navy-100 pt-2 text-[8px] text-ink-subtle">
            <span className="italic text-navy-700">{p.tagline || t('app.tagline')}</span>
            {p.footer_note && <span className="truncate pl-2">{p.footer_note}</span>}
            <span>1 / 1</span>
          </div>
        </div>
      </div>
    </div>
  );
}
