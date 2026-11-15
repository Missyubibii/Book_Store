<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use DateTimeInterface;
use InvalidArgumentException;

class Formatters
{
    /**
     * Múi giờ hiển thị chính thức của hệ thống Book_Store.
     */
    public const DISPLAY_TIMEZONE = 'Asia/Ho_Chi_Minh';

    /**
     * Định dạng số tiền Việt Nam Đồng (VND) theo chuẩn hiển thị '150.000 ₫'.
     *
     * Chú ý: Đầu vào phải là số nguyên (integer) hoặc chuỗi số nguyên để tránh sai số số thực (floating-point).
     *
     * @param int|string $amount Số tiền dạng số nguyên VND
     * @return string Chuỗi tiền tệ đã định dạng kèm ký hiệu '₫'
     * @throws InvalidArgumentException khi số tiền không phải dạng số nguyên hợp lệ
     */
    public static function formatVnd(int|string $amount): string
    {
        if (is_string($amount)) {
            $trimmed = trim($amount);
            if (! preg_match('/^-?\d+$/', $trimmed)) {
                throw new InvalidArgumentException("Số tiền VND phải là số nguyên hợp lệ, nhận được: {$amount}");
            }
            $amount = (int) $trimmed;
        }

        // Định dạng phân tách hàng nghìn bằng dấu chấm, không có số thập phân
        $formatted = number_format($amount, 0, ',', '.');

        return "{$formatted} ₫";
    }

    /**
     * Định dạng ngày tháng tiếng Việt: dd/mm/yyyy theo múi giờ Asia/Ho_Chi_Minh.
     *
     * Đối với chuỗi chỉ chứa ngày (dạng Y-m-d, ví dụ: '1990-05-15'), giữ nguyên ngày theo lịch
     * mà không chuyển đổi múi giờ để tránh lệch ngày.
     * Đối với đối tượng thời gian hoặc chuỗi có thông tin giờ giấc (UTC timestamp), chuyển đổi
     * chính xác sang múi giờ Asia/Ho_Chi_Minh trước khi xuất định dạng.
     *
     * @param DateTimeInterface|string|null $date
     * @return string
     */
    public static function formatDate(DateTimeInterface|string|null $date): string
    {
        if ($date === null) {
            return '';
        }

        // Nếu là chuỗi dạng date-only 'YYYY-MM-DD', giữ nguyên ngày lịch không đổi timezone
        if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($date))) {
            return Carbon::createFromFormat('Y-m-d', trim($date))->format('d/m/Y');
        }

        return self::toDisplayCarbon($date)->format('d/m/Y');
    }

    /**
     * Định dạng ngày giờ tiếng Việt: dd/mm/yyyy HH:mm theo múi giờ Asia/Ho_Chi_Minh.
     *
     * @param DateTimeInterface|string|null $dateTime
     * @return string
     */
    public static function formatDateTime(DateTimeInterface|string|null $dateTime): string
    {
        if ($dateTime === null) {
            return '';
        }

        return self::toDisplayCarbon($dateTime)->format('d/m/Y H:i');
    }

    /**
     * Chuyển đổi đối tượng thời gian sang múi giờ hiển thị Asia/Ho_Chi_Minh.
     *
     * @param DateTimeInterface|string $time
     * @return CarbonInterface
     */
    private static function toDisplayCarbon(DateTimeInterface|string $time): CarbonInterface
    {
        if ($time instanceof CarbonInterface) {
            return $time->copy()->setTimezone(self::DISPLAY_TIMEZONE);
        }

        if ($time instanceof DateTimeInterface) {
            return Carbon::instance($time)->setTimezone(self::DISPLAY_TIMEZONE);
        }

        return Carbon::parse($time)->setTimezone(self::DISPLAY_TIMEZONE);
    }
}

