<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Vite;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    /**
     * Chốt bảo vệ an toàn: Ngăn chặn tuyệt đối mọi ca kiểm thử chạy vào file database vật lý.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $default = config('database.default');
        $database = config("database.connections.{$default}.database");

        if ($default === 'sqlite' && $database !== ':memory:') {
            $this->fail("BẢO VỆ AN TOÀN: Database kiểm thử không được trỏ vào file '{$database}'. Phải là ':memory:'.");
        }
    }

    /**
     * Kiểm tra phản hồi HTML nạp đúng tài sản Vite theo chế độ thực tế (HMR dev server hoặc manifest production).
     */
    protected function assertRendersViteAssets(TestResponse $response): void
    {
        if (Vite::isRunningHot()) {
            $hotUrl = rtrim((string) file_get_contents(Vite::hotFile()), '/');
            $response->assertSee($hotUrl.'/@vite/client', false);
            $response->assertSee($hotUrl.'/resources/css/app.css', false);
            $response->assertSee($hotUrl.'/resources/js/app.js', false);

            return;
        }

        $response->assertSee('build/assets/app-', false);
    }

    /**
     * Chạy một callback trong chế độ mô phỏng production manifest mà không đụng tới public/hot thật.
     */
    protected function withoutViteHotFile(callable $callback): void
    {
        $originalHotFile = Vite::hotFile();
        $missingHotFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'bookstore-nonexistent-hot-'.uniqid('', true);

        Vite::useHotFile($missingHotFile);

        try {
            $callback();
        } finally {
            Vite::useHotFile($originalHotFile);
        }
    }
}
