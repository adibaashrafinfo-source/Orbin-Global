import { Banknote, Landmark, Smartphone, type LucideIcon } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/design/components';
import { AnimatedMoney } from '@/features/sales/pos/AnimatedMoney';
import { cn } from '@/lib/cn';
import { toPaisa } from '@/lib/money';
import type { Account, AccountType } from './types';

/** Neutral glyphs only: the mobile-money and bank brands' own logos are never copied (CLAUDE.md rule 13). */
const GLYPH: Record<AccountType, LucideIcon> = { cash: Banknote, bkash: Smartphone, nagad: Smartphone, rocket: Smartphone, bank: Landmark };

/** One account as a card: what it is, what it holds now. The whole card selects it. */
export function AccountCard({ account, selected, onSelect }: { account: Account; selected: boolean; onSelect: () => void }) {
  const { t } = useTranslation();
  const Icon = GLYPH[account.account_type];
  const negative = account.balance !== undefined && toPaisa(account.balance) < 0n;
  return (
    <button type="button" aria-pressed={selected} onClick={onSelect} data-testid={`account-card-${account.code}`}
      className={cn('card mirror-shine flex flex-col gap-3 p-5 text-start transition-shadow duration-fast focus-visible:focus-ring-light', selected ? 'ring-2 ring-navy-800' : 'hover:shadow-md', !account.is_active && 'opacity-60')}>
      <span className="flex items-center justify-between">
        <span className="flex items-center gap-2 text-13 font-medium text-ink-muted"><span className="rounded-pill bg-navy-100 p-2 text-navy-800"><Icon className="h-4 w-4" aria-hidden="true" /></span>{t(`ledger.accountTypes.${account.account_type}`)}</span>
        <span className="flex gap-1">{account.is_default_for_method && <Badge tone="navy">{t('ledger.accounts.default')}</Badge>}{!account.is_active && <Badge tone="neutral">{t('common.inactive')}</Badge>}</span>
      </span>
      <span className="font-heading text-base font-bold text-navy-900">{account.name}</span>
      {account.account_number && <span className="-mt-2 text-xs text-ink-muted">{account.account_number}</span>}
      {account.balance !== undefined && <AnimatedMoney paisa={toPaisa(account.balance)} className={cn('font-heading text-2xl font-bold tabular', negative ? 'text-danger' : 'text-navy-900')} />}
    </button>
  );
}
