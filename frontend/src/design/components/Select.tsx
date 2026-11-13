import * as RadixSelect from '@radix-ui/react-select';
import { Check, ChevronDown } from 'lucide-react';
import { useId, type ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { Label } from './Label';

export interface SelectOption {
  value: string;
  label: ReactNode;
  disabled?: boolean;
}

export interface SelectProps {
  value: string | null | undefined;
  onChange: (value: string) => void;
  options: SelectOption[];
  label?: ReactNode;
  placeholder?: string;
  error?: string | undefined;
  disabled?: boolean;
  required?: boolean;
  id?: string;
  className?: string;
  name?: string;
  'aria-label'?: string;
}

export function Select({ value, onChange, options, label, placeholder, error, disabled, required, id, className, name, 'aria-label': ariaLabel }: SelectProps) {
  const autoId = useId();
  const selectId = id ?? autoId;
  return (
    <div className={cn('flex flex-col gap-1.5', className)}>
      {label && (
        <Label htmlFor={selectId} required={required}>
          {label}
        </Label>
      )}
      <RadixSelect.Root value={value ?? undefined} onValueChange={onChange} disabled={disabled} name={name}>
        <RadixSelect.Trigger
          id={selectId}
          aria-label={ariaLabel}
          aria-invalid={error ? true : undefined}
          className={cn(
            'flex h-10 w-full items-center justify-between rounded-input border border-navy-200 bg-surface px-3 text-sm text-ink transition-[border-color,box-shadow] duration-fast focus:border-navy-600 focus:outline-none focus:shadow-[0_0_0_3px_theme(colors.navy.600/0.15)] disabled:bg-navy-50 disabled:text-ink-subtle data-[placeholder]:text-ink-subtle aria-[invalid=true]:border-danger',
          )}
        >
          <RadixSelect.Value placeholder={placeholder} />
          <RadixSelect.Icon>
            <ChevronDown className="h-4 w-4 text-ink-muted" aria-hidden="true" />
          </RadixSelect.Icon>
        </RadixSelect.Trigger>
        <RadixSelect.Portal>
          <RadixSelect.Content
            position="popper"
            sideOffset={4}
            className="z-50 max-h-72 min-w-[var(--radix-select-trigger-width)] overflow-hidden rounded-card border border-navy-200 bg-surface shadow-lg animate-in fade-in-0 zoom-in-95 motion-reduce:animate-none"
          >
            <RadixSelect.Viewport className="p-1">
              {options.map((o) => (
                <RadixSelect.Item
                  key={o.value}
                  value={o.value}
                  disabled={o.disabled}
                  className="relative flex h-9 cursor-default select-none items-center rounded-input pl-8 pr-3 text-sm text-ink outline-none data-[highlighted]:bg-navy-100 data-[disabled]:opacity-50"
                >
                  <RadixSelect.ItemIndicator className="absolute left-2">
                    <Check className="h-4 w-4 text-navy-700" aria-hidden="true" />
                  </RadixSelect.ItemIndicator>
                  <RadixSelect.ItemText>{o.label}</RadixSelect.ItemText>
                </RadixSelect.Item>
              ))}
            </RadixSelect.Viewport>
          </RadixSelect.Content>
        </RadixSelect.Portal>
      </RadixSelect.Root>
      {error && (
        <p role="alert" className="text-xs text-danger">
          {error}
        </p>
      )}
    </div>
  );
}
