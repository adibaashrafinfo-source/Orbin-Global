import * as RadixToast from '@radix-ui/react-toast';
import { AnimatePresence, motion } from 'framer-motion';
import { CheckCircle2, Info, TriangleAlert, X, XCircle } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { create } from 'zustand';
import { cn } from '@/lib/cn';
import { toastVariants } from '../motion';
import { useMotionSafe } from '../hooks/useMotionSafe';

export type ToastTone = 'success' | 'info' | 'warning' | 'danger';

export interface ToastItem {
  id: number;
  tone: ToastTone;
  title: string;
  body?: string;
  /** Optional 0–100 progress (e.g. "Download started"). */
  progress?: number;
}

interface ToastStore {
  items: ToastItem[];
  push: (item: Omit<ToastItem, 'id'>) => number;
  update: (id: number, patch: Partial<ToastItem>) => void;
  dismiss: (id: number) => void;
}

let nextId = 1;
export const useToastStore = create<ToastStore>((set) => ({
  items: [],
  push: (item) => {
    const id = nextId++;
    set((s) => ({ items: [...s.items.slice(-4), { ...item, id }] }));
    return id;
  },
  update: (id, patch) => set((s) => ({ items: s.items.map((i) => (i.id === id ? { ...i, ...patch } : i)) })),
  dismiss: (id) => set((s) => ({ items: s.items.filter((i) => i.id !== id) })),
}));

/** Imperative API: toast.success('Saved'). */
export const toast = {
  success: (title: string, body?: string) => useToastStore.getState().push({ tone: 'success', title, body }),
  info: (title: string, body?: string) => useToastStore.getState().push({ tone: 'info', title, body }),
  warning: (title: string, body?: string) => useToastStore.getState().push({ tone: 'warning', title, body }),
  error: (title: string, body?: string) => useToastStore.getState().push({ tone: 'danger', title, body }),
  progress: (title: string, progress: number) => useToastStore.getState().push({ tone: 'info', title, progress }),
  update: (id: number, patch: Partial<ToastItem>) => useToastStore.getState().update(id, patch),
  dismiss: (id: number) => useToastStore.getState().dismiss(id),
};

const icons = { success: CheckCircle2, info: Info, warning: TriangleAlert, danger: XCircle } as const;
const tones = { success: 'text-success', info: 'text-navy-600', warning: 'text-warning', danger: 'text-danger' } as const;

/** Toasts slide from bottom-right with emphasized easing, auto-dismiss 4 s, pause on hover. */
export function Toaster() {
  const { t } = useTranslation();
  const items = useToastStore((s) => s.items);
  const dismiss = useToastStore((s) => s.dismiss);
  const { variants } = useMotionSafe(toastVariants);
  return (
    <RadixToast.Provider swipeDirection="right" duration={4000}>
      <AnimatePresence>
        {items.map((item) => {
          const Icon = icons[item.tone];
          return (
            <RadixToast.Root key={item.id} asChild forceMount onOpenChange={(open) => !open && dismiss(item.id)} duration={item.progress !== undefined && item.progress < 100 ? Infinity : 4000}>
              <motion.div
                variants={variants}
                initial="initial"
                animate="animate"
                exit="exit"
                layout
                className="pointer-events-auto flex w-80 items-start gap-3 rounded-card border border-navy-100 bg-surface p-4 shadow-lg"
              >
                <Icon className={cn('mt-0.5 h-5 w-5 shrink-0', tones[item.tone])} aria-hidden="true" />
                <div className="flex-1">
                  <RadixToast.Title className="text-sm font-semibold text-ink">{item.title}</RadixToast.Title>
                  {item.body && <RadixToast.Description className="mt-0.5 text-13 text-ink-muted">{item.body}</RadixToast.Description>}
                  {item.progress !== undefined && (
                    <div className="mt-2 h-1.5 w-full overflow-hidden rounded-pill bg-navy-100" role="progressbar" aria-valuenow={item.progress} aria-valuemin={0} aria-valuemax={100}>
                      <div className="h-full rounded-pill bg-orange-500 transition-[width] duration-base" style={{ width: `${item.progress}%` }} />
                    </div>
                  )}
                </div>
                <RadixToast.Close aria-label={t('common.close')} className="rounded-pill p-1 text-ink-muted hover:bg-navy-100 hover:text-ink">
                  <X className="h-4 w-4" aria-hidden="true" />
                </RadixToast.Close>
              </motion.div>
            </RadixToast.Root>
          );
        })}
      </AnimatePresence>
      <RadixToast.Viewport className="pointer-events-none fixed bottom-4 right-4 z-[60] flex w-80 flex-col gap-2 outline-none" />
    </RadixToast.Provider>
  );
}
