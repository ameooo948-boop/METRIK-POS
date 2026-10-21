<?php

namespace App\Enums;

enum OrganizationRole: string
{
    case OWNER = 'owner';
    case ADMIN = 'admin';
    case BRANCH_MANAGER = 'branch_manager';
    case CASHIER = 'cashier';
    case INVENTORY_MANAGER = 'inventory_manager';
    case ACCOUNTANT = 'accountant';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::OWNER => 'مالك المؤسسة',
            self::ADMIN => 'مدير المؤسسة',
            self::BRANCH_MANAGER => 'مدير فرع',
            self::CASHIER => 'كاشير',
            self::INVENTORY_MANAGER => 'مسؤول المخزون',
            self::ACCOUNTANT => 'محاسب',
        };
    }

    public function canManageBranches(): bool
    {
        return in_array($this, [self::OWNER, self::ADMIN], true);
    }

    public function canManageCatalog(): bool
    {
        return in_array($this, [self::OWNER, self::ADMIN, self::INVENTORY_MANAGER], true);
    }

    public function canManageInventory(): bool
    {
        return in_array($this, [self::OWNER, self::ADMIN, self::BRANCH_MANAGER, self::INVENTORY_MANAGER], true);
    }

    public function canViewPurchases(): bool
    {
        return in_array($this, [
            self::OWNER,
            self::ADMIN,
            self::BRANCH_MANAGER,
            self::INVENTORY_MANAGER,
            self::ACCOUNTANT,
        ], true);
    }

    public function canManagePurchases(): bool
    {
        return $this->canViewPurchases();
    }


    public function canUsePos(): bool
    {
        return in_array($this, [self::OWNER, self::ADMIN, self::BRANCH_MANAGER, self::CASHIER], true);
    }

    public function canViewSales(): bool
    {
        return in_array($this, [self::OWNER, self::ADMIN, self::BRANCH_MANAGER, self::CASHIER, self::ACCOUNTANT], true);
    }

    public function canViewReports(): bool
    {
        return in_array($this, [self::OWNER, self::ADMIN, self::BRANCH_MANAGER, self::ACCOUNTANT], true);
    }

    public function canManageExpenses(): bool
    {
        return in_array($this, [self::OWNER, self::ADMIN, self::BRANCH_MANAGER, self::ACCOUNTANT], true);
    }
}
