<?php

namespace App\Services\Catalog;

use App\Data\Products\CreateProductData;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Unit;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ImportProductsCsv
{
    public const HEADERS = [
        'sku', 'name', 'description', 'category', 'unit_code', 'barcode',
        'purchase_price', 'selling_price', 'low_stock_threshold', 'initial_stock', 'is_active',
    ];

    private const REQUIRED_HEADERS = ['sku', 'name', 'unit_code', 'purchase_price', 'selling_price'];

    private const MAX_ROWS = 5000;

    private const MAX_ERRORS_TO_RETURN = 50;

    /**
     * Imports rows independently so one bad row does not roll back valid rows.
     * Existing SKUs update catalog fields but never change stock; initial_stock only
     * applies when a new product is created.
     *
     * @return array{created:int,updated:int,failed:int,processed:int,errors:list<array{row:int,message:string}>}
     */
    public function handle(
        UploadedFile $file,
        Organization $organization,
        Branch $branch,
        int $userId,
        CreateProduct $createProduct,
    ): array {
        $stream = fopen($file->getRealPath(), 'rb');

        if ($stream === false) {
            throw ValidationException::withMessages(['file' => 'تعذر فتح ملف CSV المرفوع.']);
        }

        try {
            $rawHeaders = fgetcsv($stream, null, ',', '"', '\\');
            if ($rawHeaders === false || $rawHeaders === [null]) {
                throw ValidationException::withMessages(['file' => 'الملف فارغ أو لا يحتوي على صف العناوين.']);
            }

            $headers = array_map(static function ($header): string {
                $value = trim((string) $header);
                $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;

                return strtolower($value);
            }, $rawHeaders);

            if (count($headers) !== count(array_unique($headers))) {
                throw ValidationException::withMessages(['file' => 'يوجد عنوان عمود مكرر في الملف.']);
            }

            $missing = array_values(array_diff(self::REQUIRED_HEADERS, $headers));
            if ($missing !== []) {
                throw ValidationException::withMessages([
                    'file' => 'الأعمدة المطلوبة غير موجودة: '.implode(', ', $missing).'. حمّل نموذج CSV واستخدم عناوينه كما هي.',
                ]);
            }

            $unknown = array_values(array_diff($headers, self::HEADERS));
            if ($unknown !== []) {
                throw ValidationException::withMessages([
                    'file' => 'الأعمدة غير المدعومة: '.implode(', ', $unknown).'. احذف الأعمدة الإضافية أو استخدم النموذج الرسمي.',
                ]);
            }

            $hasDescriptionColumn = in_array('description', $headers, true);
            $hasCategoryColumn = in_array('category', $headers, true);
            $hasThresholdColumn = in_array('low_stock_threshold', $headers, true);
            $hasActiveColumn = in_array('is_active', $headers, true);

            $created = 0;
            $updated = 0;
            $failed = 0;
            $processed = 0;
            $rowNumber = 1;
            $errors = [];
            $seenSkus = [];

            while (($values = fgetcsv($stream, null, ',', '"', '\\')) !== false) {
                $rowNumber++;

                if ($values === [null] || count(array_filter($values, static fn ($value): bool => trim((string) $value) !== '')) === 0) {
                    continue;
                }

                $processed++;
                if ($processed > self::MAX_ROWS) {
                    throw ValidationException::withMessages([
                        'file' => 'الملف يتجاوز الحد الأقصى البالغ 5000 صف بيانات. قسّم الملف إلى ملفات أصغر.',
                    ]);
                }

                if (count($values) > count($headers)) {
                    $extraValues = array_slice($values, count($headers));
                    if (count(array_filter($extraValues, static fn ($value): bool => trim((string) $value) !== '')) > 0) {
                        $this->recordError($errors, $failed, $rowNumber, 'عدد الأعمدة في الصف أكبر من عدد عناوين الملف.');
                        continue;
                    }
                }

                $values = array_pad(array_slice($values, 0, count($headers)), count($headers), null);
                $input = [];
                foreach ($headers as $index => $header) {
                    $value = trim((string) ($values[$index] ?? ''));
                    $input[$header] = $value === '' ? null : $value;
                }

                $input['sku'] = mb_strtoupper(trim((string) ($input['sku'] ?? '')));
                $input['is_active'] = $this->normalizeActive($input['is_active'] ?? null);
                $input['low_stock_threshold'] = $input['low_stock_threshold'] ?? '0';
                $input['initial_stock'] = $input['initial_stock'] ?? '0';

                $validator = Validator::make($input, [
                    'sku' => ['required', 'string', 'max:64', 'regex:/^[A-Z0-9._-]+$/'],
                    'name' => ['required', 'string', 'max:180'],
                    'description' => ['nullable', 'string', 'max:5000'],
                    'category' => ['nullable', 'string', 'max:120'],
                    'unit_code' => ['required', 'string', 'max:40'],
                    'barcode' => ['nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9._-]+$/'],
                    'purchase_price' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
                    'selling_price' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
                    'low_stock_threshold' => ['nullable', 'numeric', 'min:0', 'max:99999999999.999', 'decimal:0,3'],
                    'initial_stock' => ['nullable', 'numeric', 'min:0', 'max:99999999999.999', 'decimal:0,3'],
                    'is_active' => ['required', Rule::in(['1', '0'])],
                ], [
                    'required' => 'الحقل :attribute مطلوب.',
                    'regex' => 'تنسيق :attribute غير صالح.',
                    'decimal' => 'عدد الخانات العشرية في :attribute غير صالح.',
                    'numeric' => 'الحقل :attribute يجب أن يكون رقمًا.',
                    'min' => 'قيمة :attribute أقل من المسموح.',
                    'max' => 'قيمة :attribute أكبر من المسموح.',
                ], [
                    'sku' => 'كود المنتج', 'name' => 'اسم المنتج', 'unit_code' => 'كود الوحدة',
                    'barcode' => 'الباركود', 'purchase_price' => 'سعر الشراء', 'selling_price' => 'سعر البيع',
                    'low_stock_threshold' => 'حد التنبيه', 'initial_stock' => 'الرصيد الافتتاحي',
                ]);

                if ($validator->fails()) {
                    $this->recordError($errors, $failed, $rowNumber, implode('؛ ', $validator->errors()->all()));
                    continue;
                }

                $input = $validator->validated();
                $sku = (string) $input['sku'];

                if (isset($seenSkus[$sku])) {
                    $this->recordError($errors, $failed, $rowNumber, 'كود المنتج مكرر داخل ملف الاستيراد.');
                    continue;
                }
                $seenSkus[$sku] = true;

                $unit = Unit::query()->whereRaw('LOWER(code) = ?', [mb_strtolower((string) $input['unit_code'])])->first();
                if ($unit === null) {
                    $this->recordError($errors, $failed, $rowNumber, 'كود الوحدة غير موجود: '.$input['unit_code'].'.');
                    continue;
                }

                $categoryId = null;
                if (isset($input['category']) && $input['category'] !== '') {
                    $category = Category::query()
                        ->forOrganization((int) $organization->getKey())
                        ->whereRaw('LOWER(name) = ?', [mb_strtolower((string) $input['category'])])
                        ->first();

                    if ($category === null) {
                        $this->recordError($errors, $failed, $rowNumber, 'التصنيف غير موجود في المؤسسة الحالية: '.$input['category'].'. أضف التصنيف أولًا.');
                        continue;
                    }
                    $categoryId = (int) $category->getKey();
                }

                $existing = Product::query()
                    ->withTrashed()
                    ->forOrganization((int) $organization->getKey())
                    ->where('sku', $sku)
                    ->first();

                if ($existing?->trashed()) {
                    $this->recordError($errors, $failed, $rowNumber, 'هذا الكود يخص منتجًا مؤرشفًا؛ استخدم كودًا جديدًا أو أعد تفعيل المنتج من الإدارة.');
                    continue;
                }

                if ($existing !== null && (int) $existing->base_unit_id !== (int) $unit->getKey()) {
                    $this->recordError($errors, $failed, $rowNumber, 'لا يمكن تغيير وحدة القياس الأساسية لمنتج موجود عبر الاستيراد؛ عدّلها يدويًا بعد مراجعة حركات المخزون.');
                    continue;
                }

                $barcode = $input['barcode'] ?? null;
                if ($barcode !== null && $barcode !== '') {
                    $barcodeOwner = ProductBarcode::query()
                        ->where('organization_id', $organization->getKey())
                        ->where('barcode', $barcode)
                        ->first();

                    if ($barcodeOwner !== null && ($existing === null || (int) $barcodeOwner->product_id !== (int) $existing->getKey())) {
                        $this->recordError($errors, $failed, $rowNumber, 'الباركود مستخدم بالفعل في منتج آخر داخل المؤسسة.');
                        continue;
                    }
                }

                try {
                    if ($existing !== null) {
                        DB::transaction(function () use ($existing, $input, $categoryId, $barcode, $organization, $unit, $hasDescriptionColumn, $hasCategoryColumn, $hasThresholdColumn, $hasActiveColumn): void {
                            $existing->fill([
                                'category_id' => $hasCategoryColumn ? $categoryId : $existing->category_id,
                                'name' => (string) $input['name'],
                                'description' => $hasDescriptionColumn ? ($input['description'] ?? null) : $existing->description,
                                'purchase_price' => (string) $input['purchase_price'],
                                'selling_price' => (string) $input['selling_price'],
                                'low_stock_threshold' => $hasThresholdColumn ? (string) ($input['low_stock_threshold'] ?? '0') : $existing->low_stock_threshold,
                                'is_active' => $hasActiveColumn ? $input['is_active'] === '1' : $existing->is_active,
                            ]);
                            $existing->save();

                            if ($barcode !== null && $barcode !== '') {
                                $existsOnProduct = ProductBarcode::query()
                                    ->where('organization_id', $organization->getKey())
                                    ->where('product_id', $existing->getKey())
                                    ->where('barcode', $barcode)
                                    ->exists();

                                if (! $existsOnProduct) {
                                    ProductBarcode::query()->create([
                                        'organization_id' => $organization->getKey(),
                                        'product_id' => $existing->getKey(),
                                        'unit_id' => $unit->getKey(),
                                        'barcode' => $barcode,
                                    ]);
                                }
                            }
                        });
                        $updated++;
                    } else {
                        $data = CreateProductData::fromValidated([
                            'category_id' => $categoryId,
                            'base_unit_id' => (int) $unit->getKey(),
                            'sku' => $sku,
                            'name' => (string) $input['name'],
                            'description' => $input['description'] ?? null,
                            'purchase_price' => (string) $input['purchase_price'],
                            'selling_price' => (string) $input['selling_price'],
                            'low_stock_threshold' => (string) ($input['low_stock_threshold'] ?? '0'),
                            'initial_stock' => (string) ($input['initial_stock'] ?? '0'),
                            'barcode' => $barcode,
                        ], (int) $organization->getKey(), (int) $branch->getKey(), $userId);

                        $createProduct->handle($data);
                        $created++;
                    }
                } catch (QueryException $exception) {
                    if (! in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                        throw $exception;
                    }
                    $this->recordError($errors, $failed, $rowNumber, 'تعذر حفظ الصف بسبب تعارض في كود المنتج أو الباركود؛ راجع القيم المكررة.');
                }
            }

            if ($processed === 0) {
                throw ValidationException::withMessages(['file' => 'لا توجد صفوف بيانات في ملف CSV.']);
            }

            return compact('created', 'updated', 'failed', 'processed') + ['errors' => $errors];
        } finally {
            fclose($stream);
        }
    }

    private function normalizeActive(?string $value): string
    {
        $value = mb_strtolower(trim((string) $value));

        return match ($value) {
            '', '1', 'true', 'yes', 'active', 'نشط' => '1',
            '0', 'false', 'no', 'inactive', 'متوقف' => '0',
            default => $value,
        };
    }

    /** @param list<array{row:int,message:string}> $errors */
    private function recordError(array &$errors, int &$failed, int $rowNumber, string $message): void
    {
        $failed++;
        if (count($errors) < self::MAX_ERRORS_TO_RETURN) {
            $errors[] = ['row' => $rowNumber, 'message' => $message];
        }
    }
}
