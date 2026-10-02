<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Exports\InwentarzDanychKonta;
use Tests\TestCase;

/**
 * Polityka prywatności mówi to, co robi kod (#1816, jedno podbicie z #1324 i #619).
 *
 *  - art. 15: zdanie o paczce danych ma zgadzać się z `InwentarzDanychKonta`
 *    (co jest w środku, co tylko na prośbę). Do 29.09.2026 polityka nazywała
 *    zgłoszenia i korespondencję „spoza paczki”, choć od #953 są w środku;
 *  - wiersz „Relacje w serwisie” wymienia obserwowane tagi (rejestr §3.5 je ma);
 *  - ukrycia, reakcja „Smakowicie wygląda” i lista „Co mam w domu” mają
 *    własne wiersze: co zapisujemy, na jak długo i co z tego wynika.
 *
 * Liczby (30 dni ukrycia) porównujemy z konfiguracją, nie przepisujemy z palca —
 * tak samo jak w `DokumentyPrawneNieKlamiaTest` (D-024).
 */
class PolitykaOpisujePaczkeUkryciaIReakcjeTest extends TestCase
{
    /**
     * Każda pozycja `NA_ZADANIE` z inwentarza i zdanie, którym polityka mówi,
     * że wydajemy ją na prośbę. Nowa pozycja `NA_ZADANIE` bez wpisu tutaj oblewa
     * test — ktoś musi napisać w polityce, że jest poza paczką.
     */
    private const POZA_PACZKA = [
        'audit_log.actor_id' => 'dziennik bezpieczeństwa',
        'reports.autor_tresci_id' => 'zgłoszenia Twoich treści złożone przez inne osoby',
        'notifications.actor_id' => 'powiadomienia, które o Twoich działaniach dostały inne osoby',
        'mail_failures.user_id' => 'techniczny ślad nieudanych wysyłek poczty',
        'potwierdzenia_zadan_rodo.konto_id' => 'rejestr obsługi Twoich żądań dotyczących danych',
        'blocks.blocked_id' => 'Nie podajemy natomiast, kto Cię zablokował ani ukrył',
        'hides.hidden_user_id' => 'Nie podajemy natomiast, kto Cię zablokował ani ukrył',
        'appeals.decided_by' => 'czynności wykonane przez Twoje konto w tej roli',
        'contact_messages.handled_by' => 'czynności wykonane przez Twoje konto w tej roli',
        'contact_message_replies.author_id' => 'czynności wykonane przez Twoje konto w tej roli',
        'daily_picks.curator_id' => 'czynności wykonane przez Twoje konto w tej roli',
        'hero_picks.curator_id' => 'czynności wykonane przez Twoje konto w tej roli',
        'moderation_actions.moderator_id' => 'czynności wykonane przez Twoje konto w tej roli',
        'reports.resolved_by' => 'czynności wykonane przez Twoje konto w tej roli',
        'zabezpieczenia_dowodow.secured_by' => 'czynności wykonane przez Twoje konto w tej roli',
        'zabezpieczenia_dowodow.subject_user_id' => 'rejestr treści zabezpieczonych w związku z podejrzeniem przestępstwa',
        'weekly_recipe_picks.chosen_by' => 'przepisy tygodnia w „Ugotujmy razem”, które wskazało Twoje konto jako gospodarza',
    ];

    /** Zdanie polityki o zawartości paczki => sekcja `dane.json`, która to niesie. */
    private const W_PACZCE = [
        'obserwowane tagi' => 'obserwowane_tagi',
        'ukryte wpisy i osoby' => 'ukryte',
        'Twoje reakcje „Smakowicie wygląda”' => 'moje_reakcje',
        'lista „Co mam w domu”' => 'co_mam_w_domu',
        'zgody' => 'dziennik_zgod',
        'wiadomości, które wysłano do nas z Twojego konta, razem z naszymi odpowiedziami' => 'wiadomosci_do_serwisu',
        'Twoje zgłoszenia' => 'moje_zgloszenia',
        'odwołania' => 'odwolania',
        'decyzje moderacji, które Cię dotyczą' => 'decyzje_moderacji',
    ];

