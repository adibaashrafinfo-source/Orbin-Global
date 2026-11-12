import type { Transition, Variants } from 'framer-motion';
import { motion as motionTokens } from './tokens.generated';

// Motion presets per designSystem.motion. Only transform and opacity are animated.
const ms = (v: number) => v / 1000;

export const durations = {
  fast: ms(motionTokens.durationMs.fast),
  base: ms(motionTokens.durationMs.base),
  slow: ms(motionTokens.durationMs.slow),
  hero: ms(motionTokens.durationMs.hero),
} as const;

export const easings = {
  standard: [...motionTokens.easing.standard] as [number, number, number, number],
  emphasized: [...motionTokens.easing.emphasized] as [number, number, number, number],
} as const;

export const springSoft: Transition = {
  type: 'spring',
  stiffness: motionTokens.spring.soft.stiffness,
  damping: motionTokens.spring.soft.damping,
};

/** Page transition: fade + 8px upward slide, 220ms. */
export const pageVariants: Variants = {
  initial: { opacity: 0, y: 8 },
  animate: { opacity: 1, y: 0, transition: { duration: durations.base, ease: easings.standard } },
  exit: { opacity: 0, y: -8, transition: { duration: durations.fast, ease: easings.standard } },
};

/** List stagger: 30ms per item, max 12 animated items. */
export const STAGGER_MAX_ITEMS = 12;
export const listVariants: Variants = {
  hidden: {},
  show: { transition: { staggerChildren: 0.03 } },
};
export const listItemVariants: Variants = {
  hidden: { opacity: 0, y: 6 },
  show: { opacity: 1, y: 0, transition: { duration: durations.base, ease: easings.standard } },
};

/** Drawer/modal: scale .98 → 1 + fade; right drawer slides in 320ms. */
export const modalVariants: Variants = {
  initial: { opacity: 0, scale: 0.98 },
  animate: { opacity: 1, scale: 1, transition: { duration: durations.base, ease: easings.standard } },
  exit: { opacity: 0, scale: 0.98, transition: { duration: durations.fast } },
};
export const drawerRightVariants: Variants = {
  initial: { x: '100%' },
  animate: { x: 0, transition: { duration: 0.32, ease: easings.standard } },
  exit: { x: '100%', transition: { duration: durations.base, ease: easings.standard } },
};
export const backdropVariants: Variants = {
  initial: { opacity: 0 },
  animate: { opacity: 1, transition: { duration: durations.base } },
  exit: { opacity: 0, transition: { duration: durations.fast } },
};

/** Toast: slide from bottom-right with emphasized easing. */
export const toastVariants: Variants = {
  initial: { opacity: 0, x: 24, y: 8 },
  animate: { opacity: 1, x: 0, y: 0, transition: { duration: durations.base, ease: easings.emphasized } },
  exit: { opacity: 0, x: 24, transition: { duration: durations.fast } },
};

/** Button press: scale .97, spring release. */
export const pressTap = { scale: 0.97 } as const;

/** Reduced-motion variants: instant opacity only. */
export const reducedVariants: Variants = {
  initial: { opacity: 0 },
  animate: { opacity: 1, transition: { duration: 0 } },
  exit: { opacity: 0, transition: { duration: 0 } },
};
