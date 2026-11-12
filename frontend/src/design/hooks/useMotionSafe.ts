import { useReducedMotion } from 'framer-motion';
import type { Variants } from 'framer-motion';
import { reducedVariants } from '../motion';

/** Returns the given variants, or instant-opacity variants when the user prefers reduced motion. */
export function useMotionSafe(variants: Variants): { variants: Variants; reduced: boolean } {
  const reduced = useReducedMotion() ?? false;
  return { variants: reduced ? reducedVariants : variants, reduced };
}
