<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Gotowanie;

use RuntimeException;

/** Dopisek z gotowania zmieniono (na innym urządzeniu) po tym, jak strona go widziała (#2587). */
class KonfliktDopisku extends RuntimeException {}
