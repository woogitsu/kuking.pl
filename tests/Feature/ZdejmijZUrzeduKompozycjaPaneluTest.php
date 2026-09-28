<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Post;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Ekran „Zdejmij z urzędu” w kompozycji panelu moderacji (#581).
 *
 * Ekran powstał po porcie panelu do marki (#587) i jako jedyny w `/admin`
 * nie miał żadnej warstwy powierzchni: pola stały gołe na tle ramy. Test
 * pilnuje trzech rzeczy naraz, bo każda osobno dałaby się zepsuć w ciszy:
 *
 *  1. formularz jest panelem formularza (rola 2, `docs/design/ROLE_KART.md`)
 *     i leży w ramie panelu (`data-marka-panel`, `.marka-panel-tresc`);
 *  2. przycisk usuwający zostaje odsunięty kreską `.danger-zone` w środku
 *     panelu, a zwykłe „Wróć do treści” stoi POZA panelem;
 *  3. po nieudanej walidacji błąd i wpisane dane zostają w tym samym panelu.
 *
 * Kontrole ujemne (zepsuć → test oblewa → przywrócić), wykonane przy
 * zmianie: zdjęcie `panel-formularza` z `<form>` oblewa test 1; przeniesienie
 * przycisku poza `.danger-zone` oblewa test 1; wstawienie „Wróć do treści”
 * do formularza oblewa test 1.
 */
class ZdejmijZUrzeduKompozycjaPaneluTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_formularz_jest_panelem_w_ramie_a_akcja_destrukcyjna_jest_odsunieta(): void
    {
        $post = Post::factory()->create(['author_id' => $this->user('autor581')->getKey()]);

        $html = $this->actingAs($this->moderator())
            ->get(route('admin.z-urzedu.create', ['typ' => 'post', 'id' => $post->getKey()]))
            ->assertOk()
            ->getContent();

        $xpath = $this->xpath($html);

        // Rama panelu — bez niej klasa powierzchni nie dostaje reguł z
        // `marka-panel.css` i test niżej mierzyłby pustą nazwę.
        $this->assertSame(1, $xpath->query('//*[@data-marka-panel]//*[contains(concat(" ", normalize-space(@class), " "), " marka-panel-tresc ")]')->length,
            'Ekran nie leży w ramie panelu moderacji.');

        $panele = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " panel-formularza ")]');
        $this->assertSame(1, $panele->length, 'Na ekranie ma być dokładnie jeden panel formularza — jedyna rzecz do wypełnienia.');

        $panel = $panele->item(0);
        $this->assertInstanceOf(DOMElement::class, $panel);
        $this->assertSame('form', $panel->nodeName, 'Panel formularza ma siedzieć na samym <form>, jak w admin/tag-promotions.');
        $this->assertSame(
            route('admin.z-urzedu.store', ['typ' => 'post', 'id' => $post->getKey()]),
            $panel->getAttribute('action'),
        );

        // Pola decyzji są w panelu.
        foreach (['reason_code', 'user_message', 'note'] as $pole) {
            $this->assertSame(1, $xpath->query('.//*[@name="'.$pole.'"]', $panel)->length, "Pole {$pole} wypadło z panelu formularza.");
        }

        // Przycisk usuwający: w panelu, ale za kreską `.danger-zone`.
        $przycisk = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " danger-zone ")]//button[@type="submit"]', $panel);
        $this->assertSame(1, $przycisk->length, 'Przycisk „Zdejmij tę treść” nie stoi za kreską .danger-zone w panelu.');
        $this->assertStringContainsString('Zdejmij tę treść', $przycisk->item(0)->textContent);
        $this->assertStringContainsString('btn-danger', $przycisk->item(0)->getAttribute('class'));

        // Zwykła akcja nie siedzi obok destrukcyjnej.
        $powrot = $xpath->query('//a[normalize-space(.)="Wróć do treści"]');
        $this->assertSame(1, $powrot->length, 'Brak odnośnika „Wróć do treści”.');
        $this->assertSame(0, $xpath->query('.//a[normalize-space(.)="Wróć do treści"]', $panel)->length,
            '„Wróć do treści” wszedł do panelu obok przycisku usuwającego.');
    }

    public function test_po_bledzie_komunikat_i_wpisane_dane_zostaja_w_panelu(): void
    {
        $moderator = $this->moderator();
        $post = Post::factory()->create(['author_id' => $this->user('autor581b')->getKey()]);
        $formularz = route('admin.z-urzedu.create', ['typ' => 'post', 'id' => $post->getKey()]);

        $html = $this->actingAs($moderator)
            ->from($formularz)
            ->followingRedirects()
            ->post(route('admin.z-urzedu.store', ['typ' => 'post', 'id' => $post->getKey()]), [
                'reason_code' => '',
                'user_message' => 'Uzasadnienie wpisane przed błędem 581.',
            ])
            ->assertOk()
            ->getContent();
        $xpath = $this->xpath($html);

        $panel = $xpath->query('//form[contains(concat(" ", normalize-space(@class), " "), " panel-formularza ")]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $panel);

        $this->assertSame(1, $xpath->query('.//*[@id="f-reason_code-error"]', $panel)->length, 'Błąd podstawy nie stoi przy polu w panelu.');
        $this->assertSame(1, $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " error-summary ")]')->length, 'Brak podsumowania błędów nad formularzem.');
        $this->assertStringContainsString('Uzasadnienie wpisane przed błędem 581.', $xpath->query('.//textarea[@name="user_message"]', $panel)->item(0)?->textContent ?? '');
    }

    public function test_zdjeta_tresc_nie_pokazuje_pustego_panelu(): void
    {
        // Komentarz usunięty przez autora: wiersz zostaje, treści już nie ma.
        $komentarz = Comment::factory()->create([
            'author_id' => $this->user('autor581c')->getKey(),
            'post_id' => Post::factory()->create()->getKey(),
        ]);
        $komentarz->forceFill(['body_removed_at' => now()])->save();

        $html = $this->actingAs($this->moderator())
            ->get(route('admin.z-urzedu.create', ['typ' => 'comment', 'id' => $komentarz->getKey()]))
            ->assertOk()
            ->assertSee('Ta treść jest już zdjęta z serwisu')
            ->getContent();

        // Mocna obwódka obiecywałaby formularz, którego tu nie ma (ROLE_KART.md,
        // ten sam powód co warunkowy panel w `admin/wiadomosc`).
        $this->assertSame(0, $this->xpath($html)->query('//*[contains(concat(" ", normalize-space(@class), " "), " panel-formularza ")]')->length);
    }

    private function xpath(string $html): DOMXPath
    {
        $dokument = new DOMDocument;
        @$dokument->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new DOMXPath($dokument);
    }
}
