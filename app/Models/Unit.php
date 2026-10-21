<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Unit extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name_ar', 'name_en', 'is_system'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    public function productsUsingAsBaseUnit(): HasMany
    {
        return $this->hasMany(Product::class, 'base_unit_id');
    }

    public function productConversions(): HasMany
    {
        return $this->hasMany(ProductUnitConversion::class);
    }
}
