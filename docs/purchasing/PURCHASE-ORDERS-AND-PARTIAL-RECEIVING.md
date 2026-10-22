# Purchase Orders and Partial Receiving

## Workflow

1. Create an order for the active branch and a supplier in the current organization. Creating an order does not change on-hand stock or the supplier ledger.
2. Record only the quantities physically received. Each receipt creates a normal purchase invoice through the existing `CreatePurchaseInvoice` service, so it also records inventory movements, updates inventory balances, updates the product's base-unit purchase cost, and posts supplier transactions.
3. An order remains `partially_received` until every line reaches its ordered quantity. It becomes `received` only when all remaining quantities are received.
4. An order can be cancelled only before the first receipt. Cancelled or fully received orders cannot be received again.

## Unit and price snapshots

Orders snapshot each product's selected purchase unit name, conversion factor, ordered quantity, and unit cost. A receipt is rejected if the selected unit was disabled or its conversion factor changed after the order was created; review the order/unit first rather than silently changing the received base quantity. Partial receipts keep the ordered unit and unit cost and create separate invoices.

## Data isolation and safety

All order listing, creation, receipt, and cancellation operations are scoped to the current organization and active branch. Supplier and product ownership is checked server-side. Receipt line IDs must belong to the order being received; quantities greater than the outstanding amount are rejected within a database transaction. The stock/ledger updates and the order progress update commit together.

## Apply and verify

Run `php artisan migrate`, then `php artisan test --filter=PurchaseOrderTest`, `php artisan test --filter=PurchaseInvoiceTest`, `php artisan test --filter=ProductUnitConversionTest`, and `npm run build`. Finally run the full `php artisan test` suite and `php artisan metrik:health-check --strict`. Do not use `migrate:fresh` against a database with data.

Expiration lot allocation remains a separate follow-up: this phase uses the existing purchase invoice/inventory movement path but does not auto-create or allocate expiry lots for incoming receipts.
