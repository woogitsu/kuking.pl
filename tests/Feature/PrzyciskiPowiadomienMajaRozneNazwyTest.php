<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Social\Actions\FollowUser;
use App\Models\Notification;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Dwa przyciski „Zobacz” muszą ujawniać w nazwie cel swoich kart (#2062). */
class PrzyciskiPowiadomienMajaRozneNazwyTest extends TestCase
{
    use RefreshDatabase;

    public function test_dwa_powiadomienia_maja_rozroznialne_nazwy_przyciskow(): void
    {
        $odbiorca = $this->user('odbiorca_powiadomien');
        $basia = $this->user('basia_powiadomienie', ['display_name' => 'Basia']);
        $jan = $this->user('jan_powiadomienie', ['display_name' => 'Jan']);

        app(FollowUser::class)->handle($basia, $odbiorca);
        app(FollowUser::class)->handle($jan, $odbiorca);

        $powiadomienia = Notification::query()
            ->where('user_id', $odbiorca->getKey())
            ->where('type', Notification::TYPE_FOLLOW)
            ->get();
        $this->assertCount(2, $powiadomienia);

        $html = $this->actingAs($odbiorca)->get(route('notifications.index'))->assertOk()->getContent();
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($document);

        $nazwy = [];
        foreach ($powiadomienia as $powiadomienie) {
            $karta = '//li[@data-klucz="powiadomienie-'.$powiadomienie->getKey().'"]';
            $buttons = $xpath->query($karta.'//form[@action="'.route('notifications.open', $powiadomienie).'"]//button');
            $this->assertSame(1, $buttons->length);
            $button = $buttons->item(0);
            $this->assertInstanceOf(DOMElement::class, $button);
            $this->assertSame('Zobacz', trim($button->textContent));

            $ids = explode(' ', trim($button->getAttribute('aria-labelledby')));
            $this->assertCount(2, $ids, 'Przycisk ma wskazać swój widoczny tekst i opis własnej karty.');
            $nazwa = '';
            foreach ($ids as $id) {
                $fragment = $xpath->query($karta.'//*[@id="'.$id.'"]');
                $this->assertSame(1, $fragment->length, 'Nazwa przycisku odsyła poza swoją kartę albo do nieistniejącego id.');
                $nazwa .= ' '.trim($fragment->item(0)->textContent);
            }
            $nazwy[] = preg_replace('/\s+/u', ' ', trim($nazwa)) ?? '';
        }

        $this->assertCount(2, array_unique($nazwy));
        $this->assertTrue(str_contains(implode(' ', $nazwy), 'Basia'));
        $this->assertTrue(str_contains(implode(' ', $nazwy), 'Jan'));
        foreach ($nazwy as $nazwa) {
            $this->assertStringStartsWith('Zobacz ', $nazwa);
        }
    }
}
