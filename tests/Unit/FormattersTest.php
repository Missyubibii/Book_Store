<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Formatters;
use Carbon\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

class FormattersTest extends TestCase
{
    public function test_formats_vnd_currency_correctly(): void
    {
        $this->assertSame('150.000 ₫', Formatters::formatVnd(150000));
        $this->assertSame('0 ₫', Formatters::formatVnd(0));
        $this->assertSame('1.000.000 ₫', Formatters::formatVnd(1000000));
        $this->assertSame('25.500 ₫', Formatters::formatVnd('25500'));
        $this->assertSame('-50.000 ₫', Formatters::formatVnd(-50000));
    }

    public function test_throws_exception_on_invalid_vnd_string(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Formatters::formatVnd('150.50');
    }

    public function test_formats_vietnamese_date_in_ho_chi_minh_timezone(): void
    {
        // 2026-11-06 20:00:00 UTC = 2026-11-07 03:00:00 Asia/Ho_Chi_Minh (+7) (vượt qua nửa đêm)
        $utcTime = Carbon::parse('2026-11-06 20:00:00', 'UTC');

        $this->assertSame('07/11/2026', Formatters::formatDate($utcTime));
        $this->assertSame('07/11/2026 03:00', Formatters::formatDateTime($utcTime));
    }

    public function test_preserves_date_only_calendar_values_without_timezone_shift(): void
    {
        // Chuỗi ngày thuần túy Y-m-d phải giữ nguyên ngày lịch, không bị chuyển đổi giờ
        $this->assertSame('15/05/1990', Formatters::formatDate('1990-05-15'));
        $this->assertSame('01/01/2026', Formatters::formatDate('2026-01-01'));
        $this->assertSame('31/12/2025', Formatters::formatDate('2025-12-31'));
    }

    public function test_returns_empty_string_for_null_dates(): void
    {
        $this->assertSame('', Formatters::formatDate(null));
        $this->assertSame('', Formatters::formatDateTime(null));
    }

    public function test_global_helper_functions(): void
    {
        $this->assertSame('500.000 ₫', \format_vnd(500000));

        $utcTime = Carbon::parse('2026-05-15 05:30:00', 'UTC');
        $this->assertSame('15/05/2026', \format_date($utcTime));
        $this->assertSame('15/05/2026 12:30', \format_datetime($utcTime));
    }
}
