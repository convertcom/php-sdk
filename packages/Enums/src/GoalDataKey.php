<?php

declare(strict_types=1);

namespace ConvertSdk\Enums;

enum GoalDataKey: string
{
    case Amount = 'amount';
    case ProductsCount = 'productsCount';
    case TransactionId = 'transactionId';
}
