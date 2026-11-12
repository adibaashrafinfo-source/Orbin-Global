import { http, type Envelope } from '@/lib/api';

export interface CompanyProfile {
  legal_name: string;
  trade_name: string | null;
  tagline: string | null;
  logo_url: string | null;
  signature_url: string | null;
  address_line1: string;
  address_line2: string | null;
  city: string | null;
  district: string | null;
  postcode: string | null;
  country: string | null;
  phones: string[];
  whatsapp: string | null;
  email: string | null;
  website: string | null;
  bin_no: string | null;
  tin_no: string | null;
  trade_license_no: string | null;
  bank_name: string | null;
  bank_branch: string | null;
  bank_account_name: string | null;
  bank_account_no: string | null;
  invoice_terms: string | null;
  return_policy_text: string | null;
  warranty_policy_text: string | null;
  footer_note: string | null;
  invoice_ready: boolean;
  missing_invoice_fields: string[];
  updated_at: string | null;
}

export type CompanyProfileInput = Omit<CompanyProfile, 'logo_url' | 'signature_url' | 'invoice_ready' | 'missing_invoice_fields' | 'updated_at'>;

export const COMPANY_KEY = ['settings', 'company'] as const;

export const companyApi = {
  show: () => http.get<Envelope<CompanyProfile>>('/settings/company').then((r) => r.data.data),
  update: (body: CompanyProfileInput) => http.put<Envelope<CompanyProfile>>('/settings/company', body).then((r) => r.data.data),
  uploadLogo: (file: File) => upload('/settings/company/logo', file),
  uploadSignature: (file: File) => upload('/settings/company/signature', file),
};

function upload(url: string, file: File) {
  const form = new FormData();
  form.append('file', file);
  return http.post<Envelope<CompanyProfile>>(url, form, { headers: { 'Content-Type': 'multipart/form-data' } }).then((r) => r.data.data);
}
