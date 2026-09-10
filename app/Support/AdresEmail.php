<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Adres e-mail pokazany w SKRÓCIE: `j***@wp.pl` (issue #195).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  PO CO TO ISTNIEJE
 * ────────────────────────────────────────────────────────────────────────
 *
 * List ostrzegawczy „ktoś zażądał zmiany adresu Twojego konta" idzie na
 * STARY adres i jest jedynym ostrzeżeniem, jakie dostanie osoba, której
 * konto ktoś właśnie przejmuje. Musi więc powiedzieć, DOKĄD ta zmiana
 * prowadzi — inaczej właściciel nie odróżni własnej, zapomnianej prośby od
 * cudzej.
 *
 * Kłopot w tym, że nowy adres w całości wpisuje ŻĄDAJĄCY. Wstawiony do
 * listu bez zmian zamienia naszą wiadomość w tablicę ogłoszeń: część przed
 * `@` może mieć do 64 znaków i (w cudzysłowie) prawie dowolną treść, więc
 * „zadzwon-pilnie-pod-500600700@example.com" trafiłby do skrzynki ofiary
 * w liście z naszym nadawcą i naszym szablonem. To jest gotowy phishing
 * wysłany naszymi rękami — a grupa 50+ jest na dokładnie ten wzorzec
 * najbardziej podatna (`config/kuking.php`, `reserved_usernames`, opisuje
 * ten sam mechanizm od strony nazw kont).
 *
 * Skrót rozstrzyga to w jedną stronę: zostaje pierwsza litera i pełna
 * domena — czyli dokładnie tyle, ile potrzeba, żeby odpowiedzieć na jedyne
 * pytanie, które ofiara ma zadać („czy to moja skrzynka?"). Domenę
 * zostawiamy w całości, bo to ona niesie rozpoznanie: „wp.pl" kontra
 * „mail.ru" mówi więcej niż cała reszta adresu.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DRUGIE MIEJSCE UŻYCIA: LISTA KONT W PANELU MODERACJI
 * ────────────────────────────────────────────────────────────────────────
 *
 * `/admin/uzytkownicy` pokazuje adresy w tej samej masce — i z pokrewnego,
 * choć nie identycznego powodu. Tam nie chodzi o phishing, tylko o to, że
 * lista pokazuje DWADZIEŚCIA PIĘĆ adresów naraz, a moderator odpowiada przy
 * niej wyłącznie na pytanie „które to konto"; pierwsza litera i pełna domena
 * na to wystarczają. Pełny adres jest na KARCIE jednego konta — a wejście na
 * kartę zostawia wpis w `audit_log`, więc pełnia jest tam opłacona śladem
 * w dzienniku (docs/INSPIRATION_DECISIONS.md poz. 3.2).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  GDZIE SKRÓTU NIE UŻYWAMY — I DLACZEGO TO NIE JEST NIEKONSEKWENCJA
 * ────────────────────────────────────────────────────────────────────────
 *
 * Na ekranie `/ustawienia/e-mail` oczekujący adres pokazujemy W CAŁOŚCI.
 * Tam patrzy zalogowany właściciel na adres, który sam przed chwilą wpisał
 * — a pełny zapis jest tam JEDYNYM sposobem, żeby zobaczyć własną literówkę
 * („janek@wp.pl" kontra „jankek@wp.pl"). Skrót w tym miejscu ukrywałby
 * dokładnie tę informację, dla której ten ekran powstał.
 */
final class AdresEmail
{
    public static function maska(string $adres): string
    {
        $pozycja = mb_strrpos($adres, '@');

        if ($pozycja === false || $pozycja === 0) {
            return '***';
        }

        $domena = mb_substr($adres, $pozycja + 1);

        if ($domena === '') {
            return '***';
        }

        return mb_substr($adres, 0, 1).'***@'.$domena;
    }
}
