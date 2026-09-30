<?php

declare(strict_types=1);

namespace App\Domain\Recipes;

use App\Exceptions\BladDlaCzlowieka;
use App\Models\User;
use Illuminate\Cache\RateLimiter;

/**
 * Limit `post` dla kreatora przepisu (Livewire) — ten sam koszyk co
 * `throttle:post` na `POST /dodaj/przepis` (audyt 30.09.2026, S-01, #2268).
 *
 * DLACZEGO TO ISTNIEJE
 * Kreator zapisuje przez `POST livewire-…/update`, a na tej trasie nie ma
 * żadnego `throttle:` — Livewire ogranicza tylko wgrywanie plików. Skrypt
 * zalogowanego konta zakładał więc setki przepisów na minutę, choć ten sam
 * człowiek na zwykłym formularzu dostawał 429 przy 21. żądaniu. Limit
 * z formularza był pozorny.
 *
 * TEN SAM KOSZYK, NIE DRUGI
 * Klucz jest liczony dokładnie tak, jak liczy go `ThrottleRequests` dla
 * zalogowanego człowieka: prefiks trasy (`post`) i `sha1` identyfikatora
 * konta. Dzięki temu kreator i formularze treści (`posts.store`,
 * `recipes.store`, `recipes.update`…) dzielą jeden budżet z
 * `config/kuking.php` (`limits.post`) — osobny koszyk podwajałby limit.
 * Test `KreatorPrzepisuLimitZapisuTest` sprawdza, że formularz widzi
 * zapisy z kreatora.
 *
 * CO LICZYMY
 * Utworzenie NOWEGO przepisu (pierwszy zapis szkicu) i każdą publikację.
 * Autozapis istniejącego szkicu liczony NIE jest: człowiek pisze, pauzuje,
 * klika „Dalej” — to kilkanaście zapisów na jeden przepis i każdy by zjadał
 * budżet, a nie wytwarza nowej treści.
 *
 * Liczymy dopiero po udanym zapisie: odmowa z innego powodu (walidacja,
 * nieaktualna strona) nie zabiera miejsca w limicie.
 */
final class LimitZapisuKreatora
{
    /** Prefiks z `throttle:{limit},post` w `routes/web.php`. */
    public const PREFIKS = 'post';

    public function __construct(private readonly RateLimiter $limiter) {}

    /** Rzuca błąd dla człowieka, gdy koszyk `post` tego konta jest pełny. */
    public function sprawdz(User $osoba): void
    {
        [$maks] = $this->progi();
        $klucz = $this->klucz($osoba);

        if (! $this->limiter->tooManyAttempts($klucz, $maks)) {
            return;
        }

        $minuty = max(1, (int) ceil($this->limiter->availableIn($klucz) / 60));

        throw new BladDlaCzlowieka(
            "Zapisujesz bardzo dużo przepisów w krótkim czasie. Spróbuj ponownie za {$minuty} min.",
        );
    }

    /** Zapisuje jedno wytworzenie treści w koszyku `post`. */
    public function policz(User $osoba): void
    {
        [, $sekundy] = $this->progi();

        $this->limiter->hit($this->klucz($osoba), $sekundy);
    }

    /** Klucz identyczny z `ThrottleRequests::resolveRequestSignature()` dla konta. */
    private function klucz(User $osoba): string
    {
        return self::PREFIKS.sha1((string) $osoba->getAuthIdentifier());
    }

    /** @return array{0: int, 1: int} liczba zapisów i okno w sekundach */
    private function progi(): array
    {
        [$maks, $minuty] = array_map('intval', explode(',', (string) config('kuking.limits.post')));

        return [$maks, $minuty * 60];
    }
}
