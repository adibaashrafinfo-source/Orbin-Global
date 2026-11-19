import { forwardRef, useId, type InputHTMLAttributes, type ReactNode, type TextareaHTMLAttributes } from 'react';
import { cn } from '@/lib/cn';
import { Label } from './Label';

const base =
  'w-full rounded-input border border-navy-200 bg-surface px-3 text-base md:text-sm text-ink placeholder:text-ink-muted transition-[border-color,box-shadow] duration-fast focus:border-navy-600 focus:outline-none focus:shadow-[0_0_0_3px_theme(colors.navy.600/0.15)] disabled:bg-navy-50 disabled:text-ink-muted disabled:cursor-not-allowed aria-[invalid=true]:border-danger';

export interface InputProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'prefix'> {
  label?: ReactNode;
  hint?: ReactNode;
  error?: string | undefined;
  /** 44px height in POS, 40px elsewhere. */
  pos?: boolean;
  prefix?: ReactNode;
  suffix?: ReactNode;
}

export const Input = forwardRef<HTMLInputElement, InputProps>(function Input(
  { label, hint, error, pos, prefix, suffix, className, id, required, type = 'text', ...props },
  ref,
) {
  const autoId = useId();
  const inputId = id ?? autoId;
  const describedBy = error ? `${inputId}-error` : hint ? `${inputId}-hint` : undefined;
  return (
    <div className="flex flex-col gap-1.5">
      {label && (
        <Label htmlFor={inputId} required={required}>
          {label}
        </Label>
      )}
      <div className="relative flex items-center">
        {prefix && <span className="pointer-events-none absolute left-3 text-sm text-ink-muted">{prefix}</span>}
        <input
          ref={ref}
          id={inputId}
          type={type}
          required={required}
          aria-invalid={error ? true : undefined}
          aria-describedby={describedBy}
          className={cn(base, pos ? 'h-11' : 'h-10', prefix && 'pl-8', suffix && 'pr-10', type === 'number' && 'tabular text-right', className)}
          {...props}
        />
        {suffix && <span className="absolute right-2 flex items-center">{suffix}</span>}
      </div>
      {error ? (
        <p id={`${inputId}-error`} role="alert" className="text-xs text-danger animate-in fade-in-0 duration-fast motion-reduce:animate-none">
          {error}
        </p>
      ) : hint ? (
        <p id={`${inputId}-hint`} className="text-xs text-ink-muted">
          {hint}
        </p>
      ) : null}
    </div>
  );
});

export interface TextareaProps extends TextareaHTMLAttributes<HTMLTextAreaElement> {
  label?: ReactNode;
  hint?: ReactNode;
  error?: string | undefined;
}

export const Textarea = forwardRef<HTMLTextAreaElement, TextareaProps>(function Textarea(
  { label, hint, error, className, id, required, rows = 3, ...props },
  ref,
) {
  const autoId = useId();
  const inputId = id ?? autoId;
  return (
    <div className="flex flex-col gap-1.5">
      {label && (
        <Label htmlFor={inputId} required={required}>
          {label}
        </Label>
      )}
      <textarea
        ref={ref}
        id={inputId}
        rows={rows}
        required={required}
        aria-invalid={error ? true : undefined}
        aria-describedby={error ? `${inputId}-error` : undefined}
        className={cn(base, 'py-2 leading-5', className)}
        {...props}
      />
      {error ? (
        <p id={`${inputId}-error`} role="alert" className="text-xs text-danger">
          {error}
        </p>
      ) : hint ? (
        <p className="text-xs text-ink-muted">{hint}</p>
      ) : null}
    </div>
  );
});
