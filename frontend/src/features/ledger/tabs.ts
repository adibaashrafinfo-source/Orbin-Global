export const LEDGER_TABS = [
  { key: 'parties', to: '/ledger/parties', any: ['ledger.view'] },
  { key: 'accounts', to: '/ledger/accounts', any: ['ledger.view', 'accounts.manage'] },
  { key: 'receive', to: '/ledger/receive', any: ['ledger.receive_payment', 'ledger.pay_supplier'] },
  { key: 'expenses', to: '/ledger/expenses', any: ['expenses.manage'] },
  { key: 'transfers', to: '/ledger/transfers', any: ['fund_transfers.manage'] },
  { key: 'daybook', to: '/ledger/day-book', any: ['ledger.view'] },
  { key: 'dues', to: '/ledger/dues', any: ['ledger.view'] },
] as const;
