import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/cn';

export const LOGO_SRC = '/assets/brand/orbin-logo.jpg';

/**
 * The original logo on a white rounded plate (required on navy surfaces). Never recolored or cropped.
 * `ambient` adds the slow gloss pass used on the login screen (every 8 s; off under reduced motion).
 */
export function LogoPlate({ src, className, ambient, width = 240 }: { src?: string | null; className?: string; ambient?: boolean; width?: number }) {
  const { t } = useTranslation();
  return (
    <div className={cn('inline-flex items-center justify-center rounded-modal bg-surface p-5 shadow-lg', ambient && 'logo-ambient', className)} style={{ width }}>
      <img src={src ?? LOGO_SRC} alt={t('app.company')} className="h-auto w-full object-contain" style={{ minWidth: 120 }} draggable={false} />
    </div>
  );
}

/** Collapsed-sidebar emblem: the circular emblem region of the ORIGINAL file via CSS object-position (no edited copy). */
export function LogoEmblem({ src, size = 40, className }: { src?: string | null; size?: number; className?: string }) {
  const { t } = useTranslation();
  return (
    <div className={cn('overflow-hidden rounded-pill bg-surface', className)} style={{ width: size, height: size }} aria-hidden="true">
      <img src={src ?? LOGO_SRC} alt={t('app.company')} className="h-full w-full object-cover" style={{ objectPosition: '50% 18%', transform: 'scale(1.9)', transformOrigin: '50% 22%' }} draggable={false} />
    </div>
  );
}
