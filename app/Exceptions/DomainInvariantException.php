<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Ngoại lệ vi phạm bất biến nghiệp vụ hoặc bất biến phân quyền bất khả xâm phạm.
 *
 * Được ném bởi `DomainGuardService` ngay cả khi tác nhân là Super Administrator;
 * không thể bị bỏ qua thông qua `Gate::before`.
 */
class DomainInvariantException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $invariantCode,
        public readonly int $statusCode = 422,
    ) {
        parent::__construct($message, $statusCode);
    }
}
