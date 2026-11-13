import { readFile } from 'node:fs/promises';
import { expect, test, type Page } from '@playwright/test';
import { login, toastText } from './helpers';
import { makePng } from './png';

const stamp = Date.now().toString(36).toUpperCase();
const CODE = `E2E-${stamp}`;
const NAME = `E2E Mirror ${stamp}`;
const BARCODE = `9${String(Date.now()).slice(-9)}`;

async function pick(page: Page, label: RegExp | string, option: RegExp | string): Promise<void> {
  await page.getByLabel(label).first().click();
  await page.getByRole('option', { name: option }).first().click();
}

test.describe.configure({ mode: 'serial' });

test.describe('P1 catalog gate', () => {
  test('mirror with 6 variants, 4 images and 3 fitments is created, priced and published', async ({ page }) => {
    await login(page, 'super_admin');
    await page.goto('/products/new');
    await page.getByLabel(/^Name \(English\)/).fill(NAME);
    await page.getByLabel(/^Product code/).fill(CODE);
    await pick(page, /^Mirror type/, 'Side mirror');
    await pick(page, /^Category/, /Side Mirrors/);
    await pick(page, /^Quality grade/, /./);
    await page.getByRole('button', { name: 'Create draft' }).click();
    await expect(page).toHaveURL(/\/products\/\d+\?step=variants/);

    // Variant matrix: 2 positions × 3 sizes = 6 variants.
    await page.getByRole('button', { name: 'Generate variants' }).first().click();
    for (let i = 0; i < 3; i++) await page.getByRole('button', { name: 'Add size' }).click();
    const widths = page.getByLabel('Width (mm)');
    const heights = page.getByLabel('Height (mm)');
    for (const [i, [w, h]] of [['200', '120'], ['210', '130'], ['220', '140']].entries()) {
      await widths.nth(i).fill(w as string);
      await heights.nth(i).fill(h as string);
    }
    await page.getByLabel(/^Retail price/).fill('12500');
    await page.getByRole('button', { name: 'Preview' }).click();
    await expect(page.getByText('6 variants will be created')).toBeVisible();
    await page.getByRole('button', { name: 'Create 6 variants' }).click();
    await expect(page.getByRole('row')).toHaveCount(7); // header + 6
    await expect(page.getByRole('heading', { name: /Variants/ })).toContainText('(6)');

    // Four images.
    await page.getByRole('button', { name: /Images/ }).click();
    const colours: [number, number, number][] = [[0, 39, 86], [255, 121, 0], [120, 130, 140], [200, 210, 220]];
    await page.getByTestId('image-input').setInputFiles(colours.map((c, i) => ({ name: `mirror-${i}.png`, mimeType: 'image/png', buffer: makePng(640, 480, c) })));
    await expect(page.getByRole('list', { name: 'Images' }).getByRole('listitem')).toHaveCount(4);

    // Three fitments on the first variant.
    await page.getByRole('button', { name: /Fitment/ }).click();
    for (let i = 0; i < 3; i++) {
      await pick(page, /^Make/, 'Toyota');
      await page.getByLabel(/^Fits model/).click();
      await page.getByRole('option').nth(i).click();
      await page.getByLabel('From year').fill('2018');
      await page.getByRole('button', { name: 'Add', exact: true }).click();
      await expect(page.getByRole('row')).toHaveCount(i + 2);
    }

    // Price one variant, then publish from the review step.
    await page.getByRole('button', { name: /Pricing/ }).click();
    const firstRetail = page.getByRole('textbox', { name: /Retail price/ }).first();
    await firstRetail.fill('13000');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(toastText(page, '1 variant saved')).toBeVisible();

    await page.getByRole('button', { name: /Review/ }).click();
    await page.getByRole('button', { name: 'Publish product' }).click();
    await expect(toastText(page, 'Product published')).toBeVisible();
    await expect(page.getByText('Published').first()).toBeVisible();

    await page.goto('/products');
    await page.getByLabel('Search').fill(CODE);
    await expect(page.getByRole('link', { name: new RegExp(NAME) })).toBeVisible();
  });

  test('SKU and barcode uniqueness is enforced', async ({ page }) => {
    await login(page, 'super_admin');
    await page.goto('/products');
    await page.getByLabel('Search').fill(CODE);
    await page.getByRole('link', { name: new RegExp(NAME) }).click();
    await page.getByRole('button', { name: /Variants/ }).click();

    const add = async (sku: string, barcode: string, size: string) => {
      await page.getByRole('button', { name: 'Add variant' }).first().click();
      await page.getByLabel(/^SKU/).fill(sku);
      await page.getByLabel('Barcode', { exact: true }).fill(barcode);
      await page.getByLabel(/^Retail price/).fill('500');
      await page.getByLabel('Size', { exact: true }).fill(size);
      await page.getByRole('button', { name: 'Save', exact: true }).click();
    };
    await add(`${CODE}-DUP`, BARCODE, 'dup-a');
    await expect(toastText(page, 'Variant saved')).toBeVisible();

    await add(`${CODE}-DUP`, '', 'dup-b');
    await expect(toastText(page, 'This SKU is already used by another variant.')).toBeVisible();
    await page.getByRole('button', { name: 'Cancel' }).click();

    await add(`${CODE}-DUP2`, BARCODE, 'dup-c');
    await expect(toastText(page, 'This barcode is already used by another variant.')).toBeVisible();
  });

  test('labels render as A4 and 50 × 25 mm PDFs', async ({ page }) => {
    await login(page, 'super_admin');
    await page.goto('/products/labels');
    await page.getByLabel('Find a variant').fill(CODE);
    await page.getByRole('list', { name: 'Search results' }).getByRole('button').first().click();
    await expect(page.getByText('1 label')).toBeVisible();

    const render = async (template: RegExp) => {
      await pick(page, 'Label size', template);
      const [res] = await Promise.all([page.waitForResponse((r) => r.url().endsWith('/api/v1/labels') && r.request().method() === 'POST'), page.getByRole('button', { name: 'Create PDF' }).click()]);
      expect(res.status()).toBe(200);
      expect(res.headers()['content-type']).toContain('application/pdf');
      expect((await res.body()).subarray(0, 4).toString()).toBe('%PDF');
      await expect(page.getByTitle('Preview')).toBeVisible();
    };
    await render(/A4 sheet/);
    await render(/Roll/);
  });

  test('import: a dry run reports row errors, a fixed file commits', async ({ page }, info) => {
    await login(page, 'super_admin');
    await page.goto('/products/import');
    await pick(page, 'What to import', 'Products and variants');
    const [download] = await Promise.all([page.waitForEvent('download'), page.getByRole('link', { name: 'Download CSV template' }).click()]);
    expect(download.suggestedFilename()).toMatch(/\.csv$/);
    const lines = parseCsv((await readFile((await download.path()) as string, 'utf8')).replace(/^\uFEFF/, ''));
    const header = lines[0] as string[];
    const sample = lines[1] as string[];
    const col = (name: string) => header.indexOf(name);
    const make = (cat: string, sku: string, barcode: string) => {
      const row = [...sample];
      row[col('product_code')] = `IMP-${stamp}`;
      row[col('name_en')] = `Imported ${stamp}`;
      row[col('category')] = cat;
      row[col('sku')] = sku;
      row[col('barcode')] = barcode;
      return row;
    };
    const csv = (rows: string[][]) => rows.map((r) => r.map((c) => `"${c.replace(/"/g, '""')}"`).join(',')).join('\n');
    const bad = csv([header, make('No Such Category', `IMP-${stamp}-1`, '')]);
    const good = csv([header, make(sample[col('category')] as string, `IMP-${stamp}-1`, '')]);

    await page.getByTestId('import-file').setInputFiles({ name: 'bad.csv', mimeType: 'text/csv', buffer: Buffer.from(bad) });
    await page.getByRole('button', { name: 'Check file' }).click();
    await expect(page.getByText('1 with problems')).toBeVisible();
    await expect(page.getByRole('cell', { name: 'No category with this name' })).toBeVisible();
    await expect(page.getByRole('button', { name: /^Import \d+ row/ })).toBeDisabled();

    await page.getByTestId('import-file').setInputFiles({ name: 'good.csv', mimeType: 'text/csv', buffer: Buffer.from(good) });
    await page.getByRole('button', { name: 'Check file' }).click();
    await expect(page.getByText('1 valid').first()).toBeVisible();
    await page.getByRole('button', { name: 'Import 1 row' }).click();
    await expect(page.getByText(/Import finished: 1 added/)).toBeVisible();
    info.annotations.push({ type: 'imported', description: `IMP-${stamp}` });

    // The export link downloads the catalogue and includes what was just imported.
    await page.goto('/products');
    await page.getByLabel('Search').fill(`IMP-${stamp}`);
    await expect(page.getByRole('link', { name: new RegExp(`Imported ${stamp}`) })).toBeVisible();
    const [exported] = await Promise.all([page.waitForEvent('download'), page.getByRole('link', { name: 'Export' }).click()]);
    expect(await readFile((await exported.path()) as string, 'utf8')).toContain(`IMP-${stamp}-1`);
  });

  test('a cashier cannot open the editor or the import page', async ({ page }) => {
    await login(page, 'cashier');
    await page.goto('/products/new');
    await expect(page.getByText('You do not have permission to do this.')).toBeVisible();
    await page.goto('/products/import');
    await expect(page.getByText('You do not have permission to do this.')).toBeVisible();
  });
});

/** Minimal RFC 4180 parser: quoted cells, doubled quotes, CRLF or LF. */
function parseCsv(text: string): string[][] {
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
