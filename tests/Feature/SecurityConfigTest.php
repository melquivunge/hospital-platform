<?php

namespace Tests\Feature;

use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class SecurityConfigTest extends TestCase
{
    public function test_production_refuses_to_boot_with_debug_enabled(): void
    {
        $this->app['env'] = 'production';
        config(['app.debug' => true, 'session.secure' => true]);

        $this->expectException(RuntimeException::class);
        (new AppServiceProvider($this->app))->boot();
    }

    public function test_production_refuses_to_boot_with_insecure_session_cookie(): void
    {
        $this->app['env'] = 'production';
        config(['app.debug' => false, 'session.secure' => false]);

        $this->expectException(RuntimeException::class);
        (new AppServiceProvider($this->app))->boot();
    }

    public function test_safe_production_config_boots_and_forces_https(): void
    {
        $this->app['env'] = 'production';
        config(['app.debug' => false, 'session.secure' => true, 'services.icp.signer' => null]);

        (new AppServiceProvider($this->app))->boot();

        $this->assertStringStartsWith('https://', url('/login'));
    }

    public function test_only_development_and_tests_accept_the_test_certificate_signer(): void
    {
        $this->app['env'] = 'staging';
        config(['services.icp.signer' => ' Test ']);

        $this->expectExceptionMessage('ICP_SIGNER=test só é aceito em desenvolvimento e testes.');
        (new AppServiceProvider($this->app))->boot();
    }

    public function test_csp_allows_the_inline_theme_script_by_hash(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();
        $this->assertSame(1, preg_match_all('#<script>(.*?)</script>#s', $html, $matches));

        $hash = 'sha256-'.base64_encode(hash('sha256', $matches[1][0], true));
        $nginx = file_get_contents(base_path('docker/nginx/default.conf'));

        $this->assertStringContainsString("'{$hash}'", $nginx);
    }

    public function test_each_throttled_route_has_its_own_counter(): void
    {
        foreach (['a', 'b'] as $name) {
            Route::middleware(['web', 'throttle:2,1'])->get("/_throttle/{$name}", fn () => 'ok')->name("throttle-test.{$name}");
        }
        $this->actingAs(User::factory()->create());
        $this->get('/_throttle/a')->assertOk();
        $this->get('/_throttle/a')->assertOk();
        $this->get('/_throttle/a')->assertTooManyRequests();
        // Before step 4b.6 the person's counter was shared: the other route was refused too.
        $this->get('/_throttle/b')->assertOk();
    }
}
