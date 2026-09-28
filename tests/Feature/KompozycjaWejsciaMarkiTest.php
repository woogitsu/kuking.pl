<?php

declare(strict_types=1);

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KompozycjaWejsciaMarkiTest extends TestCase
{
    use RefreshDatabase;

    public function test_obie_drogi_wejscia_maja_zaproszenie_obok_pelnego_formularza(): void
    {
        foreach (['login' => ['login', 'password'], 'register' => ['display_name', 'username', 'email', 'password', 'age_confirmed', 'terms_accepted']] as $route => $fields) {
            $document = new DOMDocument;
            @$document->loadHTML('<?xml encoding="utf-8" ?>'.$this->get(route($route))->assertOk()->getContent());
            $dom = new DOMXPath($document);
            $card = $dom->query('//*[@id="tresc"]//*[contains(concat(" ",normalize-space(@class)," ")," marka-wejscie-karta ")]');
            $this->assertSame(1, $card->length);
            $header = $dom->query('preceding-sibling::*[contains(@class,"marka-wejscie-naglowek")]', $card->item(0));
            $this->assertSame(1, $header->length);
            $this->assertSame(1, $dom->query('./h1', $header->item(0))->length);
            $this->assertSame(1, $dom->query('.//h1', $card->item(0)->parentNode)->length);
            $this->assertSame(0, $dom->query('.//h2|.//a|.//button|.//input', $header->item(0))->length);
            $aside = $dom->query('preceding-sibling::*[contains(@class,"marka-wejscie-zaproszenie")]', $card->item(0));
            $this->assertSame(1, $aside->length);
            $this->assertNotSame(0, $header->item(0)->compareDocumentPosition($aside->item(0)) & \DOMNode::DOCUMENT_POSITION_FOLLOWING);
            $this->assertSame(1, $dom->query('.//svg', $aside->item(0))->length);
            $form = $dom->query('.//form[@action="'.route($route).'" and @method="POST"]', $card->item(0));
            $this->assertSame(1, $form->length);
            foreach ([...$fields, '_token'] as $name) {
                $this->assertSame(1, $dom->query('.//input[@name="'.$name.'"]', $form->item(0))->length, $route.': '.$name);
            }
            $this->assertSame(0, $dom->query('.//form//form', $card->item(0))->length);
            $followup = $dom->query('following-sibling::*[contains(@class,"marka-wejscie-po-formularzu")]', $card->item(0));
            $this->assertSame(1, $followup->length);
            $this->assertNotSame(0, $form->item(0)->compareDocumentPosition($followup->item(0)) & \DOMNode::DOCUMENT_POSITION_FOLLOWING);
            $alternate = $route === 'login' ? 'register' : 'login';
            $this->assertSame(1, $dom->query('.//a[@href="'.route($alternate).'"]', $followup->item(0))->length);
            if ($route === 'login') {
                $this->assertSame(1, $dom->query('.//a[@href="'.route('password.request').'"]', $card->item(0))->length);
                if (config('kuking.login_link.wlaczone')) {
                    $this->assertSame(1, $dom->query('.//a[@href="'.route('login.link').'"]', $followup->item(0))->length);
                    $this->assertSame(1, $dom->query('.//div[contains(@class,"sekcja-strony")][.//a[@href="'.route('login.link').'"]]', $followup->item(0))->length);
                }
            }
        }
    }

    public function test_trzy_karty_wlasnosci_zachowuja_prawdziwe_drogi_i_informacje_o_eksporcie(): void
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$this->get('/')->assertOk()->getContent());
        $dom = new DOMXPath($document);
        $cards = $dom->query('//*[@aria-labelledby="wlasne-tresci-tytul"]//*[contains(@class,"marka-wlasnosc-karty")]/article');
        $this->assertSame(3, $cards->length);
        // #1289: karta zeszytu nie udaje, że gość zajrzy do zeszytu bez konta,
        // a karta widoczności prowadzi do publicznej Pomocy, nie za logowanie.
        $this->assertSame(0, $dom->query('.//a', $cards->item(0))->length);
        $this->assertSame(route('help').'#kto-widzi', self::elementDom($dom->query('.//a', $cards->item(1))->item(0))->getAttribute('href'));
        $this->assertStringContainsString('przygotujemy ją i damy znać', $cards->item(2)->textContent);
        $this->assertStringContainsString('Otworzysz ją na swoim komputerze', $cards->item(2)->textContent);
        $this->assertSame(0, $dom->query('.//button|.//form', $cards->item(2))->length, 'Informacja nie może udawać wykonania eksportu.');
    }
}
