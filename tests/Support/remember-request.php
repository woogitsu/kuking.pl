<?php

declare(strict_types=1);
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\HtmlString;

// Osobny proces na żądanie: nie współdzieli guarda ani pamięci sesji z klientem.
// Sekrety przechodzą wyłącznie przez stdin/stdout procesu, nigdy przez log.
require dirname(__DIR__).'/bootstrap.php';

$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
config($input['config']);
$app['env'] = 'production'; // PreventRequestForgery musi naprawdę sprawdzać CSRF.
$app->instance(Vite::class, new class extends Vite
{
    public function __invoke($entrypoints, $buildDirectory = null)
    {
        return new HtmlString('');
    }
});
// Atrapa wyłącznie zewnętrznej odpowiedzi HIBP, nie reguły hasła.
Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
$request = Request::create('http://localhost'.$input['path'], $input['method'], $input['data'], $input['cookies'], [], ['HTTP_ACCEPT' => 'text/html', 'HTTP_REFERER' => 'http://localhost/ustawienia/bezpieczenstwo']);
$response = $kernel->handle($request);
$cookies = [];
foreach ($response->headers->getCookies() as $cookie) {
    $cookies[$cookie->getName()] = $cookie->getExpiresTime() !== 0 && $cookie->getExpiresTime() <= time()
        ? null : $cookie->getValue();
}
$kernel->terminate($request, $response);
echo json_encode(['status' => $response->getStatusCode(), 'location' => $response->headers->get('Location'), 'body' => $response->getContent(), 'cookies' => $cookies], JSON_THROW_ON_ERROR);
