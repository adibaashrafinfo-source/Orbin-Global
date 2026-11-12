import { z } from 'zod';

const optional = z.string().trim().max(255).nullable().optional().transform((v) => (v === '' ? null : (v ?? null)));
const longText = z.string().trim().max(5000).nullable().optional().transform((v) => (v === '' ? null : (v ?? null)));

/** Mirrors UpdateCompanyProfileRequest. */
export const companyProfileSchema = z.object({
  legal_name: z.string().trim().min(1).max(255),
  trade_name: optional,
  tagline: optional,
  address_line1: z.string().trim().max(1000),
  address_line2: longText,
  city: optional,
  district: optional,
  postcode: z.string().trim().max(20).nullable().optional().transform((v) => (v === '' ? null : (v ?? null))),
  country: optional,
  phones: z.array(z.string().trim().regex(/^\+?[0-9 -]{6,20}$/)).max(5),
  whatsapp: optional,
  email: z.string().trim().email().nullable().optional().or(z.literal('')).transform((v) => (v === '' ? null : (v ?? null))),
  website: optional,
  bin_no: optional,
  tin_no: optional,
  trade_license_no: optional,
  bank_name: optional,
  bank_branch: optional,
  bank_account_name: optional,
  bank_account_no: optional,
  invoice_terms: longText,
  return_policy_text: longText,
  warranty_policy_text: longText,
  footer_note: longText,
});

export type CompanyProfileForm = z.input<typeof companyProfileSchema>;
