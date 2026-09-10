<?php

declare(strict_types=1);

namespace App\Domain\Contact\Actions;

use RuntimeException;

/**
 * Próba odpisania na wiadomość, która nie ma adresu do odpowiedzi.
 *
 * Zdarza się to legalnie: gość może wysłać formularz bez adresu (pole jest
 * nieobowiązkowe — D-045: pisać może każdy, także bez konta), a konto autora
 * mogło zostać zanonimizowane po wysłaniu wiadomości. W obu przypadkach nie
 * ma dokąd wysłać listu i nie da się tego obejść.
 *
 * Osobny typ wyjątku, a nie `null` z akcji: `null` dałoby się przeoczyć
 * i skończyłoby się wierszem odpowiedzi bez wysyłki, czyli „wysłano" nad
 * listem, którego nikt nie dostał. Ekran i tak nie pokazuje formularza
 * odpowiedzi bez adresu, więc ten wyjątek jest obroną w głąb — nie
 * ścieżką, po której chodzą ludzie.
 */
final class BrakAdresuDoOdpowiedzi extends RuntimeException {}
