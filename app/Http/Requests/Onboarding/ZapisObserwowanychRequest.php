<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Wejście kroku „kogo obserwować" (`onboarding.people.save`) — wyjęte
 * z `OnboardingController::saveFollows()` bez zmiany zachowania (#970).
 * Samo obserwowanie robi `App\Domain\Onboarding\ObserwujWybraneOsoby`.
 *
 * `max:` NA TABLICY — TU WAŻNIEJSZE NIŻ GDZIEKOLWIEK INDZIEJ.
 *
 * To jedyne miejsce w serwisie, w którym POJEDYNCZE żądanie tworzy
 * powiadomienia u WIELU osób naraz: pętla w akcji woła `FollowUser` dla
 * każdej pozycji listy. Bez tej reguły limit zapytań na trasie
 * (`masowe_obserwowanie` w config/kuking.php) byłby ochroną tylko z nazwy —
 * pięć żądań po tysiąc nazw to pięć tysięcy powiadomień. Ekran proponuje
 * osiem osób, dwadzieścia daje zapas na zmianę tej liczby i nadal odcina
 * nadużycie.
 *
 * `oczekiwani[nazwa] => id` WALIDUJEMY TYLKO DLA ZAZNACZONYCH OSÓB
 * (issue #1600). Ekran renderuje tę parę przy KAŻDEJ widocznej osobie —
 * wcześniej wybranych, wynikach szukania i polecanych — więc po jednym
 * wyszukiwaniu z zachowanym wyborem pól technicznych bywa więcej niż 20,
 * choć zaznaczeń jest mniej. Sufit na całej tablicy odrzucał wtedy poprawny
 * wybór przez pola, których człowiek nie widzi i nie może poprawić. Po
 * odfiltrowaniu lista ma najwyżej tyle par co `follow`, więc jej granicę
 * trzyma już `follow.max`. Odfiltrowanie robi `validationData()`.
 */
final class ZapisObserwowanychRequest extends FormRequest
{
    /** Ile osób można zaobserwować jednym zapisem. */
    public const NAJWIECEJ_OSOB = 20;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Dane do walidacji: `follow` w całości, `oczekiwani` tylko dla
     * zaznaczonych nazw (patrz nagłówek klasy). Inne pola żądania nie
     * trafiają do `validated()`.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        $follow = $this->input('follow');
        $zaznaczone = [];

        foreach (is_array($follow) ? $follow : [] as $nazwa) {
            if (is_string($nazwa)) {
                $zaznaczone[mb_strtolower($nazwa)] = true;
            }
        }

        $oczekiwaniWejscie = $this->input('oczekiwani');
        $oczekiwaniZaznaczonych = is_array($oczekiwaniWejscie)
            ? array_filter(
                $oczekiwaniWejscie,
                fn ($nazwa) => isset($zaznaczone[mb_strtolower((string) $nazwa)]),
                ARRAY_FILTER_USE_KEY,
            )
            : $oczekiwaniWejscie;

        return [
            'follow' => $follow,
            'oczekiwani' => $oczekiwaniZaznaczonych,
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'follow' => ['nullable', 'array', 'max:'.self::NAJWIECEJ_OSOB],
            'follow.*' => ['string'],
            'oczekiwani' => ['nullable', 'array'],
            'oczekiwani.*' => ['string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'follow.max' => 'Zaznacz najwyżej :max osób. Odznacz pozostałe i kliknij „Dalej”.',
        ];
    }

    /**
     * Zaznaczone nazwy bez powtórzeń i bez rozróżniania wielkości liter
     * (`Profile::poNazwie()` też jej nie rozróżnia), ale z zachowaniem
     * pisowni widzianej na ekranie — komunikat cytuje nazwę człowiekowi.
     *
     * @return list<string>
     */
    public function zaznaczoneNazwy(): array
    {
        $selected = [];

        foreach ($this->validated()['follow'] ?? [] as $nazwa) {
            $selected[mb_strtolower((string) $nazwa)] ??= (string) $nazwa;
        }

        return array_values($selected);
    }

    /**
     * Para nazwa–identyfikator osoby widzianej w chwili renderowania (#793),
     * z kluczami małymi literami — inaczej para rozjeżdżałaby się na samym
     * zapisie nazwy i ochrona po cichu przestawałaby działać.
     *
     * @return array<string, string>
     */
    public function oczekiwani(): array
    {
        $oczekiwani = [];

        foreach ($this->validated()['oczekiwani'] ?? [] as $nazwa => $id) {
            $oczekiwani[mb_strtolower((string) $nazwa)] = (string) $id;
        }

        return $oczekiwani;
    }
}
