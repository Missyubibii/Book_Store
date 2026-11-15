<?php

declare(strict_types=1);

use App\Support\Formatters;

if (! function_exists('format_vnd')) {
    /**
     * Định dạng số tiền VND (ví dụ: '150.000 ₫').
     */
    function format_vnd(int|string $amount): string
    {
        return Formatters::formatVnd($amount);
    }
}

if (! function_exists('format_date')) {
    /**
     * Định dạng ngày tháng tiếng Việt (dd/mm/yyyy).
     */
    function format_date(\DateTimeInterface|string|null $date): string
    {
        return Formatters::formatDate($date);
    }
}

if (! function_exists('format_datetime')) {
    /**
     * Định dạng ngày giờ tiếng Việt (dd/mm/yyyy HH:mm).
     */
    function format_datetime(\DateTimeInterface|string|null $dateTime): string
    {
        return Formatters::formatDateTime($dateTime);
    }
}

