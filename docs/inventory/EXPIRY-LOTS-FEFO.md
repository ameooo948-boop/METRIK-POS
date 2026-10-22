# Expiry lots and FEFO

This phase adds branch-scoped inventory lots with a batch code, optional manufacture date, expiry date, receipt date, received quantity, and remaining quantity.

## Behavior

- Only users authorized by the existing `manageInventory` policy can view or register lots.
- A lot is recorded against stock already present in the active branch. Registering it does not change `inventory_balances` or create an inventory movement.
- The sum of remaining lot quantities cannot exceed the branch's current balance when a new lot is registered.
- POS checkout locks the product's lots and consumes non-expired lots in expiry-date order (FEFO: first expiry, first out).
- Expired tracked quantities are excluded from available sale quantity. Existing stock not allocated to any lot remains untracked and can still be sold.
- The page shows expired lots, lots expiring in the next 30 days, and remaining tracked units.
- Lot records are scoped to the organization and selected branch.

## Operational limitations

- Existing stock is not automatically classified by expiry. Staff must count the real packages and register the lots accurately.
- Purchase receipt and customer-return flows do not yet create or restore lots automatically. Those units become untracked until a user registers them.
- This phase does not add a disposal/expiry write-off workflow. Do not merely delete expired stock records; use the existing inventory adjustment process and reconcile the lot quantity carefully.
- FEFO must be validated with real POS transactions and the full regression suite before production deployment.
