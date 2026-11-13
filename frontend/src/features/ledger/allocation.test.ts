import { describe, expect, it } from 'vitest';
import { previewManual, previewOldestFirst, reminderText } from './allocation';
import type { OpenDoc } from './types';

const doc = (id: number, open: string, table: OpenDoc['table'] = 'sales'): OpenDoc => ({ table, id, no: `INV-${id}`, date: '2026-01-01', total: open, open });

describe('previewOldestFirst', () => {
  it('settles the oldest first and takes the rest off the next (AT-014 worked by hand)', () => {
    const p = previewOldestFirst([doc(1, '1000.00'), doc(2, '1000.00'), doc(3, '1000.00')], '2500.00');
    expect([...p.applied.values()]).toEqual([100000n, 100000n, 50000n]);
    expect([p.allocated, p.advance]).toEqual([250000n, 0n]);
  });
  it('keeps what is above everything due as an advance', () => {
    const p = previewOldestFirst([doc(1, '300.00')], '500.50');
    expect([p.allocated, p.advance]).toEqual([30000n, 20050n]);
  });
  it('treats an empty amount as nothing and handles no documents', () => {
    expect(previewOldestFirst([doc(1, '10.00')], '').allocated).toBe(0n);
    expect(previewOldestFirst([], '75.00').advance).toBe(7500n);
  });
  it('works to the paisa', () => {
    const p = previewOldestFirst([doc(1, '0.01'), doc(2, '0.02')], '0.02');
    expect([...p.applied.values()]).toEqual([1n, 1n]);
  });
});

describe('previewManual', () => {
  it('adds what was typed and flags a line above its document or a total above the payment', () => {
    const docs = [doc(1, '500.00'), doc(2, '800.00')];
    const ok = previewManual(docs, { 'sales:2': '600.00' }, '700.00');
    expect([ok.allocated, ok.advance, ok.overDue, ok.overAmount]).toEqual([60000n, 10000n, [], false]);
    const bad = previewManual(docs, { 'sales:1': '600.00', 'sales:2': '300.00' }, '700.00');
    expect([bad.overDue, bad.overAmount]).toEqual([['sales:1'], true]);
  });
  it('ignores zero and blank lines', () => {
    const p = previewManual([doc(1, '100.00')], { 'sales:1': '0' }, '50.00');
    expect([p.allocated, p.advance]).toEqual([0n, 5000n]);
  });
});

describe('reminderText', () => {
  it('fills the template', () => {
    expect(reminderText({ name: 'Rahim', amount: '৳ 5,000.00', company: 'Orbin Global', template: 'Dear {{name}}, {{amount}} is due to {{company}}.' })).toBe('Dear Rahim, ৳ 5,000.00 is due to Orbin Global.');
  });
});
