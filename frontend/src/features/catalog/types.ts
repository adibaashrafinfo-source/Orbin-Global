export const MIRROR_TYPES = ['side_mirror', 'interior_mirror', 'mirror_glass', 'mirror_cover', 'mirror_signal_lamp', 'mirror_motor', 'blind_spot_mirror', 'accessory'] as const;
export type MirrorType = (typeof MIRROR_TYPES)[number];

export const POSITIONS = ['left', 'right', 'interior', 'pair', 'universal'] as const;
export type Position = (typeof POSITIONS)[number];

export const ADJUSTMENTS = ['manual', 'electric', 'cable'] as const;
export type Adjustment = (typeof ADJUSTMENTS)[number];

export const FEATURE_FLAGS = ['heated', 'auto_fold', 'turn_signal', 'blind_spot_sensor', 'side_camera', 'memory_tilt', 'auto_dimming', 'puddle_light'] as const;
export type FeatureFlag = (typeof FEATURE_FLAGS)[number];

export const IMAGE_LABELS = ['front', 'back', 'connector', 'package', 'size-chart', 'other'] as const;
export type ImageLabel = (typeof IMAGE_LABELS)[number];

export const PRICE_COLUMNS = ['retail_price', 'wholesale_price', 'dealer_price', 'mrp', 'min_price'] as const;
export type PriceColumn = (typeof PRICE_COLUMNS)[number];

export interface ProductImage {
  id: number;
  product_id: number;
  variant_id: number | null;
  url: string;
  thumb_url: string | null;
  label: ImageLabel;
  alt_text: string | null;
  sort_order: number;
  is_primary: boolean;
}

export interface CrossReference {
  id: number;
  ref_type: 'oem' | 'aftermarket' | 'competitor' | 'supplier';
  number: string;
  number_normalized: string;
  brand: string | null;
  note: string | null;
}

export interface Fitment {
  id: number;
  vehicle_model_id: number;
  year_from: number;
  year_to: number | null;
  trim: string | null;
  note: string | null;
  make: string | null;
  model: string | null;
  generation_code: string | null;
}

export interface BundleItem {
  id: number;
  component_variant_id: number;
  qty: string;
  sku: string | null;
  variant_name: string | null;
}

export interface Variant {
  id: number;
  product_id: number;
  sku: string;
  barcode: string | null;
  barcode_aliases: string[];
  variant_name: string | null;
  position: Position;
  size_width_mm: string | null;
  size_height_mm: string | null;
  thickness_mm: string | null;
  size_label: string | null;
  weight_g: string | null;
  glass_type_id: number | null;
  tint_id: number | null;
  color_finish_id: number | null;
  is_glass_only: boolean;
  cover_included: boolean;
  adjustment: Adjustment | null;
  heated: boolean;
  auto_fold: boolean;
  turn_signal: boolean;
  blind_spot_sensor: boolean;
  side_camera: boolean;
  memory_tilt: boolean;
  auto_dimming: boolean;
  puddle_light: boolean;
  pin_count: number | null;
  connector_note: string | null;
  supplier_part_no: string | null;
  year_from: number | null;
  year_to: number | null;
  retail_price: string;
  wholesale_price: string | null;
  dealer_price: string | null;
  mrp: string | null;
  min_price: string | null;
  /** Present only when the user holds products.view_cost. */
  last_cost?: string;
  tax_rate_id: number | null;
  reorder_level: string;
  reorder_qty: string;
  max_stock: string | null;
  default_location_id: number | null;
  track_serial: boolean;
  is_bundle: boolean;
  is_discontinued: boolean;
  is_active: boolean;
  internal_note: string | null;
  custom: Record<string, unknown>;
  stock_on_hand: string | null;
  glass_type?: string | null;
  tint?: string | null;
  color_finish?: string | null;
  cross_references?: CrossReference[];
  fitments?: Fitment[];
  images?: ProductImage[];
  bundle_items?: BundleItem[];
}

export interface Product {
  id: number;
  code: string;
  name_en: string;
  name_bn: string | null;
  mirror_type: MirrorType;
  category_id: number;
  brand_id: number | null;
  quality_grade_id: number;
  origin_country_id: number | null;
  short_description: string | null;
  description: string | null;
  warranty_months: number;
  return_window_days: number | null;
  tax_rate_id: number | null;
  unit_id: number;
  tags: string[];
  primary_image_url: string | null;
  custom: Record<string, unknown>;
  is_active: boolean;
  category?: string | null;
  brand?: string | null;
  quality_grade?: string | null;
  unit?: string | null;
  variants_count?: number;
  min_price?: string | null;
  max_price?: string | null;
  stock_total: string | null;
  variants?: Variant[];
  images?: ProductImage[];
  created_at: string | null;
  updated_at: string | null;
}

export interface LookupValueRow {
  id: number;
  type_id: number;
  code: string;
  label_en: string;
  label_bn: string | null;
  sort_order: number;
  is_active: boolean;
}

export interface LookupTypeRow {
  id: number;
  key: string;
  name_en: string;
  name_bn: string | null;
  description: string | null;
  is_system: boolean;
  is_active: boolean;
  values: LookupValueRow[];
}

export const CUSTOM_FIELD_TYPES = ['text', 'long_text', 'number', 'date', 'select', 'multi_select', 'boolean', 'url'] as const;
export type CustomFieldType = (typeof CUSTOM_FIELD_TYPES)[number];
export type CustomFieldEntity = 'product' | 'variant' | 'customer' | 'supplier';

export interface CustomFieldDefRow {
  id: number;
  entity: CustomFieldEntity;
  key: string;
  label_en: string;
  label_bn: string | null;
  field_type: CustomFieldType;
  options: { value: string; label_en: string; label_bn?: string }[] | { lookup: string } | null;
  is_required: boolean;
  show_on_pos_card: boolean;
  show_on_invoice: boolean;
  is_searchable: boolean;
  sort_order: number;
  is_active: boolean;
}

/** One row of every simple master list (makes, models, brands, categories, units, tax rates). */
export interface MasterRow {
  id: number;
  is_active: boolean;
  [key: string]: unknown;
}
