<?php

declare(strict_types=1);

namespace App\Domain\Tags;

use App\Models\Tag;
use Normalizer;

final class InlineTagTokens
{
    /** @return list<string> Tokeny, nie identyfikatory ani nazwy kanoniczne. */
    public function fromBody(?string $body): array
    {
        $body = Normalizer::normalize($body ?? '', Normalizer::FORM_C) ?: ($body ?? '');
        // Początek słowa po odstępie lub otwarciu nawiasu/cudzysłowu.
        // Nie rozpoznajemy fragmentu URL ani # wewnątrz słowa.
        preg_match_all('/(?:^|[\s(\[{"\'«„])#([\p{L}\p{N}\p{M}_-]+)/u', $body, $matches);
        $tokens = [];
        foreach ($matches[1] as $raw) {
            $token = Tag::znormalizujNazwe($raw);
            // Istniejący slug może mieć do 40 znaków; nowa nazwa ma osobny
            // limit w resolverze. Cały błędny token pomijamy, bez ucinania.
            if (mb_strlen($token) > 40 || ! preg_match('/^[\p{L}\p{N}][\p{L}\p{N}-]+$/uD', $token)) {
                continue;
            }
            $tokens[$token] = $token;
        }

        return array_values($tokens);
    }
}
