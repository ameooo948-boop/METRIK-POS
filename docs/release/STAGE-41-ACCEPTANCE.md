# Stage 41 acceptance checklist

- [ ] Migrate the new lot-allocation and lot-movement tables on a copy of the current database.
- [ ] Verify FEFO sale writes sale-item-to-lot allocations and decrements lot balances exactly once.
- [ ] Verify partial and full returns restore only originally allocated quantities when `restock=true`.
- [ ] Verify `restock=false` records the allocation as returned without increasing lot stock.
- [ ] Verify repeated return UUID does not change lot balances or movement counts twice.
- [ ] Verify transfers preserve batch code, manufactured date and expiry date between branches.
- [ ] Verify stock-out adjustments, negative stock-count variances and purchase returns reduce tracked lots and leave no negative lots.
- [ ] Verify timezone-based expiry counts at organization-local midnight.
- [ ] Verify foreign-organization lots cannot appear in the current branch view or be mutated.
- [ ] Run the full Laravel suite and Vite production build.
- [ ] Make and verify a backup before production migration.
