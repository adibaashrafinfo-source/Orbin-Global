import { http, type Envelope } from '@/lib/api';

export interface NumberSequence {
  id: number;
  key: string;
  branch_id: number | null;
  prefix: string;
  date_token: string;
  padding: number;
  reset_policy: 'never' | 'yearly' | 'monthly';
  period_key: string;
  next_value: number;
  example: string;
}

export const NUMBERING_KEY = ['settings', 'numbering'] as const;

export const numberingApi = {
  list: () => http.get<Envelope<NumberSequence[]>>('/settings/numbering').then((r) => r.data.data),
  update: (id: number, body: Pick<NumberSequence, 'prefix' | 'date_token' | 'padding' | 'reset_policy'> & { next_value?: number }) =>
    http.put<Envelope<NumberSequence>>(`/settings/numbering/${id}`, body).then((r) => r.data.data),
};
