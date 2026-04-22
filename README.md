# TOPSPOT Motorcycle Parts Trading Management System

Simplified integrated Point-of-Sale and trading management system built with:

- Non-OOP PHP (procedural)
- Tailwind CSS (CDN)
- MySQL

## 1) Setup Steps (XAMPP)

1. Start Apache and MySQL from XAMPP control panel.
2. Create database and tables:
   - Open `http://localhost/phpmyadmin`
   - Import `database.sql`
3. Place project under:
   - `c:/xampp/htdocs/client_2`
4. Open in browser:
   - `http://localhost/client_2/index.php`

## 2) Default Login

- Username: `admin`
- Password: `admin123`

You can create additional users in `register.php`.

## 3) System Flow Implemented (based on flowchart)

1. Sales Department (`sales.php`)
   - Assist customer and confirm order
   - Input order to POS terminal
   - Live inventory query
   - If stock unavailable, auto prepare supplier order and set order status to pending stock
   - If stock available, generate digital sales order for cashier
   - Orders cannot be moved to cashier queue while any item is out of stock

2. Cashier Department (`cashier.php`)
   - Receive sales order
   - Compute total tax (12%)
   - Process payment
   - Issue receipt
   - Update sales and inventory logs in real time

3. Inventory System (`inventory.php`)
   - Store transaction logs
   - Auto deduct stock upon sale
   - Check threshold
   - Trigger low stock reorder and update notifications
   - Run automatic low-stock monitoring without repeatedly inflating existing supplier order quantities

4. Purchasing Department (`purchasing.php`)
   - Prepare supplier orders (manual and automatic)
   - Receive goods
   - Update inventory database on receiving
   - Only requested purchase orders can be received through the dedicated receive flow
   - Automatically release eligible pending sales orders to cashier after stock receiving

5. Accounting Department (`accounting.php`)
   - Record transactions to general ledger
   - Receive and store digital logs
   - Validate and audit posting

6. Financial Reporting (`reports.php`)
   - Automated posting summary
   - Revenue and tax are recognized by payment date for period accuracy
   - Income Statement generation
   - Balance Sheet generation

## 4) Sidebar Pages

- `dashboard.php`
- `sales.php`
- `cashier.php`
- `inventory.php`
- `purchasing.php`
- `accounting.php`
- `reports.php`

## 5) Modal-based Actions

The system uses modal forms/buttons for actions such as:

- Create
- Edit
- Delete
- View
- Department-specific actions (process payment, receive goods, validate logs)

## 6) Notes

- UI is styled with navy blue and white color tone.
- Layout is sidebar-based and mobile friendly.
- All core logic is procedural PHP.
