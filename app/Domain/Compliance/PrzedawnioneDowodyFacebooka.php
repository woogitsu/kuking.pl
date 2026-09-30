<?php

declare(strict_types=1);

namespace App\Domain\Compliance;

use App\Models\FacebookConnectionProof;

/**
 * Sprzątanie wygasłych dowodów połączenia z Facebookiem (issue #2319).
 *
 * `facebook_connection_proofs` trzyma przez dziesięć minut jednorazowy dowód
 * „to naprawdę Ty prosisz o połączenie” (#2085): `user_id` i cztery skróty
 * HMAC — tokenu z listu, sesji, identyfikatora Facebooka i stanu konta.
 * Po terminie wiersz nie służy już niczemu: `FacebookConnectionConfirmation`
 * odrzuca go przy każdym odczycie (`expires_at` w przeszłości), a usuwa
 * wyłącznie przy udanym użyciu albo kolejnej prośbie tej samej osoby.
 * Porzucona prośba zostawała więc w bazie i w kopiach bez terminu —
 * a polityka prywatności mówi, że po połączeniu z Facebookiem zostaje
 * u nas tylko identyfikator konta i data połączenia.
 *
 * TO NIE JEST BRAMKA BEZPIECZEŃSTWA. Link przestaje działać co do minuty
 * dzięki warunkowi `expires_at` w `FacebookConnectionConfirmation`,
 * nie dzięki temu sprzątaniu. Dlatego wystarcza przebieg raz na dobę.
 *
 * `expires_at < now()`, nie arytmetyka na dzisiejszej dacie — termin jest
 * policzony raz, przy powstaniu dowodu (ten sam wybór co
 * `PrzedawnioneZmianyAdresu`). Jeden masowy `DELETE`: wiersz nie ma
 * odpowiednika w magazynie plików, a przerwany przebieg dobierze resztę
 * następnym razem. Bez indeksu na `expires_at`: tabela ma najwyżej jeden
 * wiersz na konto (`UNIQUE (user_id)`), a po każdym przebiegu tylko prośby
 * z ostatniej doby.
 */
final class PrzedawnioneDowodyFacebooka
{
    /**
     * @param  bool  $naSucho  policz, ale nie kasuj
     * @return int liczba skasowanych (albo policzonych) dowodów
     */
    public function posprzataj(bool $naSucho = false): int
    {
        $przedawnione = FacebookConnectionProof::query()->where('expires_at', '<', now());

        return $naSucho ? $przedawnione->count() : $przedawnione->delete();
    }
}
