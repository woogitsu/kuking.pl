<?php

declare(strict_types=1);

use App\Support\Facebook;
use App\Support\Google;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

if (PHP_SAPI !== 'cli-server' || ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit;
}
require __DIR__.'/oauth-bootstrap.php';
$state = json_decode(file_get_contents(getenv('OAUTH345_STATE')), true, flags: JSON_THROW_ON_ERROR);
config([
    'kuking.google.identyfikator_klienta' => 'oauth345-google',
    'kuking.google.sekret_klienta' => 'wylacznie-atrapa345',
    'kuking.facebook.identyfikator_klienta' => 'oauth345-facebook',
    'kuking.facebook.sekret_klienta' => 'wylacznie-atrapa345',
    'kuking.google.wlaczone' => true,
    'kuking.facebook.wlaczone' => true,
    'session.driver' => 'file',
    'session.files' => getenv('OAUTH345_SESSIONS'),
    'cache.default' => 'file',
    'cache.stores.file.path' => getenv('OAUTH345_SESSIONS').'/cache',
]);
$code = (string) ($_GET['code'] ?? '');
$data = json_decode(base64_decode($code, true) ?: '{}', true) ?: [];
$scenario = $data['scenario'] ?? '';
$allowed = in_array($scenario, ['domknij', 'polacz', 'bez-adresu'], true);
Http::fake([
    Google::ADRES_TOKENU => function ($request) use ($state, $data, $scenario, $allowed, $code) {
        if (! $allowed || $request['code'] !== $code || strlen($request['code_verifier'] ?? '') < 43) {
            throw new RuntimeException('OAUTH345: nieprawidłowa wymiana Google.');
        }
        $payload = ['iss' => 'https://accounts.google.com', 'aud' => 'oauth345-google', 'exp' => time() + 300,
            'nonce' => $data['nonce'] ?? '', 'sub' => 'oauth345-'.$state['suffix'], 'email_verified' => true,
            'email' => $scenario === 'polacz' ? $state['email'] : 'nowa-'.$state['email'],
            'given_name' => 'Małgorzata Konstantynopolitańczykowianka'];

        return Http::response(['id_token' => 'test.'.rtrim(strtr(base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=').'.test']);
    },
    Facebook::adresTokenu().'*' => function ($request) use ($allowed, $code) {
        if (! $allowed || $request['code'] !== $code) {
            throw new RuntimeException('OAUTH345: nieprawidłowa wymiana Facebooka.');
        }

        return Http::response(['access_token' => 'atrapa345']);
    },
    Facebook::adresTozsamosci().'*' => fn () => Http::response(['id' => 'oauth345-'.$state['suffix'],
        'name' => 'Małgorzata Konstantynopolitańczykowianka',
        'email' => $scenario === 'bez-adresu' ? null : 'nowa-'.$state['email']]),
]);
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = realpath(public_path().$uri);
if ($file !== false && str_starts_with($file, public_path().DIRECTORY_SEPARATOR) && is_file($file) && ! str_ends_with($file, '.php')) {
    return false;
}
$request = Request::capture();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
