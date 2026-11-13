import { describe, expect, it } from 'vitest';
import bn from './bn.json';
import en from './en.json';

function leafKeys(node: unknown, prefix = ''): string[] {
  if (typeof node !== 'object' || node === null) return [prefix];
  return Object.entries(node).flatMap(([k, v]) => leafKeys(v, prefix ? `${prefix}.${k}` : k));
}

describe('locales', () => {
  it('English and Bangla define exactly the same keys', () => {
    const a = new Set(leafKeys(en));
    const b = new Set(leafKeys(bn));
    expect([...a].filter((k) => !b.has(k))).toEqual([]);
    expect([...b].filter((k) => !a.has(k))).toEqual([]);
  });

  it('no translation is empty and placeholders match between languages', () => {
    const flat = (n: unknown, p = ''): [string, string][] => (typeof n === 'string' ? [[p, n]] : typeof n === 'object' && n !== null ? Object.entries(n).flatMap(([k, v]) => flat(v, p ? `${p}.${k}` : k)) : []);
    const bnMap = new Map(flat(bn));
    const placeholders = (s: string) => [...s.matchAll(/\{\{(\w+)\}\}/g)].map((m) => m[1]).sort().join(',');
    for (const [key, text] of flat(en)) {
      const other = bnMap.get(key) ?? '';
      expect(text.trim(), key).not.toBe('');
      expect(other.trim(), key).not.toBe('');
      expect(placeholders(other), `${key} placeholders`).toBe(placeholders(text));
    }
  });
});
