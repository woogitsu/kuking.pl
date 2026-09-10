<?php

declare(strict_types=1);

namespace App\Domain\Compliance;

use App\Models\RegistrationInvite;

/**
 * Sprzątanie wygasłych zaproszeń do założenia konta (D-085).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  TO NIE JEST BRAMKA BEZPIECZEŃSTWA, TYLKO HIGIENA DANYCH
 * ────────────────────────────────────────────────────────────────────────
 *
 * Zaproszenie przestaje działać co do minuty dzięki
 * `RegistrationInvite::jestWazne()`, a nie dzięki temu `DELETE`. Gdyby
 * bezpieczeństwo stało na sprzątaniu, jeden nieudany przebieg harmonogramu
 * przedłużałby ważność cudzego linku — a tak nie przedłuża niczego.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO MIMO TO MUSI ISTNIEĆ
 * ────────────────────────────────────────────────────────────────────────
 *
 * Bo w tym wierszu leży adres e-mail osoby, która NIE MA u nas konta i nigdy
 * nie musi mieć — a jedynym powodem, dla którego go trzymamy, jest doprowadzenie
 * jej z wiadomości na formularz. Po wygaśnięciu ten powód znika i zostaje sam
 * cudzy adres (docs/SECURITY_PRIVACY_LEGAL.md — minimalizacja danych).
 *
 * `WyslijZaproszenieDoRejestracji` sprząta przy okazji każdej prośby, więc
 * w dniu z ruchem tabela czyści się sama. Ta komenda jest siatką bezpieczeństwa
 * NA DNI, W KTÓRYCH NIKT O NIC NIE PROSI — i to ona, a nie tamto sprzątanie
 * przy okazji, daje polityce prywatności prawo napisać „najwyżej dobę".
 *
 * Zwykły masowy `DELETE`, nie pętla po wierszach — ten sam powód co
 * w `PrzedawnioneZmianyAdresu`: wiersz nie ma odpowiednika po stronie storage,
 * jedno zapytanie jest atomowe, a przerwany przebieg dobierze resztę następnym
 * razem.
 */
final class PrzedawnioneZaproszenia
{
    /**
     * @param  bool  $naSucho  policz, ale nie kasuj
     * @return int liczba skasowanych (albo policzonych) zaproszeń
     */
    public function posprzataj(bool $naSucho = false): int
    {
        $przedawnione = RegistrationInvite::query()->where('expires_at', '<', now());

        return $naSucho ? $przedawnione->count() : $przedawnione->delete();
    }
}
