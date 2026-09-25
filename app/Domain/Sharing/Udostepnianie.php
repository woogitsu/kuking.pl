<?php

declare(strict_types=1);

namespace App\Domain\Sharing;

use App\Models\Post;
use App\Models\Recipe;
use App\Support\AdresKanoniczny;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * „Podziel się" — co wolno wysłać znajomym i pod jakim adresem.
 *
 * PO CO TO JEST OSOBNĄ KLASĄ, A NIE KILKOMA LINIJKAMI W WIDOKU
 *
 * Przycisk wysyłania to obietnica: „kliknij, a osoba po drugiej stronie
 * to zobaczy". Przy wpisie „tylko dla obserwujących" albo „tylko dla mnie"
 * ta obietnica jest fałszywa w obie strony naraz — córka dostaje 403,
 * a autor jest przekonany, że coś wysłał. Dlatego o tym, czy przycisk
 * w ogóle istnieje, decyduje jedno miejsce.
 *
 * O OBECNOŚCI PRZYCISKU DECYDUJE WYŁĄCZNIE GATE.
 * Pyta `Gate` o `view` z widzem `null`, czyli dokładnie o to, co zobaczy
 * ktoś, kto dostanie link i nie ma u nas konta. To jest ten sam wzorzec,
 * co w `App\Domain\Media\DostepDoZdjecia` i z tego samego powodu:
 * powtarzającą się przyczyną błędów w tym repozytorium jest „reguła
 * istnieje poprawnie w jednej warstwie, a druga implementuje ją inaczej".
 * Gdyby decyzję o przycisku podejmował własny `match ($visibility)`, ban autora naprawiony
 * w `PostPolicy` nie naprawiałby się w przycisku wysyłania — i nikt by
 * tego nie zauważył, bo widoczny przycisk nie wywala żadnego testu.
 * `match` w wyjaśnieniu dla autora dobiera tylko opis ustawienia po odmowie
 * Gate. Nie przyznaje dostępu ani nie decyduje o obecności przycisku.
 *
 * DLACZEGO „GOŚĆ", A NIE „TEN, KTO KLIKA"
 * Adres wysłany na WhatsAppie trafia do kogokolwiek — także do osoby bez
 * konta i bez obserwowania. Najsłabszy możliwy odbiorca jest tu jedyną
 * sensowną miarą. Przepis „tylko dla obserwujących" jest więc niewysyłalny
 * nawet dla własnego autora, choć autor widzi go bez przeszkód.
 */
final class Udostepnianie
{
    /**
     * Czy tę treść wolno komuś wysłać.
     *
     * `Gate::forUser(null)` to dosłownie pytanie „czy zobaczy to ktoś,
     * kto wejdzie z linka bez logowania". Obie polityki (`PostPolicy`,
     * `RecipePolicy`) przyjmują `?User`, więc gość jest dla nich
     * normalnym, przewidzianym przypadkiem.
     */
    public function wolnoWyslac(Post|Recipe $tresc): bool
    {
        return Gate::forUser(null)->allows('view', $tresc);
    }

    /**
     * Dlaczego przycisku nie ma — tekst dla AUTORA treści, nie dla obcego.
     *
     * Bez tego autor prywatnego wpisu widzi po prostu brak przycisku
     * i nie ma jak się domyślić, że to nie usterka. Zdanie mówi, co
     * zrobić, żeby przycisk się pojawił (`docs/UX_50_PLUS.md`:
     * komunikat mówi, co zrobić).
     *
     * Zwraca `null`, gdy powodu nie ma — czyli gdy wysyłać wolno.
     */
    public function powodBrakuPrzycisku(Post|Recipe $tresc): ?string
    {
        if ($this->wolnoWyslac($tresc)) {
            return null;
        }

        $rzecz = $tresc instanceof Recipe ? 'przepis' : 'wpis';

        if (! $tresc->isPublished()) {
            return "Ten {$rzecz} nie jest jeszcze opublikowany, więc nie ma czego wysłać. "
                .'Opublikuj go, a pojawi się tu przycisk „Podziel się”.';
        }

        return match ($tresc->visibility) {
            'private' => "Ten {$rzecz} widzisz tylko Ty. "
                .'Zmień widoczność na „wszyscy”, jeśli chcesz udostępnić go przez przycisk „Podziel się”.',
            'followers' => "Ten {$rzecz} widzisz Ty oraz osoby, które Cię obserwują. "
                .'Zmień widoczność na „wszyscy”, jeśli chcesz udostępnić go przez przycisk „Podziel się”.',
            default => "Ten {$rzecz} nie jest teraz dostępny dla osób bez zalogowania, więc przycisk „Podziel się” jest niedostępny.",
        };
    }

    /**
     * Adres, który dostanie odbiorca. Zawsze bezwzględny i bez parametrów.
     *
     * Host z `APP_URL`, nie z żądania (issue #1369): wejście przez `www`
     * dawałoby linki WhatsApp, e-mail i Facebook z `www`, a sitemapa
     * i canonical wskazują apex — jedna treść zbierałaby udostępnienia
     * pod dwoma adresami.
     */
    public function adres(Post|Recipe $tresc): string
    {
        return AdresKanoniczny::zbuduj(fn (): string => $tresc instanceof Recipe
            ? route('recipes.show', $tresc)
            : $tresc->url());
    }

