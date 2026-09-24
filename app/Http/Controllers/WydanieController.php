<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Wersja;
use Illuminate\Http\JsonResponse;

/**
 * /wydanie — maszynowo czytelny, PEŁNY SHA commita, który obsługuje ten adres
 * (issue #1012).
 *
 * PO CO, SKORO SKRÓT STOI W STOPCE
 * Test dymny po wdrożeniu (`.github/workflows/deploy.yml`) zapisuje wynik przy
 * commicie ze zdarzenia `deployment_status`, ale odpytuje STAŁY adres. Przy
 * dwóch szybkich wdrożeniach test zdarzenia A potrafi ruszyć dopiero po
 * przełączeniu ruchu na B — i zielony wynik ląduje przy A, choć sprawdzono B.
 * Ten punkt pozwala sondzie porównać, co naprawdę działa, z tym, co miało
 * zostać wdrożone. Stopka się do tego nie nadaje: ma siedem znaków, zmienne
 * copy i siedzi w HTML-u, który może przyjść z cache.
 *
 * BEZ BAZY I BEZ LOGIKI. W przeciwieństwie do `/health` niczego nie sprawdza,
 * więc sonda może go odpytywać co kilka sekund bez kosztu.
 *
 * CO UJAWNIA: wyłącznie SHA, którego skrót i tak jest w stopce każdej strony.
 * Lokalnie i w testach bez `RAILWAY_GIT_COMMIT_SHA` pole ma wartość `null`.
 *
 * ZAKAZ CACHE. Odpowiedź sprzed wdrożenia podana z cache to dokładnie ta
 * fałszywa zieleń, której ten punkt ma zapobiec. `no-store` stoi tu jawnie:
 * trasa jest POZA grupą `web` (bez sesji), więc nie liczy na to, że
 * `PreventSharedSessionCache` zareaguje na ciasteczko sesji. Sonda dodatkowo
 * dokleja zmienny parametr zapytania, więc nie trafia w klucz cache brzegu.
 *
 * BEZ SESJI I BEZ CSRF — trasa stoi w `bootstrap/app.php` (`then:`), poza
 * grupą `web`, nie w routes/web.php. Sonda pyta co kilka sekund; w grupie
 * `web` każde pytanie zakładało nową sesję i odsyłało `Set-Cookie`.
 * Pilnuje tego WydanieWystawiaPelnyShaTest.
 */
final class WydanieController
{
    public function __invoke(): JsonResponse
    {
        return response()
            ->json(['commit' => Wersja::commit()])
            ->header('Cache-Control', 'no-store, max-age=0');
    }
}
