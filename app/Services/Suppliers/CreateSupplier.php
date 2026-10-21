<?php

namespace App\Services\Suppliers;

use App\Data\Suppliers\CreateSupplierData;
use App\Models\Supplier;

class CreateSupplier
{
    public function handle(CreateSupplierData $data): Supplier
    {
        return Supplier::query()->create([
            'organization_id' => $data->organizationId,
            'name' => $data->name,
            'code' => $data->code,
            'phone' => $data->phone,
            'email' => $data->email,
            'address' => $data->address,
            'notes' => $data->notes,
            'is_active' => true,
        ]);
    }
}
