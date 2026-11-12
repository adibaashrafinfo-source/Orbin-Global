import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from './Button';
import { Input } from './Input';
import { Modal } from './Overlay';

export interface ConfirmDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: ReactNode;
  body?: ReactNode;
  confirmLabel?: ReactNode;
  danger?: boolean;
  /** Typed confirmation word for destructive actions (PRD §8.13). */
  typeToConfirm?: string;
  loading?: boolean;
  onConfirm: () => void | Promise<void>;
}

/** Danger buttons only live behind this dialog (CLAUDE.md §5). */
export function ConfirmDialog({ open, onOpenChange, title, body, confirmLabel, danger, typeToConfirm, loading, onConfirm }: ConfirmDialogProps) {
  const { t } = useTranslation();
  const [typed, setTyped] = useState('');
  const canConfirm = !typeToConfirm || typed.trim().toUpperCase() === typeToConfirm.toUpperCase();
  return (
    <Modal
      open={open}
      onOpenChange={(o) => {
        if (!o) setTyped('');
        onOpenChange(o);
      }}
      title={title}
      className="max-w-md"
      footer={
        <>
          <Button variant="secondary" onClick={() => onOpenChange(false)} disabled={loading}>
            {t('common.cancel')}
          </Button>
          <Button variant={danger ? 'danger' : 'primary'} onClick={() => void onConfirm()} disabled={!canConfirm} loading={loading}>
            {confirmLabel ?? t('common.confirm')}
          </Button>
        </>
      }
    >
      {body && <p className="text-sm text-ink">{body}</p>}
      {typeToConfirm && (
        <div className="mt-4">
          <Input label={t('common.typeToConfirm', { word: typeToConfirm })} value={typed} onChange={(e) => setTyped(e.target.value)} />
        </div>
      )}
    </Modal>
  );
}
