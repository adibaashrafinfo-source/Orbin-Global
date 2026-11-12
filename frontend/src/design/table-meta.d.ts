import '@tanstack/react-table';

declare module '@tanstack/react-table' {
  // eslint-disable-next-line @typescript-eslint/no-unused-vars
  interface ColumnMeta<TData, TValue> {
    /** Numbers are right-aligned with tabular figures (design rules). */
    align?: 'left' | 'right';
  }
}
