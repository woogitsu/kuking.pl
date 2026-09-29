<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Exceptions\Handler;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

/**
 * Identyfikatory zadania kolejki w kontekście zgłaszanego błędu (#1731).
 *
 * Do tej poprawki `CorrelationServiceProvider::boot()` brał z kontenera
 * `ExceptionHandler` i wieszał hook tylko wtedy, gdy wynik był `instanceof
 * Handler`. W konsoli poza testami (`queue:work` na maszynie z Collision)
 * kontener oddaje handler OWINIĘTY przez Collision — warunek był fałszywy,
 * a hook po cichu nie powstawał, więc błędy z workera traciły `job_id`
 * i `attempt_id`. Produkcja (bez pakietów dev) była cała, dlatego nikt tego
 * nie zauważył; wykrył to PHPStan poziomu 4 (`instanceof.alwaysFalse`).
 *
 * Teraz hook rejestruje `withExceptions()` w `bootstrap/app.php` na SAMYM
 * `Handler`, w chwili jego tworzenia — niezależnie od tego, co potem
 * z nim zrobi kontener. Ten test buduje świeży `Handler` (tak jak Collision
 * buduje własnego pod spodem) i sprawdza, że hook już na nim jest.
 */
final class KontekstBleduKolejkiNieZalezyOdOwinieciaHandleraTest extends TestCase
{
    public function test_swiezy_handler_ma_hook_korelacji_kolejki(): void
    {
        $handler = $this->app->make(Handler::class);

        $hooki = (new ReflectionProperty(Handler::class, 'contextCallbacks'))->getValue($handler);

        $this->assertIsArray($hooki);
        $this->assertNotSame([], $hooki, 'Handler nie ma hooka kontekstu — błędy z kolejki zgubią job_id i attempt_id.');

        foreach ($hooki as $hook) {
            $this->assertIsCallable($hook);
            $this->assertIsArray($hook(new RuntimeException('próba'), []));
        }
    }
}
