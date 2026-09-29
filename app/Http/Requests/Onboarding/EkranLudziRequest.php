<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Wejście ekranu „kogo obserwować" (`onboarding.people`, GET) — wyjęte
 * z `OnboardingController::people()` bez zmiany zachowania (#970).
 * Ekran budują `App\Domain\Onboarding\PrzygotujEkranLudzi`.
 *
 * `rules()` jest puste ŚWIADOMIE (tak samo jak w `ListaKontRequest`):
 * parametry przychodzą z paska adresu i z `old()` po nieudanym POST-cie,
 * a ekran ma się otworzyć zawsze. Wartość nie do odczytania znaczy to samo
 * co jej brak — o błędzie frazy mówi dopiero `SearchQuery::phraseValidator()`
 * na samym ekranie, obok pola. Odesłanie z błędem walidacji zamieniłoby
 * zapisany odnośnik albo cofnięcie się przeglądarką w przekierowanie donikąd.
 */
final class EkranLudziRequest extends FormRequest
{
    /** Ile zaznaczeń w ogóle czytamy; GET też ma granicę kosztu, niezależną od walidacji zapisu. */
    public const NAJWIECEJ_ZAZNACZEN = 50;

    /** Najdłuższa nazwa użytkownika, którą w ogóle bierzemy pod uwagę. */
    public const NAJDLUZSZA_NAZWA = 40;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Ten sam kontrakt co na `/szukaj` (issue #738): parametr GET może być
     * tablicą (`q[]=...`). Nie wolno rzutować go na tekst, bo PHP zgłasza
     * wtedy „Array to string conversion”, a ekran kończy na 500.
     * Nietekstowe `q` znaczy dokładnie to samo co brak frazy.
     */
    public function fraza(): string
    {
        $surowe = $this->query('q', '');

        return $this->boolean('clear') ? '' : trim(is_string($surowe) ? $surowe : '');
    }

    /**
     * Kontekst wyboru w sesji: para (kontekst, czy wybór z żądania jest jego).
     *
     * Wybór z adresu albo z `old()` liczy się tylko wtedy, gdy niesie
     * token bieżącego, niewygasłego kontekstu tego samego konta. Kontekst
     * wygasły lub cudzy jest wymieniany na nowy — dlatego ważność liczymy
     * PRZED wymianą.
     *
     * @return array{0: array{user: mixed, token: string, expires: int}, 1: bool}
     */
    public function kontekstWyboru(): array
    {
        $context = $this->session()->get('onboarding.selection');
        $contextValid = is_array($context)
            && ($context['user'] ?? null) === $this->user()->getKey()
            && ($context['expires'] ?? 0) > now()->getTimestamp();
        $selectionValid = $contextValid && old('selection', $this->input('selection')) === $context['token'];

        if (! $contextValid) {
            $context = ['user' => $this->user()->getKey(), 'token' => (string) Str::uuid(), 'expires' => now()->addMinutes(30)->getTimestamp()];
            $this->session()->put('onboarding.selection', $context);
        }

        return [$context, $selectionValid];
    }

    /**
     * Wcześniej zaznaczone nazwy z wejścia — po błędzie walidacji z `old()`,
     * inaczej z adresu. Bez powtórzeń, tylko teksty do 40 znaków, najwyżej
     * 50. Nadmiar pozostaje widoczny po błędzie POST, aby można go odznaczyć.
     *
     * @return list<string>
     */
    public function zaznaczoneNazwy(): array
    {
        $input = old('follow', $this->input('follow', []));

        if (! is_array($input)) {
            return [];
        }

        $nazwy = array_values(array_unique(array_filter(
            $input,
            fn ($name) => is_string($name) && strlen($name) <= self::NAJDLUZSZA_NAZWA,
        )));

        return array_slice($nazwy, 0, self::NAJWIECEJ_ZAZNACZEN);
    }

    /**
     * Para nazwa–identyfikator (#793) z TEGO SAMEGO źródła co `follow`:
     * po błędzie walidacji z `old()`, inaczej z adresu (#1340).
     *
     * @return array<string, string>
     */
    public function oczekiwani(): array
    {
        $surowi = $this->session()->hasOldInput('follow')
            ? old('oczekiwani', [])
            : $this->input('oczekiwani', []);
        $oczekiwani = [];

        foreach (is_array($surowi) ? $surowi : [] as $nazwa => $id) {
            if (is_string($id)) {
                $oczekiwani[mb_strtolower((string) $nazwa)] = $id;
            }
        }

        return $oczekiwani;
    }
}
