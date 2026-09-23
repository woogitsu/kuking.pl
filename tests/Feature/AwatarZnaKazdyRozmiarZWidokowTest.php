<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Awatar zna każdy rozmiar, o który proszą widoki.
 *
 * USTERKA: `x-avatar` dostaje rozmiar liczbą (`:size="64"`), a `app.css`
 * miał reguły tylko dla ośmiu wartości. W widokach stały dwie, których na tej
 * liście nie było: 32 (odpowiedź w wątku komentarzy — czyli KAŻDA odpowiedź)
 * i 64 (`/ustawienia/profil`, panel moderacji).
 *
 * Komentarz nad listą obiecywał, że „rozmiar spoza listy zostaje przy
 * domyślnych 48 px z `.avatar`". Ta obietnica była nieprawdziwa: reguła
 * `.avatar` nie miała ŻADNEGO `width` ani `height`. Wariant ze zdjęciem
 * ratowały atrybuty `width`/`height` w HTML-u; wariant z inicjałem
 * (`<span class="avatar">`, czyli każdy, kto nie ma jeszcze zdjęcia
 * profilowego) zwijał się do rozmiaru jednej litery — najlepiej widoczne
 * u nowego konta, na własnym profilu, przy pierwszym zetknięciu z Kuking.
 *
 * Test pilnuje obu stron tej obietnicy: że lista pokrywa wszystkie rozmiary
 * z widoków ORAZ że domyślny rozmiar naprawdę stoi w arkuszu.
 */
class AwatarZnaKazdyRozmiarZWidokowTest extends TestCase
{
    private function arkusz(): string
    {
        return (string) file_get_contents(resource_path('css/app.css'));
    }

    /**
     * @return list<string>
     */
    private function rozmiaryZWidokow(): array
    {
        $rozmiary = [];

        $katalog = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views')),
        );

        foreach ($katalog as $plik) {
            if (! $plik->isFile() || ! str_ends_with($plik->getFilename(), '.blade.php')) {
                continue;
            }

            $tresc = (string) file_get_contents($plik->getPathname());

            // Interesuje nas wyłącznie `:size` podany przy `x-avatar`, a nie
            // każde `:size` w repozytorium — inne komponenty mają własne skale.
            preg_match_all('/<x-avatar\b[^>]*?:size="(\d+)"/s', $tresc, $trafienia);

            foreach ($trafienia[1] as $rozmiar) {
                $rozmiary[$rozmiar] = $rozmiar;
            }
        }

        sort($rozmiary);

        return array_values($rozmiary);
    }

    public function test_kazdy_rozmiar_uzyty_w_widokach_ma_regule_w_arkuszu(): void
    {
        $arkusz = $this->arkusz();
        $rozmiary = $this->rozmiaryZWidokow();

        $this->assertNotEmpty($rozmiary, 'Nie znaleziono w widokach ani jednego `<x-avatar :size="…">`.');

        foreach ($rozmiary as $rozmiar) {
            $this->assertMatchesRegularExpression(
                "/\.avatar\[data-rozmiar='{$rozmiar}'\]\s*\{[^}]*width:\s*{$rozmiar}px/",
                $arkusz,
                "Widok prosi o awatar {$rozmiar} px, a `app.css` nie ma reguły "
                ."`.avatar[data-rozmiar='{$rozmiar}']`. Awatar z inicjałem spadnie wtedy "
                .'do rozmiaru domyślnego — u kogoś, kto nie ma jeszcze zdjęcia profilowego.',
            );
        }
    }

    public function test_awatar_bez_rozmiaru_ma_rozmiar_domyslny(): void
    {
        $arkusz = $this->arkusz();

        $this->assertSame(
            1,
            preg_match('/(?<![\w-])\.avatar\s*\{(.+?)\}/s', $arkusz, $trafienie),
            'W `app.css` nie ma reguły bazowej `.avatar`.',
        );

        foreach (['width', 'height'] as $wlasciwosc) {
            $this->assertMatchesRegularExpression(
                "/{$wlasciwosc}:\s*48px/",
                $trafienie[1],
                "Reguła `.avatar` nie ustawia `{$wlasciwosc}`. Komentarz w arkuszu obiecuje, "
                .'że rozmiar spoza listy zostaje przy domyślnych 48 px — bez tej deklaracji '
                .'obietnica jest nieprawdziwa dla awatara z inicjałem, bo `<span>` nie ma '
                .'atrybutów `width`/`height`.',
            );
        }
    }
}
