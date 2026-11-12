import type { Config } from 'tailwindcss';
import animate from 'tailwindcss-animate';
import { orbinPreset } from './src/design/tailwind-preset';

export default {
  content: ['./index.html', './src/**/*.{ts,tsx}'],
  presets: [orbinPreset],
  plugins: [animate],
} satisfies Config;
