<?php

namespace App\Http\Controllers\Audit;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogController extends Controller
{
    /** @var array<string,string> */
    private const ENTITY_OPTIONS = [
        'Product' => 'المنتج',
        'Category' => 'التصنيف',
        'ProductBarcode' => 'باركود منتج',
        'ProductUnitConversion' => 'وحدة منتج',
        'Customer' => 'العميل',
        'CustomerAccountTransaction' => 'حركة حساب عميل',
        'Supplier' => 'المورد',
        'SupplierPayment' => 'دفعة مورد',
        'Promotion' => 'عرض أو خصم',
        'InventoryBalance' => 'رصيد مخزون',
        'InventoryMovement' => 'حركة مخزون',
        'InventoryLot' => 'تشغيلة صلاحية',
        'StockTransfer' => 'تحويل مخزون',
        'InventoryStockCount' => 'جرد مخزون',
        'Sale' => 'فاتورة بيع',
        'SaleItem' => 'بند بيع',
        'SaleReturn' => 'مرتجع بيع',
        'SaleReturnItem' => 'بند مرتجع بيع',
        'PurchaseInvoice' => 'فاتورة شراء',
        'PurchaseInvoiceItem' => 'بند شراء',
        'PurchaseOrder' => 'أمر شراء',
        'PurchaseOrderItem' => 'بند أمر شراء',
        'PurchaseReturn' => 'مرتجع شراء',
        'PurchaseReturnItem' => 'بند مرتجع شراء',
        'CashShift' => 'وردية خزينة',
        'CashMovement' => 'حركة خزينة',
        'Expense' => 'مصروف',
        'team_membership' => 'عضوية فريق العمل',
    ];

    public function index(Request $request): Response
    {
        $organization = $this->authorizedOrganization($request);
        [$filters, $query] = $this->filteredQuery($request, (int) $organization->getKey());

        $matchingRecords = (clone $query)->count();
        $logs = (clone $query)
            ->with(['actor:id,name', 'branch:id,name'])
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (AuditLog $log): array => $this->toRow($log));

        $base = AuditLog::query()->where('organization_id', $organization->getKey());

