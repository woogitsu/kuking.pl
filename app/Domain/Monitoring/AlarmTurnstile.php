<?php

declare(strict_types=1);

namespace App\Domain\Monitoring;

/**
 * „Turnstile nie chroni niczego, bo sekret jest zły" na webhook właściciela (#599).
 *
 * PO CO
 * Przy `invalid-input-secret` `KlientTurnstile` celowo PRZEPUSZCZA formularz
 * (D-050: nasz błąd konfiguracji nie zamyka rejestracji). Do tej pory jedynym
 * śladem była linia `Log::error` w dzienniku, a `/health` nadal mówił
 * `turnstile.ok=true`, bo sprawdza tylko obecność kluczy. Czyli: wszystkie
 * chronione formularze działały bez weryfikacji, a jedyny sygnał czekał, aż
 * ktoś sam przeczyta dziennik.
 *
 * MASZYNA EPIZODU JEST WSPÓLNA (`EpizodAlarmu`): pierwszy zły sekret dzwoni
 * od razu, kolejne w oknie `CISZA_GODZIN` nie dzwonią, pierwsza udana
 * weryfikacja (albo odrzucony TOKEN — wtedy sekret jest dobry) daje jedno
 * odwołanie. Porażka kanału nie kupuje ciszy.
 *
 * CO WYCHODZI: stała treść i instrukcja. Bez sekretu, tokenu, adresu IP,
 * nazwy formularza i kodów zwróconych przez Cloudflare.
 *
 * KOSZT W ŻĄDANIU: stan spokojny bez trwającego epizodu to jeden odczyt
 * cache i nic więcej. Wysyłka na webhook (do 3 s) zdarza się wyłącznie przy
 * zmianie stanu albo raz na okno ciszy — nie przy każdym formularzu.
 */
final class AlarmTurnstile
{
    public const DZIALA = 'dziala';

    public const BLAD_KONFIGURACJI = 'blad_konfiguracji';

    private const KLUCZ = 'kuking:turnstile:ostatni-alarm';

    private const CISZA_GODZIN = 6;

    public function __construct(
        private readonly EpizodAlarmu $epizod,
        private readonly AlarmMemory $pamiec,
    ) {}

    public function zlaKonfiguracja(): bool
    {
        return $this->zadzwon(self::BLAD_KONFIGURACJI);
    }

    public function dziala(): bool
    {
        // Najczęstsza ścieżka: brak epizodu = nie ma czego odwoływać.
        if ($this->pamiec->get(self::KLUCZ) === null) {
            return false;
        }

        return $this->zadzwon(self::DZIALA);
    }

    private function zadzwon(string $stan): bool
    {
        return $this->epizod->zadzwonJesliTrzeba(
            klucz: self::KLUCZ,
            stan: $stan,
            spokojny: self::DZIALA,
            alarmujace: [self::BLAD_KONFIGURACJI],
            ciszaGodzin: self::CISZA_GODZIN,
            trescAlarmu: static fn (): string => implode(' ', [
                'Turnstile: Cloudflare odrzuca NASZ sekret albo nasze żądanie.',
                'Formularze chronione Turnstile przechodzą teraz BEZ weryfikacji (zostają tylko limity zapytań).',
                'Co zrobić: w Railway sprawdź TURNSTILE_SECRET_KEY — musi być Secret Key tego samego widgetu co TURNSTILE_SITE_KEY — i zrestartuj usługę.',
            ]),
            trescOdwolania: static fn (string $poprzedni): string => 'Turnstile: weryfikacja znowu działa (poprzedni stan: '.$poprzedni.').',
        );
    }
}
