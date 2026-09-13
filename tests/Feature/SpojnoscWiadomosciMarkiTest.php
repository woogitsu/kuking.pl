<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class SpojnoscWiadomosciMarkiTest extends TestCase
{
    public function test_wiadomosci_uzywaja_obecnej_palety_i_czytelnego_pisma(): void
    {
        $widoki = [
            'data-export-ready',
            'haslo-zamiast-linku',
            'link-do-logowania',
            'nowe-haslo',
            'odpowiedz-na-wiadomosc',
            'potwierdz-adres',
            'potwierdz-nowy-adres',
            'proba-wejscia-kontem-facebooka',
            'zaproszenie-do-zalozenia-konta',
            'zgloszona-zmiana-adresu',
        ];

        foreach ($widoki as $widok) {
            $html = view('mail.'.$widok, $this->dane())->render();
            $this->assertStringContainsString('<body', $html, $widok);
            $this->assertStringContainsString('#F3F4F1', $html, $widok);
            $this->assertStringContainsString('Arial,Helvetica,sans-serif', $html, $widok);
            $this->assertStringNotContainsString('odpisuje człowiek', mb_strtolower(strip_tags($html)), $widok);
            $this->assertStringNotContainsString('przeczyta ją człowiek', mb_strtolower(strip_tags($html)), $widok);
            foreach (['#FAF6F0', '#E4DACB', '#2B241D', '#5C5347', '#B3401F', '#2F6B3A', 'Georgia'] as $staryStyl) {
                $this->assertStringNotContainsString($staryStyl, $html, $widok);
            }
        }
    }

    public function test_listy_logowania_nie_obiecuja_wylacznego_dostepu_przez_skrzynke(): void
    {
        foreach (['link-do-logowania', 'nowe-haslo'] as $widok) {
            $html = view('mail.'.$widok, $this->dane())->render();
            $tekst = (string) preg_replace('/\\s+/u', ' ', strip_tags($html));
            $this->assertStringContainsString('ten link niedługo przestanie działać', $tekst);
            $this->assertStringContainsString('href="https://kuking.test/testowy-link"', $html);
            $this->assertStringNotContainsString('Nikt bez dostępu do tej skrzynki', $tekst);
            $this->assertStringNotContainsString('odpisuje człowiek', $tekst);
        }

        $html = view('mail.link-do-logowania', $this->dane())->render();
        $this->assertStringContainsString('Kliknij „Zaloguj mnie”, żeby wejść na konto.', $html);
    }

    private function dane(): array
    {
        return [
            'displayName' => 'Anna',
            'linkUrl' => 'https://kuking.test/testowy-link',
            'downloadUrl' => 'https://kuking.test/paczka',
            'expiresAt' => now()->addDay(),
            'sizeText' => '2 MB',
            'waznoscTekst' => '15 minut',
            'waznyDo' => now()->addDay(),
            'napisanaKiedy' => now(),
            'rodzaj' => 'Pomysł',
            'tresc' => 'Dziękujemy za wiadomość.',
            'adresKontaktowy' => 'kontakt@kuking.test',
            'linkLogowania' => 'https://kuking.test/login',
            'nowyAdresSkrot' => 'a***@example.test',
            'linkHaslo' => 'https://kuking.test/haslo',
        ];
    }
}
