const { Icon, Wordmark, Button } = window.KukingPlSystemProjektowy_a664d7;

/* „O Kuking”. Jedyne miejsce w produkcie, w którym wolno opowiedzieć o koronie —
   i jedyne, w którym gra słowem kuKING pada jako wyjaśnienie, a nie żart. */
const CZEGO_NIE_MA = [
  ["Rankingów", "Publiczne rankingi natychmiast dzielą ludzi na dwie klasy i wyłączają publikowanie u większości."],
  ["Punktów, poziomów i odznak", "Mechaniki, które nagradzają częstość, produkują wstyd u wszystkich, którzy nie gotują codziennie."],
  ["Algorytmu, który wybiera za Ciebie", "Widzisz to, co gotują osoby, które obserwujesz. W kolejności, w jakiej to dodali."],
  ["Reklam między daniami", "Nie zarabiamy na tym, ile czasu tu spędzisz, więc nic tu nie miga i nic nie przewija się samo."],
];

const CO_ZAMIAST = [
  ["Zeszyt", "Przepis, który chcesz zachować, trafia do Twojego Zeszytu i zawsze go tam znajdziesz. Nazywa się Zeszytem, bo dokładnie tam większość z nas trzyma przepisy do dzisiaj."],
  ["Ugotowałem", "Jedno zdjęcie od kogoś, komu przepis wyszedł, mówi więcej niż tysiąc serduszek pod cudzym zdjęciem z internetu."],
  ["Skąd ten przepis", "Miejsce na to, po kim jest przepis i skąd się wziął. To najczęściej czytana część przepisu."],
  ["Wielkość tekstu", "Tekst powiększa się na stałe, w ustawieniach konta, i wtedy jest większy na każdej stronie i na każdym urządzeniu."],
];

function OKuking({ onPowitalna, onPrzepis, stopka }) {
  return (
    <div className="strona">
      <window.BelkaGoscia />
      <main className="tresc" id="tresc">
        <section className="pas">
          <div className="pas-wnetrze">
            <h1 className="text-title-xl">Prowadzimy to na własną rękę.</h1>
            <div className="tekst-czytany odstep-nad">
              <p>
                Kuking robi jedna osoba, która gotuje w domu i miała dość przepisów, do których
                trzeba się przekopać przez pół strony cudzych wspomnień i trzy reklamy.
              </p>
              <p>
                Nie stoi za tym żadna większa firma. Nie ma tu działu, do którego można napisać —
                wiadomość czyta ten sam człowiek, który to pisze i utrzymuje. Dlatego odpowiedź
                czasem przychodzi następnego dnia, i to jest uczciwsze, niż udawać całodobową obsługę.
              </p>
            </div>
          </div>
        </section>

        <section className="pas pas--wglebiony">
          <div className="pas-wnetrze">
            <h2 className="text-title-lg">Przepisy giną razem z zeszytami.</h2>
            <div className="tekst-czytany odstep-nad">
              <p>
                Najlepsze przepisy w Polsce leżą w kuchennych szufladach: w zeszytach w kratkę,
                na kartkach z kalendarza, na odwrocie rachunku. Kiedy nie ma ich kto przepisać, znikają.
              </p>
              <p>
                W Kuking można spisać taki przepis, dołożyć do niego zdjęcie kartki pisanej ręką babci
                i nazwisko osoby, po której się go ma. A potem ktoś z niego ugotuje i pokaże, jak wyszło —
                bo przepis, którego nikt nigdy nie ugotował, jest tylko listą składników.
              </p>
            </div>
          </div>
        </section>

        <section className="pas">
          <div className="pas-wnetrze">
            <h2 className="text-title-lg">Czego tu nie ma.</h2>
            <ul className="lista-czego-nie-ma odstep-nad">
              {CZEGO_NIE_MA.map(([t, o]) => (
                <li key={t}><strong>{t}</strong><p>{o}</p></li>
              ))}
            </ul>
          </div>
        </section>

        <section className="pas pas--cieply">
          <div className="pas-wnetrze">
            <h2 className="text-title-lg">Co jest zamiast tego.</h2>
            <div className="dwie-kolumny odstep-nad">
              {CO_ZAMIAST.map(([t, o]) => (
                <div key={t}>
                  <h3>{t}</h3>
                  <p>{o}</p>
                </div>
              ))}
            </div>
          </div>
        </section>

        <section className="pas">
          <div className="pas-wnetrze">
            <h2 className="text-title-lg">Skąd ta nazwa.</h2>
            <div className="tekst-czytany odstep-nad">
              <p>
                W znaku jest garnek, a na garnku korona. I to jest cała historia: korona siedzi na
                garnku, nie na niczyjej głowie. To żart o garnku, nie komplement dla kogokolwiek.
              </p>
              <p>
                Osobę, która tu gotuje, nazywamy kuKINGiem. To nazwa przynależności, nie tytuł,
                na który trzeba zasłużyć. Wystarczy tu być.
              </p>
            </div>
          </div>
        </section>

        <section className="pas pas--kreska-gora">
          <div className="pas-wnetrze zacheta">
            <h2 className="text-title-lg">Jesteśmy przed startem.</h2>
            <p className="lead zacheta-tekst">
              Zapraszamy po kilka osób naraz, zaczynając od dwudziestu. Chcemy mieć pewność,
              że wszystko działa, zanim wejdzie więcej osób.
            </p>
            <div className="zacheta-akcje">
              <a className="btn btn-primary btn-duzy" href="mailto:kontakt@kuking.pl">Napisz do nas</a>
              <a href="#przepis" onClick={(e) => { e.preventDefault(); onPrzepis(); }}>Zobacz przykładowy przepis</a>
            </div>
          </div>
        </section>
      </main>
      {stopka}
    </div>
  );
}

Object.assign(window, { OKuking });
