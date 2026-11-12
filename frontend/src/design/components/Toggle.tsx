import * as RadixCheckbox from '@radix-ui/react-checkbox';
import * as RadixSwitch from '@radix-ui/react-switch';
import { Check } from 'lucide-react';
import { useId, type ReactNode } from 'react';
import { cn } from '@/lib/cn';

export interface CheckboxProps {
  checked: boolean;
  onChange: (checked: boolean) => void;
  label?: ReactNode;
  disabled?: boolean;
  id?: string;
  'aria-label'?: string;
}

export function Checkbox({ checked, onChange, label, disabled, id, ...rest }: CheckboxProps) {
  const autoId = useId();
  const cbId = id ?? autoId;
  return (
    <div className="flex items-center gap-2">
      <RadixCheckbox.Root
        id={cbId}
        checked={checked}
        disabled={disabled}
        onCheckedChange={(v) => onChange(v === true)}
        aria-label={rest['aria-label']}
        className="flex h-5 w-5 shrink-0 items-center justify-center rounded border border-navy-300 bg-surface transition-colors duration-fast data-[state=checked]:border-navy-900 data-[state=checked]:bg-navy-900 disabled:opacity-50"
      >
        <RadixCheckbox.Indicator>
          <Check className="h-3.5 w-3.5 text-surface" strokeWidth={3} aria-hidden="true" />
        </RadixCheckbox.Indicator>
      </RadixCheckbox.Root>
      {label && (
        <label htmlFor={cbId} className={cn('text-sm text-ink', disabled && 'opacity-50')}>
          {label}
        </label>
      )}
    </div>
  );
}

export interface SwitchProps {
  checked: boolean;
  onChange: (checked: boolean) => void;
  label?: ReactNode;
  description?: ReactNode;
  disabled?: boolean;
  id?: string;
}

export function Switch({ checked, onChange, label, description, disabled, id }: SwitchProps) {
  const autoId = useId();
  const swId = id ?? autoId;
  return (
    <div className="flex items-start justify-between gap-4">
      {(label || description) && (
        <div className="flex flex-col">
          {label && (
            <label htmlFor={swId} className="text-sm font-medium text-ink">
              {label}
            </label>
          )}
          {description && <span className="text-xs text-ink-subtle">{description}</span>}
        </div>
      )}
      <RadixSwitch.Root
        id={swId}
        checked={checked}
        disabled={disabled}
        onCheckedChange={onChange}
        className="relative h-6 w-11 shrink-0 rounded-pill bg-navy-200 transition-colors duration-base data-[state=checked]:bg-navy-900 disabled:opacity-50"
      >
        <RadixSwitch.Thumb className="block h-5 w-5 translate-x-0.5 rounded-pill bg-surface shadow-sm transition-transform duration-base ease-standard data-[state=checked]:translate-x-[22px] motion-reduce:transition-none" />
      </RadixSwitch.Root>
    </div>
  );
}
