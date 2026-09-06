<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Jedna odpowiedź na pytanie „czy to jest zdjęcie, które umiemy otworzyć".
 *
 * PO CO OSOBNA KLASA
 * Ta sama odpowiedź jest potrzebna w DWÓCH momentach i dwa razy z innego
 * powodu:
 *
 *   * przy WALIDACJI FORMULARZA — żeby człowiek dostał zrozumiały komunikat
 *     przy polu, razem z resztą błędów, a wpisany tekst nie zniknął;
 *   * w `StoreUploadedImage` — bo to jest prawdziwa granica i musi trzymać
 *     także wtedy, gdy ktoś ominie formularz.
 *
 * Zanim to powstało, pierwszy moment używał laravelowej reguły `image`, a drugi
 * własnego sprawdzenia po magic bytes. `image` to w Laravelu
 * `mimes:jpg,jpeg,png,gif,bmp,svg,webp` — czyli lista, która NIE ZAWIERA AVIF,
 * choć `config/kuking.php` go dopuszcza, a pole wyboru pliku go podpowiada.
 * Zdjęcie AVIF odpadało więc w formularzu, zanim potok mediów zdążył
 * powiedzieć, że umie je przetworzyć — a komunikat mówił „JPG, PNG lub WebP",
 * czyli listę wziętą z trzeciego miejsca (audyt W3-08).
 *
 * Jedna lista, jedno sprawdzenie, jedne komunikaty.
 */
final class RozpoznanieZdjecia
{
    /**
     * `null`, gdy plik jest w porządku. Komunikat po polsku, gdy nie jest.
     *
     * Kolejność sprawdzeń jak w potoku mediów: od najtańszego do najdroższego.
     */
    public static function coJestNieTak(string $sciezka): ?string
    {
        // `getimagesize()` czyta sam nagłówek, więc jest tanie i przy okazji
        // odpowiada na pytanie „czy to na pewno obraz", niezależnie od tego,
        // co mówi rozszerzenie i nagłówek Content-Type od klienta.
        $info = @getimagesize($sciezka);

        if ($info === false) {
            return self::komunikatNieczytelnegoPliku($sciezka);
        }

        $wykryty = $info['mime'] ?? null;

        if (! in_array($wykryty, LimityZdjec::dozwoloneTypy(), true)) {
            return 'Nie obsługujemy tego formatu zdjęć. Wybierz plik '
                .LimityZdjec::formatyDlaCzlowieka().'.';
        }

        [$szerokosc, $wysokosc] = $info;

        // Obrona przed „decompression bomb": plik może mieć 2 MB, a obraz
        // 30 000 × 30 000 px i położyć workera przy dekodowaniu.
        $megapiksele = ($szerokosc * $wysokosc) / 1_000_000;

        if ($megapiksele > (int) config('kuking.media.max_megapixels')) {
            return 'To zdjęcie ma za duże wymiary. Zmniejsz je i spróbuj ponownie.';
        }

        return null;
    }

    /**
     * Co powiedzieć, gdy PHP nie umie odczytać pliku jako obrazu.
     *
     * HEIC jest domyślnym formatem aparatu w iPhonie od 2017 roku, więc to nie
     * jest przypadek brzegowy — to najzwyklejszy sposób, w jaki nasza grupa
     * robi zdjęcia. Zwykle iOS konwertuje takie zdjęcie do JPEG przy wysyłce
     * sam, ale nie zawsze: przy udostępnianiu z Plików albo z aplikacji innej
     * niż aparat plik idzie w oryginale.
     *
     * `getimagesize()` HEIC-a nie zna (PHP 8.4 nie ma nawet stałej
     * `IMAGETYPE_HEIC`), ale `mime_content_type()` owszem — to inna biblioteka.
     * Da się więc powiedzieć, co jest naprawdę nie tak, zamiast twierdzić
     * „ten plik nie wygląda na zdjęcie" komuś, kto trzyma w ręku fotografię.
     */
    private static function komunikatNieczytelnegoPliku(string $sciezka): string
    {
        $wykryty = @mime_content_type($sciezka);

        if (in_array($wykryty, ['image/heic', 'image/heif'], true)) {
            return 'To zdjęcie jest w formacie HEIC, którego jeszcze nie umiemy otworzyć. '
                .'W iPhonie wejdź w Ustawienia → Aparat → Formaty i wybierz „Najbardziej zgodny” — '
                .'kolejne zdjęcia zapiszą się jako JPG. To zdjęcie możesz wysłać sobie e-mailem '
                .'albo otworzyć w Zdjęciach i użyć „Duplikuj”, żeby dostać wersję JPG.';
        }

        return 'Ten plik nie wygląda na zdjęcie. Wybierz plik '
            .LimityZdjec::formatyDlaCzlowieka().'.';
    }
}
