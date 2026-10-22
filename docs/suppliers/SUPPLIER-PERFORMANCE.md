# Supplier Performance Report

The report summarizes received purchase invoices for the active organization and branch: invoice count, purchase total, returned amount, paid amount, and due amount. It supports supplier search and received-date filters. CSV export uses the same filters and organization/branch scope.

## Apply
Extract this patch into the Laravel project root, preserving relative paths. No migration or package installation is required.

## Verify
```powershell
php artisan optimize:clear
php artisan route:list --path=suppliers/performance
php artisan test --filter=SupplierPerformanceTest
npm run build
php artisan test
```

Amounts reflect values recorded on received purchase invoices. The report does not create payments or modify balances. Review it against a known test invoice before relying on it for accounting decisions.
