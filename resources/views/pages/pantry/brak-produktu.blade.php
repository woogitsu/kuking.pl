{{--
    Produkt, o który chodziło, już nie istnieje na liście (#1903) — usunięty
    w innym oknie albo równolegle z edycją terminu. Status 404, komunikat po
    polsku i jedna droga dalej.
--}}
<x-layout title="Tego produktu już nie ma na liście" :noindex="true">
    <h1>Tego produktu już nie ma na liście</h1>

    <p>Został usunięty albo lista jest już inna. Nic się nie zepsuło — wróć do listy i wybierz produkt jeszcze raz.</p>

    <p><a class="btn btn-primary" href="{{ route('pantry.index') }}">Wróć do listy „Co mam w domu”</a></p>
</x-layout>
