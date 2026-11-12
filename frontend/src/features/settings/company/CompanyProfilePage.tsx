import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useRef } from 'react';
import { useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { Badge, Button, Card, CardBody, CardHeader, ErrorState, Input, PageSkeleton, PageHeader, Textarea, toast } from '@/design/components';
import { ApiError } from '@/lib/api';
import { companyApi, COMPANY_KEY, type CompanyProfile } from './api';
import { companyProfileSchema, type CompanyProfileForm } from './schema';
import { InvoicePreview } from './InvoicePreview';
import { useUnsavedChangesGuard } from '@/app/useUnsavedChangesGuard';

function toForm(p: CompanyProfile): CompanyProfileForm {
  const { logo_url: _l, signature_url: _s, invoice_ready: _r, missing_invoice_fields: _m, updated_at: _u, ...rest } = p;
  return rest;
}

/** FR-104 Company profile with live invoice preview, logo and signature upload. */
export function CompanyProfilePage() {
  const { t } = useTranslation();
  const client = useQueryClient();
  const query = useQuery({ queryKey: COMPANY_KEY, queryFn: companyApi.show });
  const form = useForm<CompanyProfileForm>({ resolver: zodResolver(companyProfileSchema), defaultValues: { legal_name: '', address_line1: '', phones: [] } });
  const logoInput = useRef<HTMLInputElement>(null);
  const sigInput = useRef<HTMLInputElement>(null);

  useEffect(() => {
    if (query.data) form.reset(toForm(query.data));
  }, [query.data, form]);
  useUnsavedChangesGuard(form.formState.isDirty);

  const save = useMutation({
    mutationFn: companyApi.update,
    onSuccess: (data) => {
      client.setQueryData(COMPANY_KEY, data);
      form.reset(toForm(data));
      toast.success(t('company.saved'));
    },
    onError: (e) => toast.error(t(`errors.${e instanceof ApiError ? e.code : 'unknown'}`, { defaultValue: t('errors.unknown') })),
  });
  const uploadLogo = useMutation({
    mutationFn: companyApi.uploadLogo,
    onSuccess: (data) => {
      client.setQueryData(COMPANY_KEY, data);
      toast.success(t('company.logoSaved'));
    },
    onError: (e) => toast.error(t(`errors.${e instanceof ApiError ? e.code : 'unknown'}`, { defaultValue: t('errors.UPLOAD_INVALID') })),
  });
  const uploadSig = useMutation({
    mutationFn: companyApi.uploadSignature,
    onSuccess: (data) => {
      client.setQueryData(COMPANY_KEY, data);
      toast.success(t('company.signatureSaved'));
    },
    onError: (e) => toast.error(t(`errors.${e instanceof ApiError ? e.code : 'unknown'}`, { defaultValue: t('errors.UPLOAD_INVALID') })),
  });

  if (query.isLoading) return <PageSkeleton rows={10} />;
  if (query.error || !query.data) return <ErrorState onRetry={() => void query.refetch()} />;
  const profile = query.data;
  const values = form.watch();
  const errs = form.formState.errors;
  const serverErr = save.error instanceof ApiError ? save.error.fields : {};
  const fieldError = (name: keyof CompanyProfileForm) => (errs[name] || serverErr[name] ? t('errors.validation') : undefined);
  const phonesText = (values.phones ?? []).join('\n');

  return (
    <>
      <PageHeader title={t('company.title')} description={t('company.subtitle')} crumbs={[{ label: t('settings.title') }, { label: t('company.title') }]} actions={<Badge tone={profile.invoice_ready ? 'success' : 'orange'}>{profile.invoice_ready ? t('company.previewReady') : t('company.previewBlocked')}</Badge>} />
      <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_380px]">
        <form onSubmit={form.handleSubmit((v) => save.mutate(companyProfileSchema.parse(v)))} className="flex flex-col gap-6" noValidate>
          <Card>
            <CardHeader title={t('company.sections.identity')} />
            <CardBody className="grid gap-4 2xl:grid-cols-2">
              <Input label={t('company.legalName')} required error={fieldError('legal_name')} {...form.register('legal_name')} />
              <Input label={t('company.tradeName')} error={fieldError('trade_name')} {...form.register('trade_name')} />
              <Input label={t('company.tagline')} error={fieldError('tagline')} {...form.register('tagline')} className="2xl:col-span-2" />
              <div className="flex flex-col gap-2">
                <span className="text-13 font-medium text-ink-muted">{t('company.logo')}</span>
                <div className="flex items-center gap-4">
                  <div className="flex h-20 w-36 shrink-0 items-center justify-center rounded-input border border-navy-200 bg-surface p-2">
                    <img src={profile.logo_url ?? '/assets/brand/orbin-logo.jpg'} alt={t('company.logo')} className="max-h-full max-w-full object-contain" />
                  </div>
                  <input ref={logoInput} type="file" accept="image/jpeg,image/png,image/webp" className="sr-only" aria-label={t('company.uploadLogo')} onChange={(e) => e.target.files?.[0] && uploadLogo.mutate(e.target.files[0])} />
                  <Button type="button" variant="secondary" size="sm" loading={uploadLogo.isPending} onClick={() => logoInput.current?.click()}>
                    {t('company.uploadLogo')}
                  </Button>
                </div>
                <span className="text-xs text-ink-subtle">{t('company.logoHelp')}</span>
              </div>
              <div className="flex flex-col gap-2">
                <span className="text-13 font-medium text-ink-muted">{t('company.signature')}</span>
                <div className="flex items-center gap-4">
                  <div className="flex h-20 w-36 shrink-0 items-center justify-center rounded-input border border-dashed border-navy-200 bg-surface p-2 text-xs text-ink-subtle">{profile.signature_url ? <img src={profile.signature_url} alt={t('company.signature')} className="max-h-full max-w-full object-contain" /> : '—'}</div>
                  <input ref={sigInput} type="file" accept="image/jpeg,image/png,image/webp" className="sr-only" aria-label={t('company.uploadSignature')} onChange={(e) => e.target.files?.[0] && uploadSig.mutate(e.target.files[0])} />
                  <Button type="button" variant="secondary" size="sm" loading={uploadSig.isPending} onClick={() => sigInput.current?.click()}>
                    {t('company.uploadSignature')}
                  </Button>
                </div>
              </div>
            </CardBody>
          </Card>

          <Card>
            <CardHeader title={t('company.sections.address')} />
            <CardBody className="grid gap-4 2xl:grid-cols-2">
              <Input label={t('company.addressLine1')} required error={fieldError('address_line1')} {...form.register('address_line1')} className="2xl:col-span-2" />
              <Input label={t('company.addressLine2')} {...form.register('address_line2')} className="2xl:col-span-2" />
              <Input label={t('company.city')} {...form.register('city')} />
              <Input label={t('company.district')} {...form.register('district')} />
              <Input label={t('company.postcode')} {...form.register('postcode')} />
              <Input label={t('company.country')} {...form.register('country')} />
              <Textarea label={t('company.phones')} hint={t('company.phonesHelp')} required rows={3} value={phonesText} error={errs.phones || serverErr.phones ? t('errors.validation') : undefined} onChange={(e) => form.setValue('phones', e.target.value.split('\n').map((s) => s.trim()).filter(Boolean), { shouldDirty: true })} />
              <div className="grid gap-4">
                <Input label={t('company.whatsapp')} {...form.register('whatsapp')} />
                <Input label={t('company.email')} type="email" error={fieldError('email')} {...form.register('email')} />
                <Input label={t('company.website')} {...form.register('website')} />
              </div>
            </CardBody>
          </Card>

          <Card>
            <CardHeader title={t('company.sections.registration')} />
            <CardBody className="grid gap-4 2xl:grid-cols-3">
              <Input label={t('company.binNo')} {...form.register('bin_no')} />
              <Input label={t('company.tinNo')} {...form.register('tin_no')} />
              <Input label={t('company.tradeLicenseNo')} {...form.register('trade_license_no')} />
            </CardBody>
          </Card>

          <Card>
            <CardHeader title={t('company.sections.bank')} description={t('company.bank')} />
            <CardBody className="grid gap-4 2xl:grid-cols-2">
              <Input label={t('company.bankName')} {...form.register('bank_name')} />
              <Input label={t('company.bankBranch')} {...form.register('bank_branch')} />
              <Input label={t('company.bankAccountName')} {...form.register('bank_account_name')} />
              <Input label={t('company.bankAccountNo')} {...form.register('bank_account_no')} />
            </CardBody>
          </Card>

          <Card>
            <CardHeader title={t('company.sections.texts')} />
            <CardBody className="grid gap-4">
              <Textarea label={t('company.invoiceTerms')} rows={3} {...form.register('invoice_terms')} />
              <Textarea label={t('company.returnPolicyText')} rows={2} {...form.register('return_policy_text')} />
              <Textarea label={t('company.warrantyPolicyText')} rows={2} {...form.register('warranty_policy_text')} />
              <Input label={t('company.footerNote')} {...form.register('footer_note')} />
            </CardBody>
          </Card>

          <div className="sticky bottom-4 flex justify-end">
            <Button type="submit" variant="primary" size="lg" loading={save.isPending} disabled={!form.formState.isDirty}>
              {t('common.save')}
            </Button>
          </div>
        </form>

        <aside className="xl:sticky xl:top-20 xl:self-start">
          <Card>
            <CardHeader title={t('company.preview')} description={t('company.previewHelp')} />
            <CardBody>
              <InvoicePreview
                legal_name={values.legal_name ?? ''}
                address_line1={values.address_line1 ?? ''}
                address_line2={values.address_line2}
                city={values.city}
                district={values.district}
                postcode={values.postcode}
                phones={values.phones ?? []}
                whatsapp={values.whatsapp}
                email={values.email}
                website={values.website}
                bin_no={values.bin_no}
                tin_no={values.tin_no}
                trade_license_no={values.trade_license_no}
                bank_name={values.bank_name}
                bank_account_no={values.bank_account_no}
                invoice_terms={values.invoice_terms}
                footer_note={values.footer_note}
                tagline={values.tagline}
                logoUrl={profile.logo_url}
                signatureUrl={profile.signature_url}
              />
            </CardBody>
          </Card>
        </aside>
      </div>
    </>
  );
}
