<x-layout title="Zaproszenie nieaktualne" :noindex="true">
    {{-- Zły, zużyty, odwołany i przeterminowany link wyglądają tak samo —
         nie mówimy, który z tych przypadków zaszedł (#1743). --}}
    <div class="marka-zeszyt">
    <h1>To zaproszenie jest już nieaktualne</h1>
    <p>Link działa raz i przez kilka dni. Mógł wygasnąć, zostać odwołany albo ktoś już z niego skorzystał.
        Poproś osobę, która go wysłała, o nowy.</p>
    <a class="btn btn-primary" href="{{ route('collections.index') }}">Przejdź do zeszytów</a>
    </div>
</x-layout>
