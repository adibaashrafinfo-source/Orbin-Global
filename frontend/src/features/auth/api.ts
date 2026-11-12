import { http, type Envelope } from '@/lib/api';
import type { AuthUser, Session } from '@/lib/permissions';

export interface SessionPayload extends Session {
  settings: { idle_lock_minutes: number; numeral_system: 'latn' | 'beng'; week_start: string };
}

export const authApi = {
  login: (body: { email: string; password: string; remember: boolean }) =>
    http.post<Envelope<SessionPayload>>('/auth/login', body).then((r) => r.data.data),
  logout: () => http.post('/auth/logout').then(() => undefined),
  me: () => http.get<Envelope<SessionPayload>>('/auth/me').then((r) => r.data.data),
  forgotPassword: (email: string) => http.post('/auth/forgot-password', { email }).then(() => undefined),
  resetPassword: (body: { token: string; email: string; password: string; password_confirmation: string }) =>
    http.post('/auth/reset-password', body).then(() => undefined),
  verifyPin: (pin: string) => http.post<Envelope<{ verified: boolean }>>('/auth/pin/verify', { pin }).then((r) => r.data.data),
  updateProfile: (body: Partial<Pick<AuthUser, 'full_name' | 'phone' | 'language'>>) =>
    http.put<Envelope<SessionPayload>>('/auth/profile', body).then((r) => r.data.data),
  changePassword: (body: { current_password: string; password: string; password_confirmation: string }) =>
    http.put<Envelope<SessionPayload>>('/auth/password', body).then((r) => r.data.data),
};
