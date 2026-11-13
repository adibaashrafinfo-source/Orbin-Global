import { readFile } from 'node:fs/promises';
import { expect, type Page } from '@playwright/test';

/** Open a select by its label and choose an option. */
export async function pick(page: Page, label: RegExp | string, option: RegExp | string): Promise<void> {
  await page.getByLabel(label).first().click();
  await page.getByRole('option', { name: option }).first().click();
}

/** Minimal RFC 4180 parser: quoted cells, doubled quotes, CRLF or LF. */
export function parseCsv(text: string): string[][] {
  const rows: string[][] = [];
  let row: string[] = [];
  let cell = '';
  let quoted = false;
  for (let i = 0; i < text.length; i++) {
    const c = text[i] as string;
    if (quoted) {
      if (c === '"' && text[i + 1] === '"') { cell += '"'; i++; } else if (c === '"') quoted = false; else cell += c;
    } else if (c === '"') quoted = true;
    else if (c === ',') { row.push(cell); cell = ''; }
    else if (c === '\n' || c === '\r') {
      if (c === '\r' && text[i + 1] === '\n') i++;
      row.push(cell); cell = '';
      if (row.some((x) => x !== '')) rows.push(row);
      row = [];
    } else cell += c;
  }
  if (cell !== '' || row.length) { row.push(cell); rows.push(row); }
  return rows;
}

/**
 * Creates one product with the given variants through the real import screen (template download → edit → upload → commit).
 * Each variant gets its own position so the variants are distinct. Returns the SKUs.
 */
export async function importProduct(page: Page, code: string, variants: { sku: string; position: string; width: string }[]): Promise<string[]> {
  await page.goto('/products/import');
  await pick(page, 'What to import', 'Products and variants');
  const [download] = await Promise.all([page.waitForEvent('download'), page.getByRole('link', { name: 'Download CSV template' }).click()]);
  const rows = parseCsv((await readFile((await download.path()) as string, 'utf8')).replace(/^﻿/, ''));
  const header = rows[0] as string[];
  const sample = rows[1] as string[];
  const col = (n: string) => header.indexOf(n);
  const out = variants.map((v) => {
    const r = [...sample];
    r[col('product_code')] = code;
    r[col('name_en')] = `Stock Mirror ${code}`;
    r[col('sku')] = v.sku;
    r[col('barcode')] = '';
    r[col('position')] = v.position;
    r[col('width_mm')] = v.width;
    r[col('size_label')] = '';
    return r;
  });
  const csv = [header, ...out].map((r) => r.map((c) => `"${c.replace(/"/g, '""')}"`).join(',')).join('\n');
  await page.getByTestId('import-file').setInputFiles({ name: 'p.csv', mimeType: 'text/csv', buffer: Buffer.from(csv) });
  await page.getByRole('button', { name: 'Check file' }).click();
  await expect(page.getByText(`${variants.length} valid`).first()).toBeVisible();
  await page.getByRole('button', { name: new RegExp(`^Import ${variants.length} rows?$`) }).click();
  await expect(page.getByText(/Import finished: \d+ added/)).toBeVisible();
  return variants.map((v) => v.sku);
}