    private function polityka(): string
    {
        return (string) file_get_contents(resource_path('legal/polityka-prywatnosci.md'));
    }

    private function wiersz(string $poczatek): string
    {
        $wiersze = array_values(array_filter(
            explode("\n", $this->polityka()),
            static fn (string $l): bool => str_starts_with($l, $poczatek),
        ));

        $this->assertCount(1, $wiersze, "Kontrola: w polityce ma być dokładnie jeden wiersz „{$poczatek}”.");

        return $wiersze[0];
    }

    private function zdaniePaczki(): string
    {
        return $this->wiersz('- **dostępu** do swoich danych');
    }

    public function test_polityka_wymienia_poza_paczka_wszystko_co_inwentarz_oznacza_na_zadanie(): void
    {
        $zdanie = $this->zdaniePaczki();
        $naZadanie = array_keys(array_filter(
            InwentarzDanychKonta::KOLUMNY_WSKAZUJACE_NA_KONTO,
            static fn (array $wpis): bool => $wpis[0] === InwentarzDanychKonta::NA_ZADANIE,
        ));

        // Kontrola: pusta lista zrobiłaby z pętli poniżej pustą prawdę.
        $this->assertGreaterThanOrEqual(10, count($naZadanie), 'Kontrola: inwentarz nie ma pozycji NA_ZADANIE — test przestał mierzyć.');

        foreach ($naZadanie as $kolumna) {
            $this->assertArrayHasKey(
                $kolumna,
                self::POZA_PACZKA,
                "`{$kolumna}` jest w InwentarzDanychKonta jako NA_ZADANIE, ale polityka (art. 15) o niej nie mówi. "
                .'Dopisz do zdania „Poza paczką, ale na Twoją prośbę, wydajemy” w resources/legal/polityka-prywatnosci.md '
                .'i do POZA_PACZKA w tym teście.',
            );
            $this->assertStringContainsString(
                self::POZA_PACZKA[$kolumna],
                $zdanie,
                'Polityka nie mówi już „'.self::POZA_PACZKA[$kolumna]."” (`{$kolumna}`, NA_ZADANIE w InwentarzDanychKonta).",
            );
        }

        // Odwrotnie: wpis w tym teście bez pozycji w inwentarzu to martwe zdanie.
        foreach (array_keys(self::POZA_PACZKA) as $kolumna) {
            $this->assertContains($kolumna, $naZadanie, "`{$kolumna}` nie jest już NA_ZADANIE w InwentarzDanychKonta — popraw politykę i ten test.");
        }

        $this->assertStringContainsString('wewnętrzne notatki moderacji i obsługi', $zdanie);
    }

    public function test_polityka_nie_stawia_poza_paczka_tego_co_jest_w_srodku(): void
    {
        $zdanie = $this->zdaniePaczki();
        $granica = strpos($zdanie, 'Poza paczką, ale na Twoją prośbę');
        $this->assertNotFalse($granica, 'Kontrola: brak zdania „Poza paczką, ale na Twoją prośbę”.');

        $eksport = [];

        foreach (InwentarzDanychKonta::KOLUMNY_WSKAZUJACE_NA_KONTO as [$tryb, $sekcja]) {
            if ($tryb === InwentarzDanychKonta::EKSPORT) {
                $eksport[$sekcja] = true;
            }
        }

        foreach (self::W_PACZCE as $zdaniePolityki => $sekcja) {
            $this->assertArrayHasKey(
                $sekcja,
                $eksport,
                "Polityka mówi, że w paczce są „{$zdaniePolityki}”, ale sekcji `{$sekcja}` nie niesie żadna pozycja EKSPORT w InwentarzDanychKonta.",
            );

            $pozycja = strpos($zdanie, $zdaniePolityki);
            $this->assertNotFalse($pozycja, "Polityka nie mówi, że w paczce są „{$zdaniePolityki}”.");
            $this->assertLessThan($granica, $pozycja, "„{$zdaniePolityki}” stoi w polityce za granicą „Poza paczką” — a jest w środku paczki.");
        }

        $this->assertStringNotContainsString(
            'historii zgłoszeń albo korespondencji z nami',
            $this->polityka(),
            'Polityka znów nazywa zgłoszenia i korespondencję rzeczą „spoza paczki”, choć są w sekcjach '
            .'`moje_zgloszenia` i `wiadomosci_do_serwisu` (#953, #1816).',
        );
    }

