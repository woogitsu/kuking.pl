<?php

declare(strict_types=1);

namespace App\Rules;

use App\Domain\Analytics\ZapiszSygnal;
use App\Support\LimityZdjec;
use App\Support\RozpoznanieZdjecia;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Reguła walidacji zastępująca laravelowe `image` (audyt W3-08).
 *
 * DLACZEGO NIE `image`
 * `image` to w Laravelu `mimes:jpg,jpeg,png,gif,bmp,svg,webp`. Ta lista NIE
 * ZAWIERA AVIF, który `config/kuking.php` dopuszcza i który pole wyboru pliku
 * podpowiada — więc zdjęcie AVIF odpadało w formularzu, zanim potok mediów
 * zdążył powiedzieć, że umie je przetworzyć. Za to `image` PRZEPUSZCZA GIF,
 * BMP i SVG, których potok nie obsługuje. Czyli druga lista formatów,
 * rozjeżdżająca się z pierwszą w obie strony naraz.
 *
 * SVG jest tu osobno groźny: to dokument XML, który potrafi nieść skrypt.
 * Nie trafiłby na stronę (potok przekodowuje wszystko do WebP), ale reguła,
 * która go przepuszcza, jest złym miejscem do rozpoczynania obrony.
 *
 * Ta reguła pyta o to samo, o co pyta `StoreUploadedImage`, i tym samym kodem.
 * Nie zastępuje tamtego sprawdzenia — tamto jest prawdziwą granicą i musi
 * trzymać także wtedy, gdy ktoś ominie formularz. Ta istnieje po to, żeby
 * człowiek dostał komunikat PRZY POLU, razem z resztą błędów i bez utraty
 * wpisanego tekstu (docs/UX_50_PLUS.md).
 *
 * SYGNAŁ `photo_upload_failed` TAKŻE STĄD (audyt zewnętrzny, punkt N05)
 * Zdjęcie, które odpada TUTAJ, nigdy nie dociera do `StoreUploadedImage`
 * — `PostController::store()` (i trzy pozostałe kontrolery przyjmujące
 * zdjęcia) rzuca `ValidationException` i wraca do formularza, zanim
 * jakikolwiek kod domenowy zobaczy plik. Dla `StoreUploadedImage` taka
 * próba WGRANIA W OGÓLE SIĘ NIE ZDARZYŁA — a dla człowieka przed ekranem
 * jest to dokładnie to samo „nie udało się wgrać zdjęcia". Bez zapisu tutaj
 * te dwie drogi (zły typ pliku, za duży plik) były niewidoczne z samego
 * Postgresa, mimo że są najprawdopodobniej NAJCZĘSTSZYM sposobem, w jaki
 * ktoś w ogóle trafia na `photo_upload_failed` — to formularz odrzuca,
 * zanim jakikolwiek plik trafi na dysk.
 *
 * Kolejność sprawdzeń (rozmiar → treść) i same kody powodu są CELOWO
 * identyczne z `StoreUploadedImage::handle()`, więc dashboard licząc
 * `properties->>'reason'` nie musi wiedzieć, na którym z dwóch etapów
 * odpadło zdjęcie. Rozmiaru NIE liczymy przez osobną regułę Laravela
 * (`max:kilobajtów`, wciąż zostaje w kontrolerach jako druga, niezależna
 * linia obrony) — bo ta reguła nic nie wie o `ZapiszSygnal` i nie da się
 * do niej dopisać zapisu sygnału bez rozwidlenia sprawdzenia na dwa miejsca.
 */
final class ObslugiwaneZdjecie implements ValidationRule
{
    public function __construct(private readonly ZapiszSygnal $sygnaly = new ZapiszSygnal) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $this->zglosOdrzucenie(ZapiszSygnal::REASON_UNREADABLE);
            $fail('Nie udało się odczytać pliku. Spróbuj wybrać zdjęcie jeszcze raz.');

            return;
        }

        $bytes = $value->getSize();

        if ($bytes === false || $bytes <= 0) {
            $this->zglosOdrzucenie(ZapiszSygnal::REASON_UNREADABLE);
            $fail('Nie udało się odczytać pliku. Spróbuj wybrać zdjęcie jeszcze raz.');

            return;
        }

        $maxBytes = LimityZdjec::maksBajtowJednegoZdjecia();

        if ($bytes > $maxBytes) {
            $this->zglosOdrzucenie(ZapiszSygnal::REASON_TOO_LARGE, ['bytes' => $bytes, 'max_bytes' => $maxBytes]);
            $fail(LimityZdjec::komunikatZaDuzyPlik());

            return;
        }

        $wynik = RozpoznanieZdjecia::rozpoznaj($value->getRealPath());

        if ($wynik !== null) {
            $this->zglosOdrzucenie($wynik->powod, $wynik->kontekst);
            $fail($wynik->komunikat);
        }
    }

    /**
     * `request()->user()` — NIGDY nazwa pliku ani inna treść od klienta,
     * tylko kod powodu z zamkniętego zbioru i, gdzie sensowne, liczby
     * (patrz AGENTS.md §7 i komentarz `ZapiszSygnal`).
     *
     * @param  array<string, int|float>  $kontekst
     */
    private function zglosOdrzucenie(string $powod, array $kontekst = []): void
    {
        $this->sygnaly->handle(request()->user(), ZapiszSygnal::PHOTO_UPLOAD_FAILED, [
            'reason' => $powod,
            ...$kontekst,
        ]);
    }
}
