<?php

namespace Tests\Feature;

use App\Http\Controllers\ProfessionalProfileController;
use App\Http\Middleware\RedirectReviewerDashboard;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class FinanceWorkflowCleanupTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        config(['association-registration.reviewer_ids' => []]);
    }

    public function test_dashboard_and_membership_routes_are_declared_once_and_keep_their_contract(): void
    {
        $source = file_get_contents(base_path('routes/web.php'));
        $this->assertSame(1, substr_count($source, "Route::get('/dashboard'"));
        $this->assertSame(1, substr_count($source, "require __DIR__.'/membership-applications.php';"));
        $dashboard = Route::getRoutes()->getByName('dashboard');
        $this->assertNotNull($dashboard);
        $this->assertSame('dashboard', $dashboard->uri());
        $this->assertSame(ProfessionalProfileController::class.'@dashboard', $dashboard->getActionName());
        foreach (['auth', 'verified', RedirectReviewerDashboard::class] as $middleware) {
            $this->assertContains($middleware, $dashboard->gatherMiddleware());
        }
        $routes = collect(Route::getRoutes()->getRoutes());
        $this->assertCount(1, $routes->filter(fn ($route) => $route->uri() === 'dashboard' && in_array('GET', $route->methods(), true)));
        $this->assertNotNull(Route::getRoutes()->getByName('membership-applications.index'));
        $this->assertNotNull(Route::getRoutes()->getByName('membership-applications.store'));
    }

    public function test_reviewer_is_redirected_but_can_explicitly_open_member_dashboard(): void
    {
        $reviewer = User::factory()->create();
        config(['association-registration.reviewer_ids' => [(string) $reviewer->id]]);
        $this->actingAs($reviewer)->get('/dashboard')->assertRedirect(route('filmunity-admin.dashboard'));
        $this->get('/dashboard?view=member')->assertOk();
    }

    public function test_ordinary_member_can_open_dashboard_and_membership_applications(): void
    {
        $this->actingAs(User::factory()->create())->get('/dashboard')->assertOk();
        $this->get(route('membership-applications.index'))->assertOk();
        $this->get('/dashboard?panel=applications')->assertRedirect(route('membership-applications.index'));
    }

    public function test_guest_and_unverified_dashboard_guards_are_retained(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->actingAs(User::factory()->unverified()->create())->get('/dashboard')->assertRedirect(route('verification.notice'));
    }
}
