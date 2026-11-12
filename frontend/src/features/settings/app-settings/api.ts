import { http } from '@/lib/api';

export type SettingValue = string | number | boolean | null;

export interface SettingSchemaEntry {
  type: 'enum' | 'string' | 'int' | 'bool' | 'date';
  group: 'sales' | 'stock' | 'session' | 'invoice' | 'alerts';
  options?: (string | number | null)[];
  min?: number;
  max?: number;
}

export interface AppSettingsPayload {
  data: Record<string, SettingValue>;
  meta: { schema: Record<string, SettingSchemaEntry> };
}

export const APP_SETTINGS_KEY = ['settings', 'app'] as const;

export const appSettingsApi = {
  show: () => http.get<AppSettingsPayload>('/settings/app').then((r) => r.data),
  update: (values: Record<string, SettingValue>) => http.put<AppSettingsPayload>('/settings/app', values).then((r) => r.data),
};
