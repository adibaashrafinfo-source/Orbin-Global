import type { Config } from 'tailwindcss';
import plugin from 'tailwindcss/plugin';
import { colors, radius, shadows, typography, motion } from './tokens.generated';

// Tailwind preset built from the generated token file. No raw hex lives here.
const fontSizes: Record<string, [string, { lineHeight: string }]> = {
  xs: ['12px', { lineHeight: '16px' }],
  '13': ['13px', { lineHeight: '18px' }],
  sm: ['14px', { lineHeight: '20px' }],
  base: ['16px', { lineHeight: '24px' }],
  lg: ['18px', { lineHeight: '28px' }],
  xl: ['20px', { lineHeight: '28px' }],
  '2xl': ['24px', { lineHeight: '32px' }],
  '3xl': ['30px', { lineHeight: '36px' }],
  '4xl': ['36px', { lineHeight: '40px' }],
  '5xl': ['48px', { lineHeight: '52px' }],
};

const ease = (v: readonly number[]) => `cubic-bezier(${v.join(', ')})`;

export const orbinPreset: Partial<Config> = {
  darkMode: ['class'],
  theme: {
    extend: {
      colors: {
        navy: colors.navy,
        orange: colors.orange,
        success: colors.semantic.success,
        warning: colors.semantic.warning,
        danger: colors.semantic.danger,
        info: colors.semantic.info,
        ink: colors.neutral.text,
        'ink-muted': colors.neutral.textMuted,
        'ink-subtle': colors.neutral.textSubtle,
        surface: colors.neutral.surface,
        background: colors.neutral.background,
        line: colors.neutral.border,
      },
      fontFamily: {
        heading: [typography.heading, 'ui-sans-serif', 'system-ui', 'sans-serif'],
        body: [typography.body, 'ui-sans-serif', 'system-ui', 'sans-serif'],
        bangla: [...typography.bangla, 'sans-serif'],
      },
      fontSize: fontSizes,
      borderRadius: {
        input: `${radius.input}px`,
        button: `${radius.button}px`,
        card: `${radius.card}px`,
        modal: `${radius.modal}px`,
        pill: `${radius.pill}px`,
      },
      boxShadow: {
        sm: shadows.sm,
        md: shadows.md,
        lg: shadows.lg,
        'glow-orange': shadows.glowOrange,
      },
      transitionDuration: {
        fast: `${motion.durationMs.fast}ms`,
        base: `${motion.durationMs.base}ms`,
        slow: `${motion.durationMs.slow}ms`,
        hero: `${motion.durationMs.hero}ms`,
      },
      transitionTimingFunction: {
        standard: ease(motion.easing.standard),
        emphasized: ease(motion.easing.emphasized),
      },
      spacing: {
        sidebar: '264px',
        'sidebar-collapsed': '72px',
        topbar: '64px',
      },
      keyframes: {
        shimmer: {
          '0%': { transform: 'translateX(-100%)' },
          '100%': { transform: 'translateX(100%)' },
        },
        shine: {
          '0%': { transform: 'translateX(-150%) skewX(-20deg)', opacity: '0' },
          '15%': { opacity: '1' },
          '100%': { transform: 'translateX(250%) skewX(-20deg)', opacity: '0' },
        },
        'pulse-glow': {
          '0%, 100%': { opacity: '1' },
          '50%': { opacity: '0.55' },
        },
      },
      animation: {
        shimmer: 'shimmer 1.4s linear infinite',
        shine: `shine ${motion.durationMs.hero}ms ${ease(motion.easing.standard)} 1`,
        'pulse-glow': 'pulse-glow 2s ease-in-out infinite',
      },
    },
  },
  plugins: [
    plugin((api) => {
      api.addBase({
        ':root': {
          '--navy-900': colors.navy[900],
          '--orange-500': colors.orange[500],
          '--background': colors.neutral.background,
          '--surface': colors.neutral.surface,
        },
        html: { fontFamily: `${typography.body}, system-ui, sans-serif` },
        body: {
          backgroundColor: colors.neutral.background,
          color: colors.neutral.text,
          fontSize: '14px',
          lineHeight: '20px',
        },
        'h1,h2,h3,h4,h5,h6': { fontFamily: `${typography.heading}, system-ui, sans-serif` },
        '[lang="bn"], .font-bangla': { fontFamily: `${typography.bangla.join(', ')}, sans-serif` },
      });
      api.addUtilities({
        '.tabular': { fontVariantNumeric: 'tabular-nums' },
        '.focus-ring-light': {
          outline: 'none',
          boxShadow: `0 0 0 2px ${colors.neutral.surface}, 0 0 0 4px ${colors.navy[600]}`,
        },
        '.focus-ring-dark': {
          outline: 'none',
          boxShadow: `0 0 0 2px ${colors.navy[900]}, 0 0 0 4px ${colors.orange[300]}`,
        },
      });
      // Mirror Shine: diagonal gloss band sweeping once per hover. Disabled under reduced motion.
      api.addComponents({
        '.mirror-shine': {
          position: 'relative',
          overflow: 'hidden',
          isolation: 'isolate',
        },
        '.mirror-shine::after': {
          content: '""',
          position: 'absolute',
          top: '0',
          bottom: '0',
          left: '0',
          width: '45%',
          pointerEvents: 'none',
          opacity: '0',
          background: `linear-gradient(100deg, transparent 0%, color-mix(in srgb, ${colors.neutral.surface} 18%, transparent) 50%, transparent 100%)`,
          transform: 'translateX(-150%) skewX(-20deg)',
        },
        '.mirror-shine:hover::after': {
          animation: `shine ${motion.durationMs.hero}ms ${ease(motion.easing.standard)} 1`,
        },
        '@media (prefers-reduced-motion: reduce)': {
          '.mirror-shine::after': { display: 'none' },
          '.mirror-shine:hover::after': { animation: 'none' },
          '.animate-shimmer, .animate-pulse-glow, .animate-shine': { animation: 'none' },
        },
      });
    }),
  ],
};