    public function test_relacje_wymieniaja_obserwowane_tagi(): void
    {
        $this->assertStringContainsString('jakie tagi obserwujesz', $this->wiersz('| Relacje w serwisie'));
    }

    public function test_ukrycia_maja_wiersz_z_okresem_z_konfiguracji_i_zobowiazaniem(): void
    {
        $dni = (int) config('kuking.ukrycia.dni');
        $this->assertGreaterThan(0, $dni);

        $wiersz = $this->wiersz('| Ukrywanie wpisów i osób');

        $this->assertStringContainsString("**{$dni} dni**", $wiersz);
        $this->assertStringContainsString('Zostaw ukryte', $wiersz);
        $this->assertStringContainsString('Wykonanie umowy', $wiersz);
        $this->assertStringContainsString(
            'Nie wnioskujemy o Tobie z ukryć i nie używamy ich do moderacji ani statystyk',
            $wiersz,
        );
    }

    public function test_reakcja_ma_wiersz_ktory_mowi_kto_widzi_nazwe_i_jak_powiadamiamy(): void
    {
        $wiersz = $this->wiersz('| Reakcja „Smakowicie wygląda”');

        $this->assertStringContainsString('Pod wpisem widać nazwę osoby, która zareagowała', $wiersz);
        $this->assertStringContainsString('także bez logowania', $wiersz);
        $this->assertStringContainsString('jedno zbiorcze powiadomienie dziennie', $wiersz);
        $this->assertStringContainsString('Wykonanie umowy', $wiersz);
    }

    public function test_lista_co_mam_w_domu_jest_prywatna_i_opisana(): void
    {
        $wiersz = $this->wiersz('| Lista „Co mam w domu”');

        $this->assertStringContainsString('widzisz ją tylko Ty', $wiersz);
        $this->assertStringContainsString('nigdzie jej nie wysyłamy', $wiersz);
        $this->assertStringContainsString('Wykonanie umowy', $wiersz);
    }

    public function test_rejestr_czynnosci_zna_te_same_kategorie(): void
    {
        $rejestr = (string) file_get_contents(base_path('docs/legal/REJESTR_CZYNNOSCI_PRZETWARZANIA.md'));

        preg_match('/### 3\.5 Relacje w serwisie\n(.+?)\n### /su', $rejestr, $relacje);
        $this->assertNotEmpty($relacje, 'Kontrola: brak sekcji 3.5 w rejestrze.');
        $this->assertStringContainsString('identyfikator obserwowanego tagu', (string) preg_replace('/\s+/u', ' ', $relacje[1]));

        foreach (['Ukrycia wpisów i osób', 'Reakcja „Smakowicie wygląda”', 'Lista „Co mam w domu”'] as $tytul) {
            $this->assertMatchesRegularExpression('/^### 3\.\d+ '.preg_quote($tytul, '/').'/mu', $rejestr, "Rejestr nie ma czynności „{$tytul}”.");
        }

        preg_match('/### 3\.17 .+?\n### /su', $rejestr, $prawa);
        $this->assertNotEmpty($prawa, 'Kontrola: brak sekcji 3.17 w rejestrze.');
        $this->assertStringNotContainsString(
            'paczka **nie zawiera** ośmiu',
            $prawa[0],
            'Rejestr §3.17 znów twierdzi, że paczka nie zawiera kategorii, które od #953 są w środku.',
        );
    }
}
