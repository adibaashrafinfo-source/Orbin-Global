# Orbin POS: user guide (English)

For the people who use Orbin POS every day at the shop. Bangla version: `user-guide-bn.md`.

Sign in with your own email and password. The first time, you are asked to choose a new password. Each role sees only the screens it needs; if a menu item is missing, ask the owner.

## 1. Roles at a glance

| Role | Main work |
|---|---|
| **Cashier** | Sell at the POS, take payments, open and close your cash shift, quotations, returns (with approval), your own sales. |
| **Storekeeper** | Products, stock, goods receipts from suppliers, stock counts, adjustments and transfers, reorder list. |
| **Accountant** | Ledger: receive and pay, accounts, expenses, transfers, day book, dues, write-offs, statements. Cost and profit reports. |
| **Manager** | Almost everything in sales, stock and reports, overrides with a PIN, approvals. |
| **Owner (super admin)** | Everything, including company profile, users, roles, numbering and the period lock. |

Whoever is signed in is shown at the top right. The top bar also has the branch, whether a shift is open, and notifications.

## 2. Selling at the POS

Open **Sell → POS Terminal**. You need an open shift first (see section 6).

1. **Find the car.** Press **F3** for the vehicle finder, or pick make, model, year and side (Left, Right, Interior, Pair, Universal). Only mirrors that fit appear.
2. **Find a product** another way: type a SKU, barcode, OEM number, or a word from the name (press **/** or **F2** to focus the search). Exact matches appear first.
3. **Add to the cart.** Tap a size chip on a product card. Each tap adds one. A chip that is greyed out has no stock.
4. **Change quantities** with **+** and **−**, or remove a line with **Del**.
5. **Customer.** Press **F4** and pick a customer, or add a new one. A walk-in sale can only be paid in full.
6. **Discounts.** **F8** for a cart discount. A discount above your limit asks for the manager's PIN.
7. **Delivery charge.** **F6**.
8. **Pay.** Press **F9** or **Ctrl+Enter** to open the payment drawer. Enter one or more payments: Cash, bKash, Nagad, Rocket or Bank Transfer. Mobile money and bank payments need a sender number and a reference (TrxID). A TrxID used in the last 30 days is refused unless the manager enters a PIN.
9. **Complete.** The invoice is saved and the PDF downloads as `Invoice_<number>_<customer>.pdf`. Use the **Download** button on the success screen if it does not.

Other keys: **Esc** closes a dialog, **Alt+N** starts a new sale, **Ctrl+P** reprints the last invoice, **F10** holds the bill and **F11** resumes a held bill.

**Quotations** (price quotes, not sales) are made from the POS with the **Quotation** button. They open in a new tab for printing and do not touch stock. The list is at `/quotations` (not in the side menu yet).

If you see **Insufficient stock**, the sale is not lost: your cart stays as it is. Change the quantity or pick another size.

## 3. Customers

**Sell → Customers** lists customers with their dues. Open one to see their invoices, payments, vehicles and warranty. Add a customer's vehicles there so the POS can use them. When you add a customer, the screen warns if the phone number is already used by another customer.

## 4. Sales, returns and voids

**Sell → Sales** lists every invoice. Open one to see it, print it, or reprint it.

- **Return:** open the invoice, press **Return** (returns are listed at `/sales/returns`), choose the items and whether each is *resellable* (back to stock) or *broken* (written off as breakage). A manager approves the return. A credit note is made, and the money is refunded or the customer's account is credited.
- **Void:** a manager can void a sale on the same day. Stock, payments and the ledger are reversed, and the invoice shows **VOID**.

A returned or voided sale is never edited; corrections are always a return or a void.

## 5. Warranty

The warranty list opens at `/warranty` in this version (it is not in the side menu yet; ask the owner for the link). It lists sold items and their warranty end dates, so you can check a claim.

## 6. Your cash shift

Click the **shift** indicator at the top of the screen (it opens the shift page), or start from the prompt at the POS, and enter the float (the cash in the drawer at the start). While the shift is open, cash sales, customer receipts and expenses paid in cash are recorded against it.

**Close the shift** by counting the cash. The system shows the expected amount and the difference. Write a reason for any difference. Print the Z-report for the day. Only one shift can be open per cashier.

## 7. Stock (storekeeper and manager)

- **Stock → Products:** the catalogue. A product is a mirror type (side, interior, glass, cover, signal lamp, motor, blind spot, accessory) with its sizes (**variants**), images and vehicle fitment. The 8-step editor takes you through it. Import many products with a spreadsheet; the import first shows a **dry run** so you can see what would change before anything is saved.
- **Stock → Inventory:** stock by warehouse, low and out-of-stock items, the reorder list, stock movements, stock counts and adjustments. Adjustments above the approval limit need a manager's approval.
- **Stock → Purchases:** suppliers, purchase orders and **goods receipts** (GRN). When you post a receipt, freight and customs are shared across the items by value, and the average cost of each item is updated. A receipt cannot be edited after posting; correct it with a return to supplier.
- **Barcode labels:** print labels for variants from the product screen.

Stock never goes negative unless the owner allows it in settings.

## 8. Money (accountant and manager)

**Money → Ledger** has these screens:

- **Party ledger:** pick a customer or supplier to see their full account, with a running balance and a statement PDF.
- **Receive / pay:** take a payment from a customer or pay a supplier. The screen shows which invoices the payment will settle, oldest first. Anything left over is kept as an advance. A receipt PDF is made.
- **Accounts:** the cash drawer, bKash, Nagad, Rocket and bank accounts with their balances. An account cannot go below zero unless the owner allows it. **Adjust** corrects a count difference (with a reason).
- **Expenses:** enter a cost, the category, the account it was paid from and the payee. Expenses above the approval limit wait for another person to approve them. Mistakes are corrected by voiding, not editing.
- **Fund transfers:** move money between your accounts (for example bKash to the bank). A charge is recorded as an expense.
- **Day book:** every taka in and out for a date range, per account.
- **Dues and aging:** who owes the shop and whom the shop owes, in age groups (0–15, 16–30, 31–60, 61–90, over 90 days). Set a **promise to pay** date. **Copy reminder** puts a short message on the clipboard for WhatsApp or SMS; nothing is sent by the system.
- **Write-off and discount** (accountant and owner): forgive or discount a due, oldest first, with a reason.

**Period lock** (owner, under Settings): once a period is locked, nothing dated inside it can be created, edited or voided.

## 9. Reports and the dashboard

**Dashboard:** the day's figures at a glance, for the period you pick (Today, 7 days, 30 days, This month or Custom). It shows only what your role may see. It updates every minute.

**Reports:** open **Reports** and search for the report you want. Every report has:

- date range (or an as-of date), branch and warehouse where they apply;
- **Compare to previous period**, which shows the change against the period before;
- sorting by clicking a column heading;
- **Save this view** to keep the filters under a name for next time;
- **Export**: CSV, Excel or PDF, or **Print view**. Files are in English and have the same rows and totals as the screen.

Click any invoice or document number in a report to open that document.

Reports you cannot open are not listed for you. Cost and profit columns are hidden from roles without the cost permission.

## 10. Settings (owner, and manager where allowed)

- **Company profile:** legal name, address, phones, email, registration numbers, bank details and the signature. Invoices and receipts are blocked until the name, address and at least one phone are saved.
- **Branches, warehouses, numbering:** invoice and document number formats. Numbers never have gaps.
- **Users and roles:** create users, reset passwords, set a manager PIN, change permissions. The last active owner cannot be removed.
- **Vehicle makes and models, categories, units, taxes, lookups and custom fields:** add your own values; you do not need a developer.
- **Audit log:** every change to money, stock, prices, permissions, voids and overrides, with who did it and when.

## 11. Health page (owner)

**Admin → Health** shows whether the server is ready: PHP and database versions, disk space, the backup and the scheduler. **Last cron run** should be less than two minutes old. If a check is red, tell the person who looks after the hosting.

## 12. Passwords, PINs and safety

- Your password is yours. Do not share it or write it on paper at the counter.
- The **manager PIN** approves overrides (discounts above the limit, a reused TrxID, returns, adjustments). Managers enter it themselves; cashiers do not know it.
- After five wrong passwords or PINs, the account is locked for a while.
- Sign out at the end of your shift from the account menu, top right.

## 13. Getting help

Note the **request ID** shown at the bottom of an error message and tell the owner. Do not try to fix a failed sale by typing the invoice again: check **Sell → Sales** first to see whether it was saved.
