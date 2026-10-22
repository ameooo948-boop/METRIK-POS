# Catalog lifecycle — Stage 40

## Products

- Product names, SKUs, descriptions, categories, purchase prices, selling prices, and default low-stock threshold can be edited.
- The base unit and stock balance are intentionally not editable in the general edit form. Unit conversions and inventory adjustments remain separate audited workflows.
- Archiving changes `is_active` instead of deleting the row. This preserves stock movements, receipts, invoices, return history, and IDs referenced by offline operations.
- Reactivation is supported. New POS and purchasing flows should continue selecting active products only.

## Categories

- Categories can be renamed and archived/reactivated.
- `is_active` defaults to true for existing categories through a new migration.
- Archiving does not delete category rows or remove category references from historical products. New product creation and CSV import accept active categories only.

## Suppliers

- Supplier contact details can be edited, and suppliers can be deactivated/reactivated.
- Deactivation prevents use in new purchase invoices and purchase orders; existing statements, payments, returns, and invoice history remain accessible.
- Supplier updates are scoped to the current organization and require the purchasing-management permission.

## Safety rules

- Every mutation scopes the target record to the current organization.
- Archive/deactivate actions are reversible and do not hard-delete accounting history.
- Product price changes affect future sales only; recorded sale/purchase snapshots are not recalculated.
