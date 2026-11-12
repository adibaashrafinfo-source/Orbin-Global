import * as Dialog from '@radix-ui/react-dialog';
import { AnimatePresence, motion } from 'framer-motion';
import { X } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/cn';
import { backdropVariants, drawerRightVariants, modalVariants } from '../motion';
import { useMotionSafe } from '../hooks/useMotionSafe';
import { Button } from './Button';

interface OverlayProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: ReactNode;
  description?: ReactNode;
  children: ReactNode;
  footer?: ReactNode;
  className?: string;
}

/**
 * The dialog's own close "X". It is auto-focused when the dialog opens, so it must NOT carry a tooltip:
 * a focus-opened tooltip is a dismissable layer and swallows the first Escape press.
 */
function CloseButton({ label }: { label: string }) {
  return (
    <Button variant="ghost" aria-label={label} className="h-10 w-10 rounded-pill px-0">
      <X className="h-5 w-5" aria-hidden="true" />
    </Button>
  );
}

/**
 * A fresh key for every time the overlay opens. Without it AnimatePresence treats a re-opened overlay as the same
 * element that is still exiting, which leaves Radix's dismissable-layer stack stale (Escape then stops working).
 */
function useOpenInstance(open: boolean): number {
  const [instance, setInstance] = useState(0);
  const [wasOpen, setWasOpen] = useState(open);
  if (open !== wasOpen) {
    setWasOpen(open);
    if (open) setInstance((i) => i + 1);
  }
  return instance;
}

function Backdrop() {
  const { variants } = useMotionSafe(backdropVariants);
  return (
    <Dialog.Overlay asChild forceMount>
      <motion.div
        variants={variants}
        initial="initial"
        animate="animate"
        exit="exit"
        className="fixed inset-0 z-40 bg-navy-950/40 backdrop-blur-[6px] motion-reduce:backdrop-blur-none"
      />
    </Dialog.Overlay>
  );
}

/** Modal: scale .98 → 1 + fade, backdrop blur 6px, radius 16. */
export function Modal({ open, onOpenChange, title, description, children, footer, className }: OverlayProps) {
  const { t } = useTranslation();
  const { variants } = useMotionSafe(modalVariants);
  const instance = useOpenInstance(open);
  return (
    <Dialog.Root open={open} onOpenChange={onOpenChange}>
      <AnimatePresence>
        {open && (
          <Dialog.Portal forceMount key={instance}>
            <Backdrop />
            <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
              <Dialog.Content asChild forceMount>
                <motion.div
                  variants={variants}
                  initial="initial"
                  animate="animate"
                  exit="exit"
                  className={cn('flex max-h-[90vh] w-full max-w-lg flex-col rounded-modal bg-surface shadow-lg focus:outline-none', className)}
                >
                  <div className="flex items-start justify-between gap-4 border-b border-navy-100 px-6 py-4">
                    <div>
                      <Dialog.Title className="font-heading text-lg font-bold text-navy-900">{title}</Dialog.Title>
                      {description ? (
                        <Dialog.Description className="mt-1 text-13 text-ink-muted">{description}</Dialog.Description>
                      ) : (
                        <Dialog.Description className="sr-only">{title}</Dialog.Description>
                      )}
                    </div>
                    <Dialog.Close asChild>
                      <CloseButton label={t('a11y.closeDialog')} />
                    </Dialog.Close>
                  </div>
                  <div className="flex-1 overflow-y-auto px-6 py-5">{children}</div>
                  {footer && <div className="flex justify-end gap-2 border-t border-navy-100 px-6 py-4">{footer}</div>}
                </motion.div>
              </Dialog.Content>
            </div>
          </Dialog.Portal>
        )}
      </AnimatePresence>
    </Dialog.Root>
  );
}

/** Right-side drawer, 480px default, slides in 320ms. */
export function Drawer({ open, onOpenChange, title, description, children, footer, className }: OverlayProps) {
  const { t } = useTranslation();
  const { variants } = useMotionSafe(drawerRightVariants);
  const instance = useOpenInstance(open);
  return (
    <Dialog.Root open={open} onOpenChange={onOpenChange}>
      <AnimatePresence>
        {open && (
          <Dialog.Portal forceMount key={instance}>
            <Backdrop />
            <Dialog.Content asChild forceMount>
              <motion.div
                variants={variants}
                initial="initial"
                animate="animate"
                exit="exit"
                className={cn('fixed inset-y-0 right-0 z-50 flex w-full max-w-[480px] flex-col bg-surface shadow-lg focus:outline-none sm:rounded-l-modal', className)}
              >
                <div className="flex items-start justify-between gap-4 border-b border-navy-100 px-6 py-4">
                  <div>
                    <Dialog.Title className="font-heading text-lg font-bold text-navy-900">{title}</Dialog.Title>
                    {description ? (
                      <Dialog.Description className="mt-1 text-13 text-ink-muted">{description}</Dialog.Description>
                    ) : (
                      <Dialog.Description className="sr-only">{title}</Dialog.Description>
                    )}
                  </div>
                  <Dialog.Close asChild>
                    <CloseButton label={t('a11y.closeDialog')} />
                  </Dialog.Close>
                </div>
                <div className="flex-1 overflow-y-auto px-6 py-5">{children}</div>
                {footer && <div className="flex justify-end gap-2 border-t border-navy-100 px-6 py-4">{footer}</div>}
              </motion.div>
            </Dialog.Content>
          </Dialog.Portal>
        )}
      </AnimatePresence>
    </Dialog.Root>
  );
}
