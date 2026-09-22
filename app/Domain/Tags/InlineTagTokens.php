<?php

declare(strict_types=1);

namespace App\Domain\Tags;

use App\Models\Tag;
use Normalizer;

final class InlineTagTokens
{
    /**
     * JEDNO wyrażenie dla ZAPISU i dla RENDEROWANIA (issue #737).
     *
     * Do 19 września 2026 czytał je wyłącznie `ResolvePostTags`. Wtedy
     * `#tag` w treści przestał być odnośnikiem i pierwszym odruchem było
     * napisanie drugiego wyrażenia w widoku. Drugie wyrażenie rozjechałoby
     * się z tym przy pierwszej zmianie i dałoby najgorszy z możliwych
     * skutków: odnośnik do strony, której nie ma, albo tag przypięty do
     * wpisu bez odnośnika w treści. Stąd `wTekscie()` niżej — renderowanie
     * pyta o TO SAMO dopasowanie, tylko dodatkowo o jego położenie.
     *
     * Początek słowa po odstępie lub otwarciu nawiasu/cudzysłowu.
     * Nie rozpoznajemy fragmentu URL ani `#` wewnątrz słowa.
     */
    private const WZORZEC = '/(?:^|[\s(\[{"\'«„])#([\p{L}\p{N}\p{M}_-]+)/u';

    /** @return list<string> Tokeny, nie identyfikatory ani nazwy kanoniczne. */
    public function fromBody(?string $body): array
    {
        $body = Normalizer::normalize($body ?? '', Normalizer::FORM_C) ?: ($body ?? '');

        $tokens = [];
        foreach ($this->wTekscie($body) as $trafienie) {
            $tokens[$trafienie['token']] = $trafienie['token'];
        }

        return array_values($tokens);
    }

    /**
     * Te same tokeny, ale z położeniem W BAJTACH w PODANYM tekście — po to,
     * żeby widok mógł zamienić `#tag` na odnośnik, nie ruszając ani jednego
     * znaku obok.
     *
     * DLACZEGO BEZ `Normalizer` NA CAŁYM TEKŚCIE, W ODRÓŻNIENIU OD `fromBody`
     * NFC potrafi zmienić długość ciągu, a wtedy zwrócone przesunięcia nie
     * pasowałyby do tekstu, który widok naprawdę renderuje. Wynik jest ten
     * sam: klasa znaku `\p{M}` łapie znaki łączące w postaci rozłożonej,
     * a `Tag::znormalizujNazwe()` robi NFC na samym tokenie.
     *
     * @return list<array{token: string, surowy: string, offset: int, dlugosc: int}>
     *                                                                               `offset`/`dlugosc` obejmują znak `#`.
     */
    public function wTekscie(string $tekst): array
    {
        preg_match_all(self::WZORZEC, $tekst, $matches, PREG_OFFSET_CAPTURE);

        $trafienia = [];
        foreach ($matches[1] as [$surowy, $offset]) {
            $token = Tag::znormalizujNazwe($surowy);
            // Istniejący slug może mieć do 40 znaków; nowa nazwa ma osobny
            // limit w resolverze. Cały błędny token pomijamy, bez ucinania.
            if (mb_strlen($token) > 40 || ! preg_match('/^[\p{L}\p{N}][\p{L}\p{N}-]+$/uD', $token)) {
                continue;
            }

            // `#` to jeden bajt ASCII stojący zawsze tuż przed grupą 1.
            $trafienia[] = [
                'token' => $token,
                'surowy' => $surowy,
                'offset' => $offset - 1,
                'dlugosc' => strlen($surowy) + 1,
            ];
        }

        return $trafienia;
    }
}
