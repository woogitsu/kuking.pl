<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\ApplySecurityHeaders;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class DyktowanieNaglowkiDluzszychPolTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function longerTextRoutes(): array
    {
        $routes = [
            'recipes.create', 'recipes.create.simple', 'recipes.create.from-post',
            'recipes.details', 'recipes.edit', 'posts.create', 'posts.edit',
            'questions.create', 'cooked.create', 'cooked.celebrate', 'recipes.show',
            'posts.show', 'questions.show', 'cooked.show', 'collections.index',
            'collections.edit', 'collections.show', 'settings.profile', 'kontakt',
            'appeals.show', 'appeals.reporter', 'appeals.guest', 'reports.create',
            'zglos.nielegalna', 'recipes.history.hide', 'recipes.history.restore',
            'admin.reports', 'admin.appeals', 'admin.sygnaly', 'admin.z-urzedu.create',
            'admin.csam.create', 'admin.contact.show', 'admin.unanswered',
        ];

        return array_combine($routes, array_map(fn (string $route): array => [$route], $routes));
    }

    #[DataProvider('longerTextRoutes')]
    public function test_ekran_dluzszego_pola_odblokowuje_mikrofon_tylko_zalogowanemu(string $route): void
    {
        $this->assertStringContainsString('microphone=(self)', $this->policy($route, true), 'DICTATION_AUTH_HEADER');
        $this->assertStringContainsString('microphone=()', $this->policy($route, false), 'DICTATION_GUEST_HEADER');
    }

    public function test_nazwa_trasy_nie_odblokowuje_mikrofonu_na_bledzie_przekierowaniu_json_ani_post(): void
    {
        foreach ([302, 403, 404, 419, 429, 500] as $status) {
            $this->assertStringContainsString('microphone=()', $this->policy('posts.create', true, $status), 'DICTATION_NON_FORM_RESPONSE');
        }

        $this->assertStringContainsString('microphone=()', $this->policy('posts.create', true, 200, 'application/json'), 'DICTATION_NON_FORM_RESPONSE');
        $this->assertStringContainsString('microphone=()', $this->policy('posts.create', true, 200, 'text/html', 'POST'), 'DICTATION_NON_FORM_RESPONSE');
    }

    public function test_zalogowanie_nie_odblokowuje_mikrofonu_na_innych_ekranach(): void
    {
        foreach (['home', 'landing', 'cooking.show', 'profile.show', 'settings.security', 'wydanie'] as $route) {
            $this->assertStringContainsString('microphone=()', $this->policy($route, true), 'DICTATION_OTHER_ROUTE');
        }
    }

    private function policy(string $name, bool $authenticated, int $status = 200, string $contentType = 'text/html', string $method = 'GET'): string
    {
        $request = Request::create('/dictation-header-fixture', $method);
        $route = (new Route([$method], 'dictation-header-fixture', fn () => ''))->name($name);
        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => $authenticated ? new User : null);
        $response = (new ApplySecurityHeaders)->handle($request, fn () => new Response('', $status, ['Content-Type' => $contentType]));

        return (string) $response->headers->get('Permissions-Policy');
    }
}
