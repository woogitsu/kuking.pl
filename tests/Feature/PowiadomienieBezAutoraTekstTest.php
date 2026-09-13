<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Notification;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PowiadomienieBezAutoraTekstTest extends TestCase
{
    use RefreshDatabase;

    public function test_piec_zwyklych_typow_ma_podmiot_bez_autora_i_zachowuje_prawdziwa_nazwe(): void
    {
        $recipient = $this->user('odbiorca');
        $actor = $this->user('kucharka');
        $this->actingAs($recipient);
        $types = [
            Notification::TYPE_COOKED => ' — ugotowane z Twojego przepisu',
            Notification::TYPE_COMMENT => ' — nowy komentarz.',
            Notification::TYPE_REPLY => ' — nowa odpowiedź.',
            Notification::TYPE_FOLLOW => ' zaczyna Cię obserwować.',
            Notification::TYPE_SAVED => ' ma Twój przepis',
        ];

        foreach ($types as $type => $sentence) {
            foreach ([null, $actor] as $source) {
                $notification = Notification::create([
                    'user_id' => $recipient->getKey(),
                    'actor_id' => $source?->getKey(),
                    'type' => $type,
                    'data' => ['recipe_title' => 'Przepis kontrolny'],
                ]);
                // Renderujemy właściwy Blade z pojedynczą rzeczywistą kartą.
                // Filtry widoczności mają własną regresję; tu celowo mierzymy
                // także brak relacji aktora bez zmiany reguł kontrolera.
                $notifications = Notification::query()->whereKey($notification->getKey())->with('actor.profile.avatar')->paginate(30);
                $html = view('pages.notifications', [
                    'notifications' => $notifications,
                    'decyzjeModeracyjne' => collect(),
                ])->render();
                $dom = new DOMDocument;
                @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
                $xpath = new DOMXPath($dom);
                $headings = $xpath->query('//ul[contains(concat(" ", normalize-space(@class), " "), " marka-powiadomienia ")]/li/article//strong');
                $this->assertSame(1, $headings->length, 'Kontrola musi znaleźć dokładnie nagłówek karty: '.$type);
                $this->assertSame(($source?->displayName() ?? 'Ktoś').$sentence, trim($headings->item(0)->textContent), $type.' — '.($source === null ? 'bez autora' : 'z autorem'));
            }
        }
    }
}
