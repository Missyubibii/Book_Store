<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Enums\AccountStatus;
use App\Enums\StaffAccessStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PublicHomepageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_access_public_homepage_without_login_redirect(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertViewIs('welcome');
        $response->assertSeeText('Book_Store');
        $response->assertSeeText('Hiệu sách Book_Store — Không gian Sách in & Ấn phẩm Số');
        $response->assertSee('href="'.route('login').'"', false);
        $response->assertSee('href="'.route('register').'"', false);
        $response->assertDontSee('href="#"', false);
    }

    public function test_authenticated_customer_can_access_public_homepage_with_account_navigation(): void
    {
        $customer = User::factory()->create([
            'name' => 'Nguyễn Hồng Nhung',
            'email' => 'nhungnguyen@example.com',
            'account_status' => AccountStatus::Active,
            'staff_access_status' => StaffAccessStatus::NotApplicable,
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($customer)->get('/');

        $response->assertStatus(200);
        $response->assertSeeText('Nguyễn Hồng Nhung');
        $response->assertSeeText('nhungnguyen@example.com');
        $response->assertSeeText('Email đã xác thực');
        $response->assertSeeText('Đăng xuất tài khoản');
        $response->assertSee('action="'.route('logout').'"', false);
        $response->assertDontSeeText('Mở tài khoản độc giả');
    }

    public function test_staff_users_can_access_public_storefront_as_customers(): void
    {
        $pendingStaff = User::factory()->create([
            'name' => 'Nhân Sự Chờ Duyệt',
            'email' => 'pending.staff@example.com',
            'account_status' => AccountStatus::Active,
            'staff_access_status' => StaffAccessStatus::Pending,
            'must_change_password' => true,
        ]);

        $activeStaff = User::factory()->create([
            'name' => 'Nhân Sự Chi Nhánh',
            'email' => 'active.staff@example.com',
            'account_status' => AccountStatus::Active,
            'staff_access_status' => StaffAccessStatus::Active,
            'must_change_password' => false,
            'email_verified_at' => now(),
        ]);

        foreach ([$pendingStaff, $activeStaff] as $staff) {
            $response = $this->actingAs($staff)->get('/');

            $response->assertStatus(200);
            $response->assertSeeText($staff->name);
            $response->assertSeeText('Hiệu sách Book_Store — Không gian Sách in & Ấn phẩm Số');
            $response->assertDontSeeText('Bảng điều khiển nội bộ');
        }
    }

    public function test_public_homepage_renders_storefront_sections_in_vietnamese_without_fabricated_catalog_data(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);

        // Thương hiệu & điều hướng hiệu sách công khai
        $response->assertSeeText('Book_Store');
        $response->assertSeeText('Khám phá');
        $response->assertSeeText('Danh mục sách');
        $response->assertSeeText('Tìm kiếm');
        $response->assertSeeText('Giỏ hàng');

        // Điểm vào tìm kiếm, khám phá, danh mục và chân trang
        $response->assertSee('name="tu-khoa"', false);
        $response->assertSeeText('Tìm sách');
        $response->assertSeeText('Khám phá ấn phẩm tại Book_Store');
        $response->assertSeeText('Kệ sách trưng bày hiện đang trống');
        $response->assertSeeText('Danh mục sách theo định dạng');
        $response->assertSeeText('Sách in');
        $response->assertSeeText('eBook bản quyền');

        // Đảm bảo tài sản Vite sạch và không chứa CSS/JS nội tuyến hay văn bản tiếng Anh giả lập
        $this->assertRendersViteAssets($response);
        $response->assertDontSee('<style>', false);
        $response->assertDontSee('<script>', false);
        $response->assertDontSee('href="#"', false);
        $response->assertDontSeeText('Add to cart');
        $response->assertDontSeeText('Shop now');
        $response->assertDontSeeText('₫');

        $this->withoutViteHotFile(function (): void {
            $manifestResponse = $this->get('/');
            $manifestResponse->assertOk();
            $manifestResponse->assertSee('build/assets/app-', false);
        });
    }

    public function test_search_query_submission_shows_honest_empty_state_without_404(): void
    {
        $response = $this->get('/?tu-khoa=Van+hoc');

        $response->assertStatus(200);
        $response->assertSee('value="Van hoc"', false);
        $response->assertSeeText('Chưa có kết quả cho từ khóa "Van hoc" vì danh mục ấn phẩm đang được chuẩn bị và chưa mở bán đầu sách.', false);
    }

    public function test_dedicated_login_and_register_routes_remain_accessible_and_return_to_storefront(): void
    {
        $this->get('/dang-nhap')->assertStatus(200)->assertViewIs('auth.login');
        $this->get('/dang-ky')->assertStatus(200)->assertViewIs('auth.register');

        $user = User::create([
            'name' => 'Trần Thanh Bình',
            'email' => 'binhtran@example.com',
            'password' => Hash::make('MatKhauAnToan@123'),
        ]);
        $user->account_status = AccountStatus::Active;
        $user->staff_access_status = StaffAccessStatus::NotApplicable;
        $user->save();

        $loginResponse = $this->post('/dang-nhap', [
            'email' => 'binhtran@example.com',
            'password' => 'MatKhauAnToan@123',
        ]);

        $this->assertAuthenticatedAs($user);
        $loginResponse->assertRedirect('/');
    }

    public function test_protected_auth_routes_remain_protected_and_unimplemented_product_routes_are_not_exposed(): void
    {
        // Tuyến yêu cầu đăng nhập vẫn bảo vệ nghiêm ngặt
        $this->get('/xac-thuc-email')->assertRedirect('/dang-nhap');
        $this->post('/dang-xuat')->assertRedirect('/dang-nhap');

        // Không lộ route sản phẩm/giỏ hàng/thanh toán chưa triển khai
        foreach (['/sach', '/san-pham', '/gio-hang', '/thanh-toan', '/tim-kiem'] as $route) {
            $this->get($route)->assertNotFound();
        }
    }
}
