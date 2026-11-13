import { useEffect, useRef } from 'react';

export interface PosShortcuts {
  search: () => void;
  finder: () => void;
  customer: () => void;
  delivery: () => void;
  discount: () => void;
  pay: () => void;
  hold: () => void;
  held: () => void;
  removeSelected: () => void;
  qty: (delta: number) => void;
  reprint: () => void;
  newSale: () => void;
}

const typing = (t: EventTarget | null): boolean => t instanceof HTMLElement && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable);

/**
 * PRD §6.1 shortcuts. Function keys and Alt/Ctrl combinations work anywhere; "/", "+", "-" and Del only when no field has focus.
 * The listener runs before the top bar's own "/" so the POS search wins on this screen. Esc is left to the dialogs.
 */
export function useShortcuts(on: PosShortcuts, enabled: boolean): void {
  const ref = useRef(on);
  useEffect(() => {
    ref.current = on;
  });
  useEffect(() => {
    if (!enabled) return;
    const handler = (e: KeyboardEvent) => {
      const h = ref.current;
      const run = (fn: () => void) => { e.preventDefault(); e.stopImmediatePropagation(); fn(); };
      if (e.key === 'F2') return run(h.search);
      if (e.key === 'F3') return run(h.finder);
      if (e.key === 'F4') return run(h.customer);
      if (e.key === 'F6') return run(h.delivery);
      if (e.key === 'F8') return run(h.discount);
      if (e.key === 'F9') return run(h.pay);
      if (e.key === 'F10') return run(h.hold);
      if (e.key === 'F11') return run(h.held);
      if (e.altKey && (e.key === 'n' || e.key === 'N')) return run(h.newSale);
      if (e.ctrlKey && (e.key === 'p' || e.key === 'P')) return run(h.reprint);
      if (typing(e.target) || e.ctrlKey || e.altKey || e.metaKey) return;
      if (e.key === '/') return run(h.search);
      if (e.key === '+' || e.key === '=') return run(() => h.qty(1));
      if (e.key === '-') return run(() => h.qty(-1));
      if (e.key === 'Delete') return run(h.removeSelected);
    };
    window.addEventListener('keydown', handler, true);
    return () => window.removeEventListener('keydown', handler, true);
  }, [enabled]);
}
