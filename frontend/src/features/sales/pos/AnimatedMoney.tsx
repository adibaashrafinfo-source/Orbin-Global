import { useReducedMotion } from 'framer-motion';
import { useEffect, useRef, useState } from 'react';
import { formatMoney, fromPaisa } from '@/lib/money';

/** Counts from the previous total to the new one over ~250 ms; jumps straight there when the user prefers reduced motion. */
export function AnimatedMoney({ paisa, className }: { paisa: bigint; className?: string }) {
  const reduced = useReducedMotion();
  const [shown, setShown] = useState(paisa);
  const from = useRef(paisa);
  useEffect(() => {
    if (reduced || from.current === paisa) {
      from.current = paisa;
      const id = requestAnimationFrame(() => setShown(paisa));
      return () => cancelAnimationFrame(id);
    }
    const start = performance.now();
    const a = from.current;
    let id = 0;
    const tick = (now: number) => {
      const p = Math.min(1, (now - start) / 250);
      setShown(a + BigInt(Math.round(Number(paisa - a) * (1 - (1 - p) ** 3))));
      if (p < 1) id = requestAnimationFrame(tick);
      else from.current = paisa;
    };
    id = requestAnimationFrame(tick);
    return () => cancelAnimationFrame(id);
  }, [paisa, reduced]);
  return <span className={className} data-value={fromPaisa(paisa)}>{formatMoney(fromPaisa(shown))}</span>;
}
