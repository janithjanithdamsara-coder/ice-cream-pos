# FrostyFlow - Ice Cream Distribution & POS System

A comprehensive web-based management system designed specifically for Ice Cream Distribution Hubs, Van/Lorry Sales, and Retail Counter POS. Built according to the operational workflow diagram.

---

## 🚀 Getting Started

The system is hosted in your XAMPP Apache `htdocs` directory and is already configured with automatic database setup.

1. **Start Apache & MySQL** in your XAMPP Control Panel (Already active).
2. Open your web browser and visit:
   ```
   http://localhost/ice%20creame/
   ```

---

## 🔑 Default User Accounts

| Role | Username | Password | Access Level |
|---|---|---|---|
| **Super Admin** | `admin` | `admin123` | Full access across all branches, reports & settings |
| **Branch Admin** | `branch_admin` | `admin123` | Colombo Branch store, lorry dispatches & cash |
| **Cashier** | `cashier` | `cashier123` | Retail POS billing counter |

*(You can also use the 1-click quick login buttons on the login screen to sign in instantly).*

---

## 📌 Features Mapped to Your Sketch

### 1. Stock Management (උඩ කොටස)
- **In Come Stock (GRN)**: Record incoming stock with `INV NO`, `Date`, `What Branch`, and line items (e.g. `Vanilla 1L [160]`).
- Directly adds stock into the **Main Store (Warehouse)**.
- Real-time stock ledger with low stock alerts and adjustment tools.

### 2. Store & Lorry Flow (වම් පැත්ත)
- **Morning Dispatch**: Select Lorry by Number Plate (e.g. `WP CAB-4521`), choose products, and load stock out of Main Store into the lorry.
- Automatically validates store stock availability and tracks outgoing inventory.

### 3. Returns (3:00 PM Cutoff)
- **Evening Settlement**:
  - `Loaded Qty` (e.g. 150)
  - `Good Returns` (e.g. 20) -> **Automatically credited back to Main Store stock!**
  - `Damaged / Melted Qty` (e.g. 20) -> Recorded as loss with reasons.
  - `Sold Qty` (e.g. 110) & `Expected Cash` calculated automatically.
  - Tracks driver cash handover and difference (Shortage/Excess).

### 4. Daily Cash & POS (දකුණු පැත්ත)
- **Retail Counter POS**: Fast, visual product catalog with categories (Tubs, Cones, Cups), search, real-time cart, discount, quick cash buttons, and printable thermal receipts.
- **Daily Cash Register**: Merges Counter POS cash + Lorry handover cash - Day expenses to balance daily cash.

### 5. Admin Hierarchy (පහළ දකුණු පැත්ත)
- Super Admin &rarr; Branch Admins &rarr; Multi-Branch Network &rarr; Cashiers.
- Multi-branch support with branch switcher and role-based permissions.

### 6. Reports & UI (පහළ වම් පැත්ත)
- **Sell Report**: POS sales + Lorry sales breakdown with date range filters.
- **Store Report**: Stock valuation, in-store units, and alert levels.
- **Lorry Report**: Performance by vehicle plate number, driver, trips, and revenue.
