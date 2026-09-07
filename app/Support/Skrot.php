<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Nieodwracalny skrót wartości, której NIE CHCEMY trzymać w bazie w postaci
 * jawnej — dziś adresu IP i adresu e-mail (klucze limitera, `audit_log.ip_hash`).
 *
 * PO CO OSOBNA KLASA NA JEDNĄ LINIĘ
 * Bo ta jedna linia była już w repozytorium napisana DWA RAZY, w dwóch
 * różnych wersjach, i tylko jedna z nich była poprawna.
 * `App\Support\KluczeLimitow` używało `hash_hmac` i miało przy tym komentarz
 * tłumaczący, dlaczego HMAC, a nie `hash()` z doklejoną solą (audyt kluczy
 * limitera). `App\Models\AuditLogEntry` w tym samym czasie robiło
 * `hash('sha256', $ip.config('app.key'))` — czyli dokładnie tę konstrukcję,
 * którą tamten komentarz odrzucał. Reguła istniała w jednej warstwie i nie
 * było jej w drugiej; to ta sama klasa błędu co filtr widoczności obecny na
 * liście wykonań, ale nieobecny w liczniku nad nią.
 *
 * DLACZEGO HMAC, A NIE `hash()` Z DOKLEJONYM SEKRETEM
 * `hash('sha256', $wartosc.$sekret)` jest podatny na rozszerzanie wiadomości,
 * a `hash_hmac` nie. Przy skrócie adresu IP nie jest to atak o dużych
 * konsekwencjach, ale nie ma powodu wybierać słabszej konstrukcji, skoro obie
 * są jednolinijkowe.
 *
 * CZEGO TEN SKRÓT NIE OBIECUJE — I DLACZEGO TO WAŻNE DLA POLITYKI PRYWATNOŚCI
 * Nie obiecuje, że wartości nie da się odtworzyć. Przestrzeń adresów IPv4 to
 * około czterech miliardów możliwości — kto ma `APP_KEY`, przeliczy je
 * wszystkie w kilka minut i dopasuje skrót do adresu. Ochrona polega na tym,
 * że `APP_KEY` żyje jako zmienna środowiskowa, a NIE w bazie: sam zrzut
 * tabeli nie wystarcza. To jest znacznie słabsze zapewnienie niż
 * „nie da się odczytać, z jakiego adresu ktoś korzystał", i polityka
 * prywatności nie ma prawa napisać tego drugiego.
 *
 * WARTOŚCI ZAPISANE PRZED TĄ ZMIANĄ zostały policzone starą konstrukcją, więc
 * nie da się ich porównać z nowymi. Nic w kodzie nigdy nie porównywało
 * `audit_log.ip_hash` z niczym (jest tylko zapisywany), a tabela nie ma
 * jeszcze ani jednego wiersza z produkcji, więc nie ma czego migrować.
 */
final class Skrot
{
    public static function hmac(string $wartosc): string
    {
        return hash_hmac('sha256', $wartosc, (string) config('app.key'));
    }
}
