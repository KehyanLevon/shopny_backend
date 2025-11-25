<?php

namespace App\Enum;

enum ProductStatus: string
{
    case ACTIVE = 'active';
    case DRAFT = 'draft';
    case OUT_OF_STOCK = 'out_of_stock';
}