        return Inertia::render('Audit/Index', [
            'organization' => $organization->only(['id', 'name', 'currency']),
            'currentBranch' => $request->attributes->get('currentBranch')?->only(['id', 'name']),
            'logs' => $logs,
            'filters' => $filters,
            'branches' => Branch::query()->where('organization_id', $organization->getKey())
                ->orderBy('name')->get(['id', 'name']),
            'entityOptions' => collect(self::ENTITY_OPTIONS)->map(fn (string $label, string $value): array => [
                'value' => $value, 'label' => $label,
            ])->values(),
            'summary' => [
                'matchingRecords' => $matchingRecords,
                'today' => (clone $base)->where('created_at', '>=', now()->startOfDay())->count(),
                'deletionsToday' => (clone $base)->where('event', 'deleted')->where('created_at', '>=', now()->startOfDay())->count(),
                'failedActionsToday' => (clone $base)->where('event', 'action_failed')->where('created_at', '>=', now()->startOfDay())->count(),
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $organization = $this->authorizedOrganization($request);
        [, $query] = $this->filteredQuery($request, (int) $organization->getKey());
        $filename = 'metrik-audit-log-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($query): void {
            echo "\xEF\xBB\xBF";
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['ID', 'Date', 'Actor', 'Branch', 'Event', 'Entity', 'Entity ID', 'Summary', 'Changed Fields', 'Old Values', 'New Values', 'IP Address', 'Request ID']);

            foreach ((clone $query)->with(['actor:id,name', 'branch:id,name'])->orderByDesc('id')->limit(10000)->cursor() as $log) {
                $row = [
                    $log->getKey(),
                    $log->created_at?->format('Y-m-d H:i:s'),
                    $log->actor?->name,
                    $log->branch?->name,
                    $log->event,
                    self::ENTITY_OPTIONS[$this->entityKey($log->auditable_type)] ?? $this->entityKey($log->auditable_type),
                    $log->auditable_id,
                    $log->summary,
                    implode(', ', $log->changed_fields ?? []),
                    json_encode($log->old_values ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    json_encode($log->new_values ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $log->ip_address,
                    $log->request_id,
                ];
                fputcsv($stream, array_map(fn ($value) => $this->csvSafe($value), $row));
            }

            fclose($stream);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function authorizedOrganization(Request $request): Organization
    {
        $organization = $request->attributes->get('currentOrganization');
        abort_unless($organization instanceof Organization, 403);
        abort_unless($request->user()?->can('viewAuditLogs', $organization), 403);

        return $organization;
    }

    /** @return array{array<string,mixed>,Builder<AuditLog>} */
    private function filteredQuery(Request $request, int $organizationId): array
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'event' => ['nullable', 'in:created,updated,deleted,action,action_failed'],
            'entity' => ['nullable', 'string', 'in:'.implode(',', array_keys(self::ENTITY_OPTIONS))],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $from = $validated['from'] ?? '';
        $to = $validated['to'] ?? '';
        if ($from !== '' && $to !== '' && $to < $from) {
            throw ValidationException::withMessages(['to' => 'تاريخ النهاية يجب ألا يسبق تاريخ البداية.']);
        }

        $filters = [
            'q' => trim((string) ($validated['q'] ?? '')),
            'event' => $validated['event'] ?? '',
            'entity' => $validated['entity'] ?? '',
            'branch_id' => $validated['branch_id'] ?? '',
            'from' => $from,
            'to' => $to,
        ];

        $query = AuditLog::query()->where('organization_id', $organizationId)
            ->when($filters['event'] !== '', fn (Builder $builder) => $builder->where('event', $filters['event']))
            ->when($filters['entity'] !== '', function (Builder $builder) use ($filters): void {
                $type = $filters['entity'] === 'team_membership' ? 'team_membership' : 'App\\Models\\'.$filters['entity'];
                $builder->where('auditable_type', $type);
            })
            ->when($filters['branch_id'] !== '', fn (Builder $builder) => $builder->where('branch_id', (int) $filters['branch_id']))
            ->when($from !== '', fn (Builder $builder) => $builder->whereDate('created_at', '>=', $from))
            ->when($to !== '', fn (Builder $builder) => $builder->whereDate('created_at', '<=', $to))
            ->when($filters['q'] !== '', function (Builder $builder) use ($filters): void {
                $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $filters['q']).'%';
                $builder->where(function (Builder $nested) use ($term): void {
                    $nested->where('summary', 'like', $term)
                        ->orWhere('auditable_type', 'like', $term)
                        ->orWhereHas('actor', fn (Builder $actor) => $actor->where('name', 'like', $term));
                });
            });

        return [$filters, $query];
    }

    /** @return array<string,mixed> */
    private function toRow(AuditLog $log): array
    {
        return [
            'id' => (int) $log->getKey(),
            'date' => $log->created_at?->format('Y-m-d H:i:s'),
            'actorName' => $log->actor?->name ?? 'مستخدم محذوف',
            'branchName' => $log->branch?->name ?? 'على مستوى المؤسسة',
            'event' => $log->event,
            'entityKey' => $this->entityKey($log->auditable_type),
            'entityLabel' => self::ENTITY_OPTIONS[$this->entityKey($log->auditable_type)] ?? $this->entityKey($log->auditable_type),
            'entityId' => $log->auditable_id,
            'summary' => $log->summary,
            'changedFields' => $log->changed_fields ?? [],
            'oldValues' => $log->old_values ?? [],
            'newValues' => $log->new_values ?? [],
            'ipAddress' => $log->ip_address,
            'requestId' => $log->request_id,
        ];
    }

    private function entityKey(string $type): string
    {
        return $type === 'team_membership' ? $type : class_basename($type);
    }

    private function csvSafe(mixed $value): string
    {
        $cell = (string) ($value ?? '');
        if (! is_numeric($cell) && preg_match('/^[=+\-@\t\r]/u', $cell) === 1) {
            return "'".$cell;
        }

        return $cell;
    }
}
