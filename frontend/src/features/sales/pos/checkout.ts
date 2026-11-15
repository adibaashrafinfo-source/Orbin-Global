import type { CheckoutBody } from '../api';
import type { PaymentMethod, PosConfig } from '../types';
import { useCart } from './cartStore';
import type { PriceResult } from './pricing';
import { fromMoney } from './payMath';

export interface PayLine {
  id: string;
  method: PaymentMethod;
  amount: string;
  tendered: string;
  account_id: string;
  sender_number: string;
  trx_id: string;
  bank_name: string;
  bank_ref: string;
  transfer_date: string;
}

/** The checkout request for the cart as it stands. The server recomputes every figure; `client_expected_grand_total` is what the cashier saw. */
export function buildCheckoutBody(config: PosConfig, totals: PriceResult, payments: PayLine[], approvals?: { manager_pin: string; reason: string }): CheckoutBody {
  const s = useCart.getState();
  const v = s.vehicle;
  const hasVehicle = [v.reg_no, v.make, v.model, v.year, v.color].some((x) => x.trim() !== '');
  return {
    lines: s.lines.map((l) => ({ variant_id: l.variant.id, qty: l.qty, unit_price: l.unitPrice, discount_type: l.discountType, discount_value: l.discountValue, ...(l.note ? { note: l.note } : {}) })),
    customer_id: s.customer?.id ?? config.walk_in_customer_id,
    vehicle: hasVehicle ? { customer_vehicle_id: v.customer_vehicle_id, reg_no: v.reg_no, make: v.make, model: v.model, year: v.year, color: v.color } : null,
    sale_channel: s.channel,
    ...(s.salespersonId ? { salesperson_id: s.salespersonId } : {}),
    warehouse_id: config.warehouses.find((w) => w.is_default)?.id ?? config.warehouses[0]?.id ?? 0,
    cart_discount: { type: s.cartDiscountType, value: s.cartDiscountValue },
    delivery_charge: s.delivery,
    payments: payments.map((p) => ({
      method: p.method,
      amount: p.amount,
      account_id: p.account_id ? Number(p.account_id) : undefined,
      ...(p.method === 'cash' && p.tendered ? { tendered: p.tendered } : {}),
      ...(p.method === 'bkash' || p.method === 'nagad' || p.method === 'rocket' ? { sender_number: p.sender_number, trx_id: p.trx_id } : {}),
      ...(p.method === 'bank_transfer' ? { bank_name: p.bank_name, bank_ref: p.bank_ref, transfer_date: p.transfer_date || undefined } : {}),
    })),
    client_expected_grand_total: fromMoney(totals.grandTotal),
    ...(approvals ? { approvals } : {}),
    ...(s.note ? { note: s.note } : {}),
    ...(s.quotationId ? { quotation_id: s.quotationId } : {}),
  };
}
