import { ADJUSTMENTS, FEATURE_FLAGS, POSITIONS, type Adjustment, type FeatureFlag, type Position, type Variant } from '../types';

export const NONE = '__none__';

export interface VariantFormState {
  sku: string; barcode: string; aliases: string; supplier_part_no: string; position: Position;
  size_width_mm: string; size_height_mm: string; thickness_mm: string; size_label: string; weight_g: string;
  glass_type_id: string; tint_id: string; color_finish_id: string; adjustment: Adjustment | typeof NONE;
  pin_count: string; connector_note: string; year_from: string; year_to: string;
  retail_price: string; wholesale_price: string; dealer_price: string; mrp: string; min_price: string;
  is_glass_only: boolean; cover_included: boolean; is_discontinued: boolean; is_active: boolean; is_bundle: boolean; track_serial: boolean;
  internal_note: string; generate_barcode: boolean;
  flags: Record<FeatureFlag, boolean>;
}

const s = (v: string | number | null | undefined): string => (v === null || v === undefined ? '' : String(v));
const sel = (v: number | null | undefined): string => (v ? String(v) : NONE);

export function blankVariant(position: Position = 'left'): VariantFormState {
  return {
    sku: '', barcode: '', aliases: '', supplier_part_no: '', position, size_width_mm: '', size_height_mm: '', thickness_mm: '', size_label: '', weight_g: '',
    glass_type_id: NONE, tint_id: NONE, color_finish_id: NONE, adjustment: NONE, pin_count: '', connector_note: '', year_from: '', year_to: '',
    retail_price: '', wholesale_price: '', dealer_price: '', mrp: '', min_price: '',
    is_glass_only: false, cover_included: false, is_discontinued: false, is_active: true, is_bundle: false, track_serial: false, internal_note: '', generate_barcode: true,
    flags: Object.fromEntries(FEATURE_FLAGS.map((f) => [f, false])) as Record<FeatureFlag, boolean>,
  };
}

export function fromVariant(v: Variant): VariantFormState {
  return {
    sku: v.sku, barcode: s(v.barcode), aliases: v.barcode_aliases.join(', '), supplier_part_no: s(v.supplier_part_no), position: v.position,
    size_width_mm: s(v.size_width_mm), size_height_mm: s(v.size_height_mm), thickness_mm: s(v.thickness_mm), size_label: s(v.size_label), weight_g: s(v.weight_g),
    glass_type_id: sel(v.glass_type_id), tint_id: sel(v.tint_id), color_finish_id: sel(v.color_finish_id), adjustment: v.adjustment ?? NONE,
    pin_count: s(v.pin_count), connector_note: s(v.connector_note), year_from: s(v.year_from), year_to: s(v.year_to),
    retail_price: s(v.retail_price), wholesale_price: s(v.wholesale_price), dealer_price: s(v.dealer_price), mrp: s(v.mrp), min_price: s(v.min_price),
    is_glass_only: v.is_glass_only, cover_included: v.cover_included, is_discontinued: v.is_discontinued, is_active: v.is_active, is_bundle: v.is_bundle, track_serial: v.track_serial,
    internal_note: s(v.internal_note), generate_barcode: false,
    flags: Object.fromEntries(FEATURE_FLAGS.map((f) => [f, v[f]])) as Record<FeatureFlag, boolean>,
  };
}

const num = (v: string): number | null => (v.trim() === '' ? null : Number(v));
const txt = (v: string): string | null => (v.trim() === '' ? null : v.trim());
const ref = (v: string): number | null => (v === NONE || v === '' ? null : Number(v));

/** Request body. Price columns are only sent when the user may change prices (the API rejects them otherwise). */
export function variantBody(f: VariantFormState, isNew: boolean, canPrice: boolean): Record<string, unknown> {
  const body: Record<string, unknown> = {
    sku: txt(f.sku), position: f.position, supplier_part_no: txt(f.supplier_part_no),
    size_width_mm: num(f.size_width_mm), size_height_mm: num(f.size_height_mm), thickness_mm: num(f.thickness_mm), size_label: txt(f.size_label), weight_g: num(f.weight_g),
    glass_type_id: ref(f.glass_type_id), tint_id: ref(f.tint_id), color_finish_id: ref(f.color_finish_id), adjustment: f.adjustment === NONE ? null : f.adjustment,
    pin_count: num(f.pin_count), connector_note: txt(f.connector_note), year_from: num(f.year_from), year_to: num(f.year_to),
    is_glass_only: f.is_glass_only, cover_included: f.cover_included, is_discontinued: f.is_discontinued, is_active: f.is_active, is_bundle: f.is_bundle, track_serial: f.track_serial,
    internal_note: txt(f.internal_note), barcode_aliases: f.aliases.split(',').map((x) => x.trim()).filter(Boolean), ...f.flags,
  };
  if (txt(f.barcode) !== null || !isNew) body.barcode = txt(f.barcode);
  if (isNew) body.generate_barcode = f.generate_barcode && txt(f.barcode) === null;
  if (canPrice) {
    body.retail_price = f.retail_price.trim();
    for (const k of ['wholesale_price', 'dealer_price', 'mrp', 'min_price'] as const) body[k] = txt(f[k]);
  }
  return body;
}

export const POSITION_LIST = POSITIONS;
export const ADJUSTMENT_LIST = ADJUSTMENTS;
