<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Digest\TrescDigestu;
use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Profile;
use App\Models\Recipe;
use App\Models\User;
use Carbon\CarbonImmutable;

/** Rzeczywiste widoki, bez bazy, kolejki i wysyłki. Digest obejmuje wszystkie sekcje. */
final class WlasneListyMarki
{
    public static function render(): array
    {
        // Przywrócenie mtime po kontroli ujemnej nie unieważnia cache Blade.
        // Ten przyrząd zawsze renderuje obecne źródło, nigdy starszy artefakt.
        foreach (glob(resource_path('views/mail/*.blade.php')) as $sciezka) {
            app('blade.compiler')->compile($sciezka);
        }

        $osoba = (new User)->forceFill(['id' => '12345678-1234-4234-8234-123456789abc'])
            ->setRelation('profile', new Profile(['display_name' => 'Anna Kowalska']));
        $przepis = new Recipe(['title' => 'Pierogi z kapustą']);
        $wykonanie = (new CookedEvent)->forceFill(['id' => '12345678-1234-4234-8234-123456789abd', 'note' => 'Wyszły bardzo dobrze.'])
            ->setRelation('user', $osoba)->setRelation('recipe', $przepis);
        $wpis = (new Post)->forceFill(['id' => '12345678-1234-4234-8234-123456789abe', 'body' => 'Dzisiejszy obiad.'])
            ->setRelation('author', $osoba)->setRelation('recipe', null);
        $dane = [
            'displayName' => 'Anna',
            'linkUrl' => 'https://kuking.test/testowy-link?token='.str_repeat('a', 64),
            'downloadUrl' => 'https://kuking.test/paczka?signature='.str_repeat('b', 64),
            'expiresAt' => CarbonImmutable::parse('2026-09-20 10:37:00', 'UTC'),
            'sizeText' => '2 MB',
            'waznoscTekst' => '15 minut',
            'waznyDo' => '20 września 2026, 12:37',
            'napisanaKiedy' => CarbonImmutable::parse('2026-09-13 10:00:00', 'UTC'),
            'rodzaj' => 'Pomysł',
            'tresc' => 'Dziękujemy za wiadomość.',
            'adresKontaktowy' => 'kontakt@kuking.test',
            'linkLogowania' => 'https://kuking.test/login',
            'nowyAdresSkrot' => 'a***@example.test',
            'linkHaslo' => 'https://kuking.test/haslo',
        ];
        $wyniki = [];
        foreach (['data-export-ready', 'haslo-zamiast-linku', 'link-do-logowania', 'nowe-haslo', 'odpowiedz-na-wiadomosc', 'potwierdz-adres', 'potwierdz-nowy-adres', 'proba-wejscia-kontem-facebooka', 'zaproszenie-do-zalozenia-konta', 'zgloszona-zmiana-adresu'] as $widok) {
            $wyniki[$widok] = view('mail.'.$widok, $dane)->render();
        }
        $wyniki['podsumowanie-tygodnia'] = view('mail.podsumowanie-tygodnia', [
            'tresc' => new TrescDigestu($osoba, [$wykonanie], [$osoba], 2, [$wpis], 'Co gotujesz w niedzielę?'),
            'imie' => 'Anna',
            'gospodarz' => 'Jan',
            'wypisz' => 'https://kuking.test/podsumowanie/wypisz/test?signature='.str_repeat('c', 64),
        ])->render();

        return $wyniki;
    }
}
