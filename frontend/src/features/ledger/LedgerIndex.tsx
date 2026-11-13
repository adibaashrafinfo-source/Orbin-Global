import { Navigate } from 'react-router-dom';
import { useSession } from '@/features/auth/useSession';
import { LEDGER_TABS } from './tabs';

/** /ledger lands on the first finance tab the person may use, so a cashier (who can only take payments) is not sent to a forbidden page. */
export function LedgerIndex() {
  const { can } = useSession();
  const first = LEDGER_TABS.find((tab) => can([...tab.any]));
  return <Navigate to={first?.to ?? '/dashboard'} replace />;
}
