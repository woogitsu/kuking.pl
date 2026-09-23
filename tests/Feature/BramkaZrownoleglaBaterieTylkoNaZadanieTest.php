<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Bramka puszcza baterię RÓWNOLEGLE tylko wtedy, gdy ktoś o to poprosi.
 *
 * PO CO TO JEST
 * -------------
 * `scripts/check.sh` wołał `php artisan test` na sztywno. Bateria zajmowała 82%
 * czasu bramki i chodziła na JEDNYM rdzeniu z 24 — zmierzone 21.09.2026 na
 * 4402 testach: 310 s szeregowo, 105 s na sześciu procesach. Stąd przełącznik
 * `KUKING_TESTY_ROWNOLEGLE=N`.
 *
 * Domyślnie ma być SZEREGOWO i to jest właściwa treść tego testu. Przy zwykłym
 * przeciążeniu obowiązuje reguła „fałszywa czerwień, nigdy fałszywa zieleń” —
 * przebieg zielony pod obciążeniem jest wiarygodny. Równoległość tę regułę
 * OSŁABIA: test zależny od kolejności albo współdzielonego stanu może się pod
 * nią zachować inaczej. W pomiarze z 21.09 liczba testów zgadzała się co do
 * jednego (4402), ale asercji było 83722 szeregowo i 83721 równolegle. Ta jedna
 * różnica jest nadal NIEWYJAŚNIONA — i dlatego równoległość jest świadomym
 * wyborem stanowiska, a nie zachowaniem domyślnym. Ten test jest kotwicą tego
 * rozstrzygnięcia: „domyślnie szeregowo” przestaje być zdaniem w komentarzu,
 * a staje się czymś, czego nie da się cofnąć bez czerwieni.
 *
 * DLACZEGO TEN TEST WYKONUJE KAWAŁEK `check.sh`, ZAMIAST SZUKAĆ W NIM NAPISÓW
 * ---------------------------------------------------------------------------
 * Bo `--parallel` stoi w tym pliku w kilku miejscach naraz: w komentarzu
 * nagłówkowym kroku, w budowanym poleceniu i w podpowiedzi wypisywanej przy
 * czerwieni. Test szukający napisu przechodziłby po wycięciu tego jedynego
 * miejsca, które coś robi — byłby zielony nad martwym przełącznikiem. To
 * pułapka 1 z `docs/PULAPKI_TESTOW.md`: dopasowanie łapie to samo słowo
 * skądinąd. Odwrotnie też: test szukający BRAKU napisu `--parallel` w pliku
 * byłby czerwony od samego komentarza.
 *
 * Dlatego wycinamy z `check.sh` PRAWDZIWY krok „Testy” i wykonujemy go
 * w powłoce z podstawionym `php`, `krok`, `ok` i `zle`. Sprawdzamy, z jakimi
 * argumentami bramka naprawdę woła `php` — czyli to, co obiecuje nazwa tego
 * pliku, a nie to, co o sobie pisze skrypt.
 *
 * CZEGO TEN TEST NIE ROBI
 * -----------------------
 * Nie uruchamia baterii — ani szeregowo, ani równolegle. Podstawiony `php`
 * tylko zapisuje argumenty i zwraca zero, więc cały przebieg trwa ułamek
 * sekundy i nie dotyka bazy. Nie dowodzi też, że równoległa bateria daje ten
 * sam wynik co szeregowa — dowodzi wyłącznie tego, KIEDY bramka jej używa.
 *
 * @see scripts/check.sh — krok 5 „Testy”
 * @see docs/PULAPKI_TESTOW.md §1
 *
 * @bez-kontroli-dodatniej Wycinek check.sh ma własną ochronę przed pustką (assertNotFalse na obu granicach i assertStringContainsString na znacznikach), więc zgubiony krok daje czerwień, nie cichą zieleń.
 */
class BramkaZrownoleglaBaterieTylkoNaZadanieTest extends TestCase
{
    /** Wycięty z `check.sh` krok „Testy” — prawdziwy kod, nie jego kopia. */
    private function krokTestow(): string
    {
        $sciezka = base_path('scripts/check.sh');

        $this->assertFileExists(
            $sciezka,
            'Nie ma scripts/check.sh — bez niego ten test nie sprawdza niczego.',
        );

        $tresc = (string) file_get_contents($sciezka);

        $poczatek = strpos($tresc, 'krok "Testy"');
        $koniec = strpos($tresc, '# --- 5b.');

        $this->assertNotFalse($poczatek, 'W check.sh nie ma kroku `krok "Testy"`.');
        $this->assertNotFalse($koniec, 'W check.sh nie ma granicy kroku 5b — nie wiadomo, gdzie kończy się bateria.');
        $this->assertGreaterThan($poczatek, $koniec, 'Krok „Testy” stoi po granicy 5b — wycinek byłby pusty.');

        $blok = substr($tresc, $poczatek, $koniec - $poczatek);

        // Bez tego wycinek mógłby zwęzić się do czegoś niewinnego, a wszystkie
        // asercje niżej byłyby zielone nad pustką.
        $this->assertStringContainsString('KUKING_TESTY_ROWNOLEGLE', $blok,
            'Wycięty krok „Testy” nie zna przełącznika równoległości — wycinek trafił w złe miejsce.');
        $this->assertStringContainsString('artisan test', $blok,
            'Wycięty krok „Testy” nie woła baterii — wycinek trafił w złe miejsce.');

        return $blok;
    }

