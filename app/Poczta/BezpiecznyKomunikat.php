<?php

declare(strict_types=1);

namespace App\Poczta;

/**
 * Redakcja tekstu, który przyszedł od CUDZEJ strony, przed zapisaniem go
 * gdziekolwiek: jedna linia, bez adresów e-mail, przycięta.
 *
 * PO CO OSOBNA KLASA, A NIE DWIE PRYWATNE METODY W DWÓCH MIEJSCACH
 * Bo od 10 września 2026 tę samą redakcję robią dwa kawałki kodu:
 * `TransportEmailLabs` (tytuł i detal błędu od dostawcy → komunikat wyjątku)
 * oraz `ZapiszNieudanyList` (komunikat DOWOLNEGO transportu → wiersz
 * `mail_failures`). Dwie kopie tej samej reguły rozjechałyby się przy
 * pierwszej poprawce, a rozjazd dwóch kopii jednej reguły jest w tym
 * repozytorium usterką, nie niedogodnością (ta sama lekcja co przy
 * `DziennyBudzetListow`).
 *
 * DLACZEGO TO NIE JEST OSTROŻNOŚĆ NA ZAPAS
 * Komunikat wyjątku od cudzej biblioteki niesie więcej, niż autor kodu tam
 * włożył (audyt A6-01, `App\Logging\WebhookBleduHandler`). `QueryException`
 * wkłada w komunikat SQL razem z wartościami, a transport SMTP — odpowiedź
 * serwera, w której przy odrzuconym odbiorcy stoi JEGO ADRES:
 *
 *     Expected response code 250 but got code "550", with message
 *     "550 5.1.1 <basia@wp.pl>: Recipient address rejected"
 *
 * Ten tekst idzie u nas do bazy i do dziennika, więc adres nie ma prawa
 * przejść (AGENTS.md §7). Wycinamy więc wszystko o kształcie adresu —
 * bez próby zgadywania, gdzie w zdaniu stoi.
 */
final class BezpiecznyKomunikat
{
    /** Ile znaków przepuszczamy domyślnie. Tyle wystarcza, żeby rozpoznać awarię. */
    public const DOMYSLNA_DLUGOSC = 300;

    /** Wstawiane w miejsce czegokolwiek, co wygląda na adres e-mail. */
    public const ZAMIAST_ADRESU = '[adres]';

    public static function z(mixed $tekst, int $maksimum = self::DOMYSLNA_DLUGOSC): ?string
    {
        if (! is_string($tekst) || trim($tekst) === '') {
            return null;
        }

        $tekst = (string) preg_replace('/\s+/', ' ', trim($tekst));
        $tekst = (string) preg_replace('/[^\s<>()@,;]+@[^\s<>()@,;]+/', self::ZAMIAST_ADRESU, $tekst);

        return mb_substr($tekst, 0, max(1, $maksimum));
    }
}
