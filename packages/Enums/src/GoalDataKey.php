<?php

declare(strict_types=1);

namespace ConvertSdk\Enums;

enum GoalDataKey: string
{
    case Amount = 'amount';
    case ProductsCount = 'productsCount';
    case TransactionId = 'transactionId';
    case CustomDimension1 = 'customDimension1';
    case CustomDimension2 = 'customDimension2';
    case CustomDimension3 = 'customDimension3';
    case CustomDimension4 = 'customDimension4';
    case CustomDimension5 = 'customDimension5';
}
