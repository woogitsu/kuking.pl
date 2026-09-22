<?php

declare(strict_types=1);

namespace App\Domain\Contact\Actions;

use App\Domain\Contact\DzwonekOperatora;
use App\Domain\Contact\PageContext;
use App\Models\ContactMessage;
use App\Models\User;
use App\Support\Wersja;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Przyjęcie wiadomości z formularza „Napisz do nas".
 *
 * KOLEJNOŚĆ JEST TU CAŁĄ TREŚCIĄ TEJ KLASY:
 *
 *   1. zapis do bazy Kuking (transakcja),
 *   2. dopiero potem dzwonek na webhook operatora.
 *
 * Odwrotna kolejność znaczyłaby, że przy awarii zapisu operator dostaje
 * powiadomienie o wiadomości, której nie ma — a to jest gorsze niż brak
 * powiadomienia, bo wygląda na czyjeś zgubione zgłoszenie. Dzwonek
 * NIGDY nie wycofuje zapisu (`DzwonekOperatora` łyka własne błędy):
 * wiadomość zapisana w Kuking jest źródłem prawdy, webhook jest wygodą.
 *
 * DLACZEGO OSOBNA AKCJA, A NIE `ContactMessage::create()` W KONTROLERZE
 * Bo dróg zapisu będzie więcej niż jedna, jak tylko dojdzie panel/dymek
 * z JavaScriptem albo formularz na stronie błędu 500 — a reguła „zapis,
 * potem dzwonek, dzwonek nigdy nie przewraca zapisu" ma obowiązywać na
 * każdej z nich. Reguła domenowa żyje w `app/Domain`, nie w kontrolerze
 * (AGENTS.md §4).
 */
final class PrzyjmijWiadomosc
{
    public function __construct(private readonly DzwonekOperatora $dzwonek) {}

    /**
     * @param  string  $rodzaj  klucz z `ContactMessage::RODZAJE`
     * @param  User|null  $autor  zalogowany albo `null` dla gościa
     * @param  string|null  $email  adres podany przez GOŚCIA; dla zalogowanego
     *                              zostaje `null` — jego adres jest na koncie
     *                              i kopiowanie go tu byłoby powielaniem danych
     *                              osobowych bez powodu (RODO, minimalizacja)
     * @param  string|null  $sciezka  ścieżka strony, z której pisano (bez domeny
     *                                i bez parametrów — przycina ją kontroler)
     * @param  string|null  $kluczWyslania  tożsamość TEGO wysłania formularza;
     *                                      `null` znaczy „nie wiemy, przyjmij
     *                                      normalnie", nigdy „odmawiam"
     */
    public function handle(
        string $rodzaj,
        string $tresc,
        ?User $autor = null,
        ?string $email = null,
        ?string $sciezka = null,
        ?string $kluczWyslania = null,
    ): ContactMessage {
        $zapisz = fn (?string $klucz): ContactMessage => DB::transaction(
            fn (): ContactMessage => ContactMessage::create([
                'user_id' => $autor?->getKey(),
                'klucz_wyslania' => $klucz,
                'kind' => $rodzaj,
                'message' => $tresc,
                'contact_email' => $autor === null ? $email : null,
                'page_path' => PageContext::clean($sciezka),
                // Wydanie serwisu w chwili wysłania — przy „coś nie działa"
                // to jest połowa diagnozy, a nie dana osobowa.
                'wydanie' => Wersja::opisWydania(),
            ]),
        );

        try {
            $wiadomosc = $zapisz($kluczWyslania);
        } catch (UniqueConstraintViolationException $e) {
            if ($kluczWyslania === null) {
                // Bez klucza nie ma jak odbić się o
                // `contact_messages_one_per_klucz_wyslania` — to inne
                // ograniczenie i nie wolno go tu wyciszyć.
                throw $e;
            }

            $istniejaca = $this->wiadomoscZTegoWyslania($kluczWyslania, $rodzaj, $tresc, $autor, $email);

            if ($istniejaca !== null) {
                // Drugie kliknięcie „Wyślij" ma być nieodróżnialne od
                // pierwszego: ten sam wiersz, ten sam ekran podziękowania
                // i ANI JEDEN dzwonek więcej. Przy jednoosobowej obsłudze
                // każda kopia to ta sama praca wykonana dwa razy.
                return $istniejaca;
            }

            // Klucz zajęty, ale nie przez tę wiadomość (ktoś podstawił cudzą
            // wartość). Przyjmujemy BEZ klucza — nigdy nie oddajemy cudzego
            // wiersza i nigdy nie odmawiamy przyjęcia (ADR §4.3).
            $wiadomosc = $zapisz(null);
        }

        $this->dzwonek->zadzwon($wiadomosc);

        return $wiadomosc;
    }

    /**
     * Wiadomość przyjęta z TEGO wysłania formularza — jeśli została przyjęta.
     *
     * Sam klucz nie wystarcza. UUID w żądaniu nie jest autoryzacją
     * (AGENTS.md §7): gdyby ktoś podstawił cudzą wartość, oddanie tamtego
     * wiersza pokazałoby mu cudzą sprawę. Dlatego wiersz musi zgadzać się
     * także treścią, autorem i adresem odpowiedzi gościa. Kontekst strony
     * jest tylko diagnostyką, nie treścią wysłania. Dla podwójnego kliknięcia jest
     * to bajt w bajt to samo żądanie.
     */
    private function wiadomoscZTegoWyslania(
        string $kluczWyslania,
        string $rodzaj,
        string $tresc,
        ?User $autor,
        ?string $email,
    ): ?ContactMessage {
        return ContactMessage::query()
            ->where('klucz_wyslania', $kluczWyslania)
            ->where('kind', $rodzaj)
            ->where('message', $tresc)
            ->when(
                $autor === null,
                static fn ($query) => $query->whereNull('user_id')->where('contact_email', $email),
                static fn ($query) => $query->where('user_id', $autor->getKey()),
            )
            ->first();
    }
}
