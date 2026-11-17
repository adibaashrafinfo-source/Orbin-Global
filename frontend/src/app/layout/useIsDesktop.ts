import { useEffect, useState } from 'react';

const QUERY = '(min-width: 1024px)';

/** True from the lg breakpoint up. Below it the sidebar is a drawer and the rail width does not apply. */
export function useIsDesktop(): boolean {
  const [desktop, setDesktop] = useState(() => typeof window !== 'undefined' && window.matchMedia(QUERY).matches);
  useEffect(() => {
    const mql = window.matchMedia(QUERY);
    const onChange = () => setDesktop(mql.matches);
    mql.addEventListener('change', onChange);
    return () => mql.removeEventListener('change', onChange);
  }, []);
  return desktop;
}