    /**
     * Wykonuje krok „Testy” w osobnym katalogu z podstawionym `php`.
     *
     * @param  array<string, string>  $srodowisko
     * @return array{polecenie: string, bledy: string, katalog: string}
     */
    private function uruchomKrokTestow(array $srodowisko, bool $zParatestem): array
    {
        $katalog = sys_get_temp_dir().'/kuking-bramka-rownolegle-'.bin2hex(random_bytes(6));

        mkdir($katalog.'/vendor/bin', 0o755, true);

        if ($zParatestem) {
            file_put_contents($katalog.'/vendor/bin/paratest', "#!/bin/sh\nexit 0\n");
            chmod($katalog.'/vendor/bin/paratest', 0o755);
        }

        file_put_contents($katalog.'/blok-testow.sh', $this->krokTestow());

        // `php`, `krok`, `ok` i `zle` jako funkcje powłoki: funkcja wygrywa
        // z PATH, więc `"${_test_polecenie[@]}"` z check.sh trafia tutaj
        // zamiast uruchamiać prawdziwą baterię.
        file_put_contents($katalog.'/przebieg.sh', <<<'BASH'
            #!/usr/bin/env bash
            set -uo pipefail
            cd "$(dirname "$0")" || exit 1

            BLEDY=0
            krok() { :; }
            ok()   { :; }
            zle()  { printf '%s\n' "$1" >> bledy.log; BLEDY=$((BLEDY + 1)); }
            php()  { printf '%s\n' "$*" >> polecenie.log; return 0; }

            : > polecenie.log
            : > bledy.log

            . ./blok-testow.sh

            printf 'BLEDY=%d\n' "$BLEDY"
            BASH);

        $przedrostek = 'env -u KUKING_TESTY_ROWNOLEGLE';

        foreach ($srodowisko as $nazwa => $wartosc) {
            $przedrostek .= ' '.escapeshellarg($nazwa.'='.$wartosc);
        }

        shell_exec($przedrostek.' bash '.escapeshellarg($katalog.'/przebieg.sh').' 2>&1');

        return [
            'polecenie' => trim((string) @file_get_contents($katalog.'/polecenie.log')),
            'bledy' => trim((string) @file_get_contents($katalog.'/bledy.log')),
            'katalog' => $katalog,
        ];
    }

    #[Test]
    public function test_bez_zmiennej_bramka_puszcza_baterie_szeregowo(): void
    {
        $wynik = $this->uruchomKrokTestow([], zParatestem: true);

        // Równość, nie `assertStringNotContainsString`: dokładne polecenie jest
        // jedyną odpowiedzią na pytanie „co bramka zrobiła”, a lista rzeczy,
        // których ma NIE dokładać, z czasem przestałaby być pełna.
        $this->assertSame(
            'artisan test',
            $wynik['polecenie'],
            'Bramka bez KUKING_TESTY_ROWNOLEGLE zawołała coś innego niż `php artisan test`. '.
            'Domyślna równoległość osłabia regułę „fałszywa czerwień, nigdy fałszywa zieleń” '.
            'i nie wolno jej włączyć bez decyzji stanowiska.',
        );

        $this->assertSame('', $wynik['bledy'],
            'Szeregowy przebieg zgłosił błąd, choć nie miał czego zgłaszać: '.$wynik['bledy']);
    }

    #[Test]
    public function test_ustawiona_zmienna_puszcza_baterie_na_podanej_liczbie_procesow(): void
    {
        $wynik = $this->uruchomKrokTestow(['KUKING_TESTY_ROWNOLEGLE' => '6'], zParatestem: true);

        $this->assertSame(
            'artisan test --parallel --processes=6 --recreate-databases',
            $wynik['polecenie'],
            'Bramka z KUKING_TESTY_ROWNOLEGLE=6 nie puściła baterii równolegle na sześciu procesach.',
        );

        // `--recreate-databases` nie jest kosmetyką: bazy robocze <baza>_test_N
        // przeżywają między przebiegami, a kolejne gałęzie mają różne migracje.
        // Bez tego druga gałąź dostałaby schemat pierwszej. Asercja wyżej już
        // to obejmuje — ten komentarz stoi tu, żeby nikt nie skrócił polecenia
        // „bo wygląda na zbędne”.
        $this->assertSame('', $wynik['bledy'],
            'Równoległy przebieg z obecnym paratestem zgłosił błąd: '.$wynik['bledy']);
    }

    #[Test]
    public function test_brak_paratesta_przy_ustawionej_zmiennej_jest_bledem_a_nie_cicha_ucieczka_do_szeregowych(): void
    {
        $wynik = $this->uruchomKrokTestow(['KUKING_TESTY_ROWNOLEGLE' => '6'], zParatestem: false);

        // Cicha ucieczka do szeregowych byłaby najgorsza z możliwych: bramka
        // trwałaby trzy razy dłużej, nikt by nie wiedział czemu, a przyczyną
        // byłby brakujący pakiet.
        $this->assertStringContainsString(
            'paratest',
            $wynik['bledy'],
            'Bramka nie powiedziała, że brakuje vendor/bin/paratest, choć poproszono ją '.
            'o równoległą baterię. Milczenie tutaj to trzykrotnie dłuższa bramka bez podanej przyczyny.',
        );
    }
}
