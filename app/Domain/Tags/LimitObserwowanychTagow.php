<?php

declare(strict_types=1);

namespace App\Domain\Tags;

use Illuminate\Validation\ValidationException;

/**
 * Odmowa dodania obserwowanego tagu ponad `LimityTagow::maksObserwowanych()` (#2326).
 *
 * Osobna klasa, bo przycisk „Obserwuj ten tag” zamienia każdą inną odmowę
 * walidacji na „Tego tagu nie da się już obserwować” — a tu tag jest
 * w porządku, tylko lista jest pełna, i człowiek musi się dowiedzieć, co
 * zrobić. Formularze (krok powitalny, „Twoje tagi”) dostają ją jak zwykłą
 * odmowę walidacji pola `tags`: wracają z wpisanymi danymi.
 */
final class LimitObserwowanychTagow extends ValidationException {}
