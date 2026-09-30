<?php

declare(strict_types=1);

namespace App\Domain\Sharing;

use App\Models\Profile;
use App\Models\Recipe;
use App\Support\AdresKanoniczny;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Karta do wydruku z kodem QR dla publicznego przepisu albo profilu (#2349, F10).
 *
 * PO CO
 * Prowadząca zajęcia w KGW/UTW albo córka rozdaje papierową kartkę, która
 * łączy osobę BEZ konta z jednym przepisem. Adresu z papieru nikt nie
 * przepisuje z telefonu, kod się skanuje.
 *
 * CO KODUJE QR (i czego nie wolno)
 * Wyłącznie kanoniczny adres publicznej strony, zbudowany z modelu — nigdy
 * z żądania. Nie ma w nim parametrów z adresu karty (`?druk=1`, `?utm_…`),
 * tokenu, identyfikatora sesji ani danych osoby drukującej. Ten sam adres
 * stoi na kartce tekstem, więc kartka działa także bez skanera.
 *
 * KTO MOŻE WYDRUKOWAĆ
 * Każdy, kto widzi treść — to tylko publiczny link, taki sam jak ten
 * z „Podziel się”. Karta istnieje wyłącznie dla treści, którą zobaczy GOŚĆ
 * (`Udostepnianie::wolnoWyslac()` pyta Policy jako gość): prywatny,
 * „tylko dla obserwujących”, ukryty, usunięty przepis i przepis konta
 * zbanowanego albo kasowanego nie dostaje karty, także u własnego autora —
 * kartka z kodem, który otwiera 403, byłaby gorsza niż jej brak.
 */
final class KartaZKodemQr
{
    public function __construct(private readonly Udostepnianie $udostepnianie) {}

    public function przepisDostepny(Recipe $przepis): bool
    {
        return $this->udostepnianie->wolnoWyslac($przepis);
    }

    /**
     * Profil, na którym gość ma co oglądać: konto dostępne jako autor
     * i co najmniej jedna publiczna treść (`Profile::scopeZPublicznaTrescia()`,
     * ta sama bramka co mapa strony).
     */
    public function profilDostepny(Profile $profil): bool
    {
        return Profile::query()->zPublicznaTrescia()->whereKey($profil->getKey())->exists();
    }

    public function adresPrzepisu(Recipe $przepis): string
    {
        return $this->udostepnianie->adres($przepis);
    }

    public function adresProfilu(Profile $profil): string
    {
        return AdresKanoniczny::zbuduj(fn (): string => route('profile.show', $profil->username));
    }

    /**
     * QR jako inline SVG liczony na serwerze: bez zewnętrznego generatora,
     * bez zapisu na dysk, bez JavaScriptu. Margines 4 modułów (cisza wokół
     * kodu wymagana przez skanery) siedzi w samym obrazku.
     */
    public function kodSvg(string $adres): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle(400, 4),
            new SvgImageBackEnd,
        );

        $svg = (new Writer($renderer))->writeString($adres);
        $poczatek = mb_strpos($svg, '<svg');

        return trim($poczatek === false ? $svg : mb_substr($svg, $poczatek));
    }
}
