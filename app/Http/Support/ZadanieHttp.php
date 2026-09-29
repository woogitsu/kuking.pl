<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Support\ZadanieDomenowe;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;

/** Adapter HTTP: `Request` jako `ZadanieDomenowe` (#970). Zero logiki, samo tłumaczenie. */
final readonly class ZadanieHttp implements ZadanieDomenowe
{
    public function __construct(private Request $request) {}

    public static function z(Request $request): self
    {
        return new self($request);
    }

    public function sesja(): Session
    {
        return $this->request->session();
    }

    public function ip(): ?string
    {
        return $this->request->ip();
    }

    public function pole(string $nazwa, mixed $domyslnie = null): mixed
    {
        return $this->request->input($nazwa, $domyslnie);
    }

    public function wszystkie(): array
    {
        return $this->request->all();
    }

    public function scal(array $pola): void
    {
        $this->request->merge($pola);
    }
}
