/** Declarative description of the six simple master lists served by /api/v1/{resource} (FR-201). */
export type ResourceName = 'vehicle-makes' | 'vehicle-models' | 'product-brands' | 'categories' | 'units' | 'tax-rates';

export type FieldKind = 'text' | 'integer' | 'decimal' | 'switch' | 'make' | 'parent' | 'lookup';

export interface FieldDef {
  key: string;
  /** i18n key under masterData.fields */
  label: string;
  kind: FieldKind;
  required?: boolean;
  /** Lookup type key for kind 'lookup'. */
  lookup?: string;
  /** Locked once the row exists (codes are referenced by imports). */
  lockedOnEdit?: boolean;
  maxLength?: number;
}

export interface ColumnDef {
  key: string;
  label: string;
  kind: 'text' | 'bool' | 'number' | 'make' | 'count' | 'lookup' | 'percent';
  lookup?: string;
}

export interface ResourceDef {
  name: ResourceName;
  fields: FieldDef[];
  columns: ColumnDef[];
  reorder: boolean;
  tree: boolean;
  /** Field shown as the row's title in confirmations. */
  titleKey: string;
  hasMakeFilter: boolean;
  hasActiveSwitch: boolean;
}

export const RESOURCES: Record<ResourceName, ResourceDef> = {
  'vehicle-makes': {
    name: 'vehicle-makes',
    titleKey: 'name',
    reorder: true,
    tree: false,
    hasMakeFilter: false,
    hasActiveSwitch: true,
    fields: [{ key: 'name', label: 'name', kind: 'text', required: true, maxLength: 191 }],
    columns: [
      { key: 'name', label: 'name', kind: 'text' },
      { key: 'models_count', label: 'modelsCount', kind: 'count' },
    ],
  },
  'vehicle-models': {
    name: 'vehicle-models',
    titleKey: 'name',
    reorder: false,
    tree: false,
    hasMakeFilter: true,
    hasActiveSwitch: true,
    fields: [
      { key: 'make_id', label: 'make', kind: 'make', required: true },
      { key: 'name', label: 'name', kind: 'text', required: true, maxLength: 191 },
      { key: 'generation_code', label: 'generationCode', kind: 'text', maxLength: 100 },
      { key: 'body_type_id', label: 'bodyType', kind: 'lookup', lookup: 'body_type' },
      { key: 'year_from', label: 'yearFrom', kind: 'integer' },
      { key: 'year_to', label: 'yearTo', kind: 'integer' },
    ],
    columns: [
      { key: 'make_id', label: 'make', kind: 'make' },
      { key: 'name', label: 'name', kind: 'text' },
      { key: 'generation_code', label: 'generationCode', kind: 'text' },
      { key: 'body_type_id', label: 'bodyType', kind: 'lookup', lookup: 'body_type' },
      { key: 'year_from', label: 'yearFrom', kind: 'number' },
      { key: 'year_to', label: 'yearTo', kind: 'number' },
    ],
  },
  'product-brands': {
    name: 'product-brands',
    titleKey: 'name',
    reorder: false,
    tree: false,
    hasMakeFilter: false,
    hasActiveSwitch: true,
    fields: [
      { key: 'name', label: 'name', kind: 'text', required: true, maxLength: 191 },
      { key: 'country_id', label: 'country', kind: 'lookup', lookup: 'origin_country' },
      { key: 'website', label: 'website', kind: 'text', maxLength: 255 },
      { key: 'is_oem', label: 'isOem', kind: 'switch' },
    ],
    columns: [
      { key: 'name', label: 'name', kind: 'text' },
      { key: 'country_id', label: 'country', kind: 'lookup', lookup: 'origin_country' },
      { key: 'is_oem', label: 'isOem', kind: 'bool' },
    ],
  },
  categories: {
    name: 'categories',
    titleKey: 'name_en',
    reorder: true,
    tree: true,
    hasMakeFilter: false,
    hasActiveSwitch: true,
    fields: [
      { key: 'parent_id', label: 'parent', kind: 'parent' },
      { key: 'name_en', label: 'nameEn', kind: 'text', required: true, maxLength: 255 },
      { key: 'name_bn', label: 'nameBn', kind: 'text', maxLength: 255 },
      { key: 'slug', label: 'slug', kind: 'text', maxLength: 191 },
    ],
    columns: [
      { key: 'name_en', label: 'nameEn', kind: 'text' },
      { key: 'name_bn', label: 'nameBn', kind: 'text' },
      { key: 'slug', label: 'slug', kind: 'text' },
      { key: 'children_count', label: 'childrenCount', kind: 'count' },
    ],
  },
  units: {
    name: 'units',
    titleKey: 'name_en',
    reorder: false,
    tree: false,
    hasMakeFilter: false,
    hasActiveSwitch: true,
    fields: [
      { key: 'code', label: 'code', kind: 'text', required: true, lockedOnEdit: true, maxLength: 50 },
      { key: 'name_en', label: 'nameEn', kind: 'text', required: true, maxLength: 255 },
      { key: 'name_bn', label: 'nameBn', kind: 'text', maxLength: 255 },
      { key: 'is_pair_unit', label: 'isPairUnit', kind: 'switch' },
    ],
    columns: [
      { key: 'code', label: 'code', kind: 'text' },
      { key: 'name_en', label: 'nameEn', kind: 'text' },
      { key: 'name_bn', label: 'nameBn', kind: 'text' },
      { key: 'is_pair_unit', label: 'isPairUnit', kind: 'bool' },
    ],
  },
  'tax-rates': {
    name: 'tax-rates',
    titleKey: 'name',
    reorder: false,
    tree: false,
    hasMakeFilter: false,
    hasActiveSwitch: true,
    fields: [
      { key: 'code', label: 'code', kind: 'text', required: true, lockedOnEdit: true, maxLength: 50 },
      { key: 'name', label: 'name', kind: 'text', required: true, maxLength: 255 },
      { key: 'rate_percent', label: 'ratePercent', kind: 'decimal', required: true },
      { key: 'is_default', label: 'isDefault', kind: 'switch' },
    ],
    columns: [
      { key: 'code', label: 'code', kind: 'text' },
      { key: 'name', label: 'name', kind: 'text' },
      { key: 'rate_percent', label: 'ratePercent', kind: 'percent' },
      { key: 'is_default', label: 'isDefault', kind: 'bool' },
    ],
  },
};

export const RESOURCE_ORDER: ResourceName[] = ['vehicle-makes', 'vehicle-models', 'product-brands', 'categories', 'units', 'tax-rates'];

export function isResourceName(v: string | undefined): v is ResourceName {
  return !!v && v in RESOURCES;
}