    /**
     * Nagłówek wiadomości.
     *
     * Wpis nie ma tytułu — najczęściej to samo zdjęcie z podpisem — więc
     * niesie go imię autora. Formy zakładające rodzaj („ugotowała")
     * świadomie nie ma (`AGENTS.md` §11).
     */
    public function tytul(Post|Recipe $tresc): string
    {
        if ($tresc instanceof Recipe) {
            return $tresc->title;
        }

        if ($tresc instanceof Post && $tresc->kind === Post::KIND_QUESTION) {
            return (string) $tresc->title;
        }

        return $tresc->author->displayName().' na Kuking';
    }

    /**
     * Kilka słów, które lecą razem z adresem.
     *
     * Bez emoji i bez wykrzyknika (`docs/brand/COPY_STYLE.md`). Podpis
     * wpisu skracamy: WhatsApp i tak utnie długą treść, a wiadomość ma
     * być zachętą do kliknięcia, nie kopią strony.
     */
    public function opis(Post|Recipe $tresc): string
    {
        if ($tresc instanceof Recipe) {
            return 'Przepis z Kuking: '.$tresc->title;
        }

        $podpis = trim((string) $tresc->body);

        return $podpis === ''
            ? $this->tytul($tresc)
            : $this->tytul($tresc).': '.Str::limit($podpis, 120);
    }

    /**
     * Gotowe adresy do jawnej listy — wersja PODSTAWOWA, bez JavaScriptu.
     *
     * To jest ta warstwa, która ma działać zawsze. Arkusz systemowy
     * (`navigator.share`) nakładamy na nią w `resources/js/app.js`,
     * a nie odwrotnie: `navigator.share` jest z definicji JavaScriptem,
     * więc nie może być jedyną drogą (`AGENTS.md` §5).
     *
     * CZEGO TU NIE MA I DLACZEGO — pełne uzasadnienie w `docs/DECISIONS.md`,
     * D-044:
     *
     *  - MESSENGER. Okno „wyślij osobie" (`facebook.com/dialog/send`)
     *    wymaga własnego `app_id` z zarejestrowanej aplikacji na Facebooku.
     *    Bez niej adres kończy się ekranem logowania, nie oknem wysyłania —
     *    zmierzone curl-em, patrz opis Pull Requesta. Messenger jest za to
     *    normalną pozycją w arkuszu systemowym na telefonie i tam ludzie
     *    go zobaczą.
     *  - SMS (`sms:`). Na telefonie działa, na komputerze najczęściej nie
     *    robi nic. Martwy przycisk jest gorszy niż jego brak; SMS-y są
     *    w arkuszu systemowym.
     *  - `fb-messenger://share`. Ten sam problem co `sms:`, tylko gorszy:
     *    na komputerze bez aplikacji przeglądarka pokazuje komunikat
     *    o nieznanym protokole.
     *
     * @return list<array{nazwa: string, adres: string, opis: string, zewnetrzny: bool}>
     */
    public function drogi(Post|Recipe $tresc): array
    {
        $adres = $this->adres($tresc);
        $tytul = $this->tytul($tresc);
        $opis = $this->opis($tresc);

        return [
            [
                'nazwa' => 'WhatsApp',
                // `wa.me` działa i na telefonie, i w przeglądarce
                // (przekierowuje na `api.whatsapp.com`), bez żadnej
                // rejestracji po naszej stronie.
                'adres' => 'https://wa.me/?text='.rawurlencode($opis."\n".$adres),
                'opis' => 'Otworzy się rozmowa, w której wybierzesz osobę.',
                'zewnetrzny' => true,
            ],
            [
                'nazwa' => 'E-mail',
                'adres' => 'mailto:?subject='.rawurlencode($tytul)
                    .'&body='.rawurlencode($opis."\n\n".$adres),
                'opis' => 'Otworzy się Twój program pocztowy z gotową treścią.',
                'zewnetrzny' => false,
            ],
            [
                'nazwa' => 'Facebook',
                'adres' => 'https://www.facebook.com/sharer/sharer.php?u='.rawurlencode($adres),
                // Uczciwie: to jest wstawienie na TABLICĘ, a nie wysłanie
                // jednej osobie. Nazwanie tego „Messengerem" byłoby
                // obietnicą bez pokrycia.
                'opis' => 'Wstawisz to na swoją tablicę na Facebooku.',
                'zewnetrzny' => true,
            ],
        ];
    }

    /**
     * Czy ten model w ogóle umiemy wysłać.
     *
     * Świadomie wąska lista: nowy typ treści ma tu trafić razem z decyzją,
     * co znaczy dla niego „publiczny", a nie odziedziczyć przycisk po cichu.
     * Pozostałe metody tej klasy przyjmują już wyłącznie `Post|Recipe`
     * (issue #1731) — widok pyta najpierw tutaj, dopiero potem o resztę.
     */
    public function obslugiwana(Model $tresc): bool
    {
        return $tresc instanceof Post || $tresc instanceof Recipe;
    }
}
