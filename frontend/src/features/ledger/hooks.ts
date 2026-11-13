import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useCallback } from 'react';
import { accountApi, LEDGER_KEYS, MONEY_KEYS } from './api';
import type { Account } from './types';

/** Accounts with balances where the user may see them, names only otherwise. Shared by every pick-an-account field. */
export function useAccounts() {
  return useQuery<Account[]>({ queryKey: LEDGER_KEYS.accounts, queryFn: accountApi.list, staleTime: 30_000 });
}

/** After any money movement: refresh balances, dues, ledgers, invoices and the parties behind them. */
export function useRefreshMoney(): () => void {
  const client = useQueryClient();
  return useCallback(() => {
    for (const key of MONEY_KEYS) void client.invalidateQueries({ queryKey: [...key] });
  }, [client]);
}

export const today = (): string => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Dhaka' }).format(new Date());
