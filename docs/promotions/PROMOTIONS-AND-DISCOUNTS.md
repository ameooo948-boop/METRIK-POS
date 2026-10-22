# Promotions, coupons, and cashier discounts

## Supported promotion types

- **Buy X, get Y free**: applies to one product and one selected unit. The eligible cycles are calculated from the quantity entered in that unit.
- **Product percentage**: percentage off selected product lines; an optional coupon code can make the offer code-specific.
- **Bundle percentage**: percentage off the selected product group only when every selected product is present in the basket.
- **Manual discount**: an additional percentage discount on each line's already-promoted net value, bounded by the cashier's organization role.

## Price calculation and safeguards

The server is authoritative. Prices and discounts are calculated in integer cents; quantities use thousandths of a unit. The POS submits its displayed expected total so the server can reject the checkout if prices or active promotions have changed since the page was loaded. The sale, sale items, payments, stock, and cashier movement are still written by the existing checkout transaction.

At most one automatic/coupon promotion can apply to a given line; offers do not stack on the same line. A bundle promotion requires all of its products to be in the basket. Coupon text is normalized to uppercase, and a supplied but inapplicable coupon is rejected rather than silently ignored. The applied promotion name is saved on each sale item as a historical snapshot.

Role-based manual discount ceilings in this phase are: owner 80%, organization admin 30%, branch manager 15%; other roles cannot grant a manual discount. Review these business limits before production, as they are policy defaults and may need customization per merchant.

## Branch scope

Promotions may be organization-wide or limited to a specific branch. Branch managers create and toggle promotions for their active branch only. Organization-level roles may create a global promotion or target an active branch.

## Offline sales

Discounts and coupons are intentionally disabled for offline checkout in this phase. Offline sales use the catalog price snapshot already charged to the customer; syncing them does not retroactively apply promotions or manual discounts. Do not promise offline promotion support until pricing snapshots and offer validity are designed and tested end-to-end.

## Apply and verify

Run the three new migrations with `php artisan migrate`, then run `php artisan test --filter=PromotionPricingTest`, `php artisan test --filter=PointOfSaleTest`, `php artisan test --filter=SaleReturnTest`, and `npm run build`. Validate the four supported cases with test data before enabling promotions for real sales.
