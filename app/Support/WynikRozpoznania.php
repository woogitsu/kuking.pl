<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Wynik `RozpoznanieZdjecia::rozpoznaj()`, gdy plik NIE jest w porządku.
 *
 * DWA KSZTAŁTY JEDNEJ ODPOWIEDZI, JEDNO SPRAWDZENIE
 * `komunikat` idzie do człowieka (błąd formularza albo treść wyjątku),
 * `powod` jest kodem maszynowym do sygnału `photo_upload_failed`
 * (issue #115: `unreadable|too_large|not_an_image|unsupported_format|
 * too_many_megapixels` — te trzy ostatnie stąd). Rozdzielenie ich na dwa
 * pola zamiast wyciągania powodu z treści komunikatu jest celowe: parsowanie
 * polskiego zdania, żeby odgadnąć, co się stało, pęka przy pierwszej zmianie
 * słownictwa, o której nikt nie pomyśli, że jest kontraktem.
 *
 * `kontekst` niesie WYŁĄCZNIE liczby, nigdy tekst od użytkownika (np.
 * `megapixels` przy przekroczonym limicie) — do właściwości sygnału.
 */
final readonly class WynikRozpoznania
{
    /**
     * @param  array<string, int|float>  $kontekst
     */
    public function __construct(
        public string $powod,
        public string $komunikat,
        public array $kontekst = [],
    ) {}
}
