<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\AccountStatus;
use App\Enums\LoginEventType;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AccountStatusMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_user_passes_through_middleware(): void
    {
        $user = User::create([
            'name' => 'Bùi Văn G',
            'email' => 'buivang@gmail.com',
            'password' => bcrypt('MatKhau@123'),
        ]);
        $user->refresh();

        Auth::login($user);

        $request = Request::create('/dashboard', 'GET');
        $request->setUserResolver(fn () => $user);

        $middleware = new EnsureAccountIsActive;
        $response = $middleware->handle($request, function ($req) {
            return response('OK', 200);
        });

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('OK', $response->getContent());
        $this->assertAuthenticatedAs($user);
    }

    public function test_suspended_user_session_is_revoked_by_middleware(): void
    {
        $user = User::create([
            'name' => 'Đỗ Thị H',
            'email' => 'dothih@gmail.com',
            'password' => bcrypt('MatKhau@123'),
        ]);

        Auth::login($user);
        $this->assertAuthenticatedAs($user);

        // Giả lập tài khoản bị admin khóa trong khi phiên làm việc đang mở
        $user->account_status = AccountStatus::Suspended;
        $user->save();

        $request = Request::create('/dashboard', 'GET');
        $request->setUserResolver(fn () => $user);
        $request->setLaravelSession(session()->driver());

        $middleware = new EnsureAccountIsActive;
        $response = $middleware->handle($request, function ($req) {
            return response('OK', 200);
        });

        // Phải bị đăng xuất ngay lập tức
        $this->assertGuest();
        $this->assertTrue($response->isRedirection());

        // Kiểm tra nhật ký session_revoked
        $this->assertDatabaseHas('login_events', [
            'user_id' => $user->id,
            'email' => 'dothih@gmail.com',
            'event_type' => LoginEventType::SessionRevoked->value,
        ]);
    }
}
