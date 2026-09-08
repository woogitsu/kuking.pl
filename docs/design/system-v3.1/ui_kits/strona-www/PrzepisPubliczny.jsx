const { Icon, Avatar, EmptyState, Button, Badge } = window.KukingPlSystemProjektowy_a664d7;

/* Przepis widziany przez kogoś, kto trafił tu z wyszukiwarki i nie ma konta.
   Gość dostaje to, po co przyszedł — przepis w całości, bez zasłaniania,
   bez okna z zapisem — a zaproszenie stoi obok, nie na drodze.

   Panel ze składnikami jedzie POD przepisem na telefonie i OBOK kroków
   od 80 rem (D-102). Gra słowem „kuKING” nie pada tu wcale. */
const SKLADNIKI = [
  "3 szklanki mąki",
  "szklanka gorącej wody, może trochę więcej",
  "1 kg ziemniaków, ugotowanych dzień wcześniej",
  "40 dag twarogu półtłustego",
  "2 duże cebule",
  "sól i sporo pieprzu",
];

const KROKI = [
  "Ziemniaki ugotuj dzień wcześniej i zostaw w chłodnym miejscu. Zimne lepiej się przepuszcza.",
  "Mąkę wsyp do miski i zalej gorącą wodą. Mieszaj łyżką, dopóki nie da się dotknąć ręką.",
  "Wyrabiaj ciasto, aż przestanie kleić się do rąk. Odstaw pod ściereczką na pół godziny.",
  "Ziemniaki i twaróg przepuść przez maszynkę. Jedną cebulę zesmaż na złoto i dodaj do farszu.",
  "Farsz dopraw solą i pieprzem. Spróbuj — na zimno ma być wyraźnie za mocno.",
  "Ciasto rozwałkuj po kawałku, nie od razu całe. Wykrawaj szklanką.",
  "Lepij brzegi mocno, dwa razy. Gotowe odkładaj na ściereczkę posypaną mąką.",
  "Wrzucaj na osoloną, ledwo mrugającą wodę. Po wypłynięciu gotuj jeszcze dwie minuty.",
  "Drugą cebulę zesmaż na maśle i polej pierogi na talerzu.",
];

function PrzepisPubliczny({ onPowitalna, onOnas, stopka }) {
  return (
    <div className="strona strona--przepis">
      <window.BelkaGoscia />
      <main className="tresc" id="tresc">
        <section className="pas">
          <div className="pas-wnetrze">
            <div className="pasek-goscia">
              <p className="pasek-goscia-tekst">Czytasz przepis w Kuking. Do czytania nie trzeba konta.</p>
              <a className="link-dalej" href="#o-kuking" onClick={(e) => { e.preventDefault(); onOnas(); }}>
                Czym jest Kuking<Icon name="strzalka" className="link-dalej-ikona" />
              </a>
            </div>

            <div className="przepis-uklad">
              <article>
                <figure className="przepis-figura">
                  <img className="przepis-zdjecie" src="../../assets/photos/pierogi.png"
                    alt="Talerz pierogów polanych zesmażoną cebulką i posypanych szczypiorkiem" />
                </figure>

                <header className="przepis-glowka">
                  <h1 className="text-title-lg">Pierogi ruskie po babci Halinie</h1>
                  <div className="przepis-autor">
                    <Avatar imie="Marek" rozmiar="sm" />
                    <a className="karta-autor" href="#profil">Marek</a>
                    <Badge waga="cichy">przepis przykładowy</Badge>
                  </div>
                  <div className="dane-rzad">
                    <span className="dana-przepisu"><Icon name="zegar" className="dana-przepisu-ikona" />90 minut</span>
                    <span className="dana-przepisu"><Icon name="porcje" className="dana-przepisu-ikona" />6 porcji</span>
                    <span className="dana-przepisu"><Icon name="czapka" className="dana-przepisu-ikona" />Kuchnia domowa</span>
                  </div>
                  <p className="lead">
                    Ciasto na gorącej wodzie, farsz z ziemniaków ugotowanych dzień wcześniej i tyle pieprzu,
                    żeby było czuć. U babci stały na stole w każdą niedzielę.
                  </p>
                </header>

                <h2>Jak to zrobić</h2>
                <p className="pomoc">Jeden krok to jedna czynność — łatwiej to czytać przy garnku.</p>
                <ol className="kroki odstep-nad-maly">
                  {KROKI.map((k, i) => <li key={i}>{k}</li>)}
                </ol>

                <section className="pochodzenie odstep-nad">
                  <h2 className="pochodzenie-po-kim">Skąd ten przepis</h2>
                  <p className="pomoc">To najczęściej czytana część przepisu. Ludzie chcą wiedzieć, po kim on jest.</p>
                  <p><strong>Po babci Halinie, z Rzeszowszczyzny.</strong></p>
                  <p>
                    Babcia lepiła je na blacie posypanym mąką, bez stolnicy, i nigdy nie mierzyła niczego
                    szklanką. Mówiła, że ciasto samo powie, ile wody weźmie.
                  </p>
                  <p style={{ marginBottom: 0 }}>
                    Kartka z tym przepisem leżała dwadzieścia lat w kieszeni fartucha. Jest na niej dopisek
                    innym długopisem: „mniej pieprzu, dzieci nie lubią”.
                  </p>
                </section>

                <section className="odstep-nad">
                  <h2>Komu wyszło</h2>
                  <p className="pomoc">Zdjęcia od ludzi, którzy naprawdę to zrobili u siebie.</p>
                  <EmptyState znak="garnek" tytul="Nikt jeszcze nie pokazał, jak mu wyszło"
                    opis="Kiedy ugotujesz z tego przepisu, możesz kliknąć „Ugotowałem” i dodać zdjęcie. Marek dowie się, że ktoś naprawdę to zrobił. Zdjęcie nie musi być ładne.">
                    <a className="btn btn-secondary" href="#rejestracja">Załóż konto, żeby dodać Ugotowałem</a>
                  </EmptyState>
                </section>
              </article>

              <aside className="panel-przepisu">
                <h2 className="panel-tytul">Składniki</h2>
                <p className="pomoc">Na 6 porcji, czyli mniej więcej 50 pierogów.</p>
                <ul className="lista-skladnikow">
                  {SKLADNIKI.map((s, i) => <li key={i}>{s}</li>)}
                </ul>
                <hr style={{ border: 0, borderTop: "1px solid var(--color-border)", margin: "var(--spacing-5) 0" }} />
                <h2 className="panel-tytul">Zapisz na później</h2>
                <p className="pomoc">
                  Zeszyt to Twoje miejsce na przepisy, które chcesz zachować. Wtedy, gdy będą potrzebne,
                  i za dwa lata też.
                </p>
                <a className="btn btn-primary btn-pelny odstep-nad-maly" href="#rejestracja">Zapisz przepis w Zeszycie</a>
                <p className="pomoc" style={{ marginTop: "var(--spacing-3)" }}>
                  Konto jest darmowe. Nie pytamy o numer telefonu.
                </p>
              </aside>
            </div>
          </div>
        </section>
      </main>
      {stopka}
    </div>
  );
}

Object.assign(window, { PrzepisPubliczny });
