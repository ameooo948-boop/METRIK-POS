<?php

namespace App\Enums;

enum InventoryMovementType: string
{
    case OPENING = 'opening';
    case STOCK_IN = 'stock_in';
    case STOCK_OUT = 'stock_out';
    case SALE = 'sale';
    case SALE_RETURN = 'sale_return';
    case PURCHASE = 'purchase';
    case PURCHASE_RETURN = 'purchase_return';
    case TRANSFER_IN = 'transfer_in';
    case TRANSFER_OUT = 'transfer_out';
    case ADJUSTMENT = 'adjustment';
}
