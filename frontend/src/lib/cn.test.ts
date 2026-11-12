import { describe, expect, it } from 'vitest';
import { cn } from './cn';

describe('cn (tailwind-merge with design-system font sizes)', () => {
  it('keeps text-13 and a text color together instead of treating text-13 as a color', () => {
    expect(cn('text-13 font-medium text-ink-muted')).toBe('text-13 font-medium text-ink-muted');
    expect(cn('bg-navy-900 text-surface', 'text-13')).toContain('text-surface');
    expect(cn('bg-navy-900 text-surface', 'text-13')).toContain('text-13');
  });

  it('still lets a later text color override an earlier one', () => {
    expect(cn('text-ink', 'text-danger')).toBe('text-danger');
  });
});
