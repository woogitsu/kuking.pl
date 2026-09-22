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
            $this->assertSame(1, $dom->query('./h1', $card->item(0))->length);
            $this->assertSame(1, $dom->query('preceding-sibling::*[contains(@class,"marka-wejscie-zaproszenie")]//svg', $card->item(0))->length);
            $form = $dom->query('.//form[@action="'.route($route).'" and @method="POST"]', $card->item(0));
            $this->assertSame(1, $form->length);
            foreach ([...$fields, '_token'] as $name) {
                $this->assertSame(1, $dom->query('.//input[@name="'.$name.'"]', $form->item(0))->length, $route.': '.$name);
            }
            $this->assertSame(0, $dom->query('.//form//form', $card->item(0))->length);
            $alternate = $route === 'login' ? 'register' : 'login';
            $this->assertSame(1, $dom->query('.//a[@href="'.route($alternate).'"]', $card->item(0))->length);
            if ($route === 'login') {
                $this->assertSame(1, $dom->query('.//a[@href="'.route('password.request').'"]', $card->item(0))->length);
                if (config('kuking.login_link.wlaczone')) {
                    $this->assertSame(1, $dom->query('.//a[@href="'.route('login.link').'"]', $card->item(0))->length);
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
        foreach (['collections.index', 'posts.create'] as $i => $route) {
            $this->assertSame(route($route), $dom->query('.//a', $cards->item($i))->item(0)->getAttribute('href'));
        }
        $this->assertStringContainsString('przygotujemy ją i damy znać', $cards->item(2)->textContent);
        $this->assertStringContainsString('Otworzysz ją na swoim komputerze', $cards->item(2)->textContent);
        $this->assertSame(0, $dom->query('.//button|.//form', $cards->item(2))->length, 'Informacja nie może udawać wykonania eksportu.');
    }
}
