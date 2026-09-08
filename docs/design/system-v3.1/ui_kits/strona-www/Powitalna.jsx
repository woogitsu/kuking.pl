const { Icon, Wordmark, PostCard, Badge, Button, Avatar } = window.KukingPlSystemProjektowy_a664d7;

/* Strona powitalna. Rytm robią PASY na pełną szerokość okna, nie siatka
   trzykolumnowa: jasny — wgłębiony — jasny — CIEMNY — ciepły — jasny.
   Ciemny pas wypada w połowie i jest jedynym mocnym akcentem: dostaje go ta
   rzecz, której nie ma nikt inny.

   Belka górna NIE jest przyklejona: na stronie sprzedażowej zabierałaby wiersz
   tekstu przy każdym przewinięciu, a przy skali 150% dwa — i pokazywałaby grę
   słowem „kuKING” na każdym ekranie.

   Wszystkie napisy pochodzą z COPY_STYLE.md i BRAND_EXTENDED.md; źródło każdego
   zdania jest wypisane w 04-strona-www/STRONA-WWW.md §6.1. */
const RZECZY = [
  ["aparat", "Pokazujesz, co ugotowałeś", "Zdjęcie i kilka słów. Nie musi być ładne — ma być prawdziwe."],
  ["zeszyt", "Trzymasz przepisy w Zeszycie", "Przepis, który chcesz zachować, trafia do Twojego Zeszytu i zawsze go tam znajdziesz."],
  ["garnek", "Mówisz, że ugotowałeś", "Kiedy ugotujesz z czyjegoś przepisu, autor się o tym dowie. To jest tutaj najmilsza rzecz."],
  ["osoba", "Obserwujesz, kogo chcesz", "Widzisz to, co gotują osoby, które obserwujesz. W kolejności, w jakiej to dodali."],
];

function BelkaGoscia({ onPrzepis }) {
  return (
    <>
      <a className="tylko-dla-czytnika" href="#tresc">Przejdź do treści</a>
      <header className="pas pas-gorny" style={{ paddingBlock: 0 }}>
        <div className="pas-wnetrze pas-gorny-wnetrze">
          <Wordmark href="#powitalna" />
          <div className="pas-gorny-akcje">
            <a className="btn btn-quiet" href="#logowanie">Zaloguj</a>
            <a className="btn btn-primary" href="#rejestracja">Zostań kuKINGiem</a>
          </div>
        </div>
      </header>
    </>
  );
}

function Powitalna({ onPrzepis, onOnas, stopka }) {
  return (
    <div className="strona">
      <BelkaGoscia />
      <main className="tresc" id="tresc">
        <section className="pas">
          <div className="pas-wnetrze hero">
            <div className="hero-tekst">
              <p className="nadtytul">Gotujemy po swojemu.</p>
              <h1 className="hero-tytul text-title-xl">Pokaż, co dziś ugotowałeś.</h1>
              <p className="lead hero-lead miara">
                Kuking to twój zeszyt z przepisami i ludzie, którzy naprawdę gotują.
                Zdjęcie i kilka słów — tyle wystarczy, żeby zacząć.
              </p>
              <div className="hero-akcje">
                <a className="btn btn-primary btn-duzy" href="#rejestracja">Załóż konto — to darmowe</a>
                <a href="#przepis" onClick={(e) => { e.preventDefault(); onPrzepis(); }}>Najpierw się rozejrzę</a>
              </div>
            </div>
            <figure className="hero-figura">
              <img className="hero-zdjecie" src="../../assets/photos/pierogi.png"
                alt="Talerz pierogów polanych zesmażoną cebulką i posypanych szczypiorkiem" />
              <figcaption className="hero-podpis">Pierogi ruskie na niedzielę, zdjęcie z kuchennego stołu.</figcaption>
            </figure>
          </div>
        </section>

        <section className="pas pas--wglebiony">
          <div className="pas-wnetrze">
            <h2 className="text-title-lg">Cztery rzeczy i nic więcej.</h2>
            <p className="lead miara">Kuking robi cztery rzeczy porządnie i nie próbuje robić trzynastej.</p>
            <ul className="rzeczy odstep-nad">
              {RZECZY.map(([ik, t, o]) => (
                <li className="rzecz" key={t}>
                  <Icon name={ik} className="rzecz-ikona" />
                  <p className="rzecz-tytul">{t}</p>
                  <p className="rzecz-opis">{o}</p>
                </li>
              ))}
            </ul>
            <p className="odstep-nad">
              <a className="link-dalej" href="#o-kuking" onClick={(e) => { e.preventDefault(); onOnas(); }}>
                Czego tu nie ma<Icon name="strzalka" className="link-dalej-ikona" />
              </a>
            </p>
          </div>
        </section>

        <section className="pas">
          <div className="pas-wnetrze">
            <h2 className="text-title-lg">Tak to wygląda w środku.</h2>
            <p className="lead miara">Po zalogowaniu widzisz dania i przepisy. Dokładnie takie jak te dwa poniżej.</p>
            <div className="para odstep-nad">
              <PostCard
                autor="Kasia" autorHref="#profil" avatarSrc="../../assets/photos/avatar_kasia.png"
                czas="wczoraj, 19:40" plakietka={<Badge waga="cichy">przykład</Badge>}
                tytul="Pizza w piątek, bo tak wyszło"
                tresc="Ciasto stało od rana pod ścierką. Bazylia z parapetu, reszta z lodówki."
                zdjecie="../../assets/photos/pizza.png"
                alt="Pizza z mozzarellą, pomidorkami i świeżą bazylią na drewnianej desce"
              />
              <article className="karta-przepisu-przyklad">
                <div className="karta-przepisu-naglowek">
                  <h3 style={{ margin: 0 }}>Pierogi ruskie po babci Halinie</h3>
                  <div className="dane-rzad" style={{ marginTop: "var(--spacing-3)", marginBottom: 0 }}>
                    <span className="dana-przepisu"><Icon name="zegar" className="dana-przepisu-ikona" />90 minut</span>
                    <span className="dana-przepisu"><Icon name="porcje" className="dana-przepisu-ikona" />6 porcji</span>
                  </div>
                </div>
                <div className="karta-przepisu-tresc">
                  <p className="pomoc">Składniki pisze się tak, jak się mówi w kuchni.</p>
                  <ul className="lista-skladnikow">
                    <li>3 szklanki mąki</li>
                    <li>szklanka gorącej wody, może trochę więcej</li>
                    <li>1 kg ziemniaków, ugotowanych dzień wcześniej</li>
                    <li>40 dag twarogu półtłustego</li>
                    <li>2 duże cebule</li>
                  </ul>
                  <a className="link-dalej" href="#przepis" onClick={(e) => { e.preventDefault(); onPrzepis(); }}>
                    Zobacz cały przepis<Icon name="strzalka" className="link-dalej-ikona" />
                  </a>
                </div>
              </article>
            </div>
            <p className="podpis-przykladu">
              To są przykłady. Prawdziwe dania i przepisy dodają tu ludzie, a Kuking jest przed startem.
            </p>
          </div>
        </section>

        <section className="pas blok-ciemny">
          <div className="pas-wnetrze pas-ciemny-uklad">
            <div className="pas-ciemny-tekst">
              <h2 className="text-title-lg">Przepis jest dobry, kiedy ktoś go ugotował.</h2>
              <p className="lead">
                Pod każdym przepisem jest przycisk „Ugotowałem”. Kiedy go klikniesz i dodasz zdjęcie,
                autor przepisu dowie się, że ktoś naprawdę zrobił to u siebie w kuchni.
              </p>
              <p>
                Dlatego przy przepisie widać nie liczbę serduszek, tylko zdjęcia od ludzi, którym wyszedł.
                Tu nie ma rankingów. Nie ma kogo wyprzedzać.
              </p>
              <p>
                <a className="link-dalej" href="#przepis" onClick={(e) => { e.preventDefault(); onPrzepis(); }}>
                  Zobacz to na przepisie<Icon name="strzalka" className="link-dalej-ikona" />
                </a>
              </p>
            </div>
            <blockquote className="cytat-ugotowalem">
              Halina ugotowała Twój rosół.
              <span className="cytat-zrodlo">Tak wygląda powiadomienie, na które się tutaj czeka.</span>
            </blockquote>
          </div>
        </section>

        <section className="pas pas--cieply">
          <div className="pas-wnetrze">
            <h2 className="text-title-lg">Zabierzesz stąd wszystko, co dodasz.</h2>
            <div className="dwie-kolumny odstep-nad">
              <div>
                <p>
                  W każdej chwili możesz pobrać paczkę ze swoimi zdjęciami, wpisami i przepisami.
                  Otworzysz ją na swoim komputerze — także wtedy, gdyby Kuking kiedyś przestał istnieć.
                </p>
                <p>
                  Przy każdym wpisie sam decydujesz, kto go widzi: wszyscy, tylko obserwujący albo tylko Ty.
                  Zeszyt jest prywatny, dopóki sam nie postanowisz inaczej.
                </p>
              </div>
              <div>
                <p><strong>Prowadzimy to na własną rękę.</strong></p>
                <p>
                  Nie ma tu reklam między daniami ani firmy, która czeka na Twoje dane — jest strona,
                  konto i przepisy.
                </p>
                <p>
                  <a className="link-dalej" href="#o-kuking" onClick={(e) => { e.preventDefault(); onOnas(); }}>
                    Kto to prowadzi<Icon name="strzalka" className="link-dalej-ikona" />
                  </a>
                </p>
              </div>
            </div>
          </div>
        </section>

        <section className="pas pas--kreska-gora">
          <div className="pas-wnetrze zacheta">
            <h2 className="text-title-lg">Załóż konto. Zajmie minutę.</h2>
            <p className="lead zacheta-tekst">Cztery pola i gotowe. Nie pytamy o numer telefonu ani o datę urodzenia.</p>
            <div className="zacheta-akcje">
              <a className="btn btn-primary btn-duzy" href="#rejestracja">Załóż konto</a>
              <span className="pomoc">Jeśli masz już konto — <a href="#logowanie">zaloguj się</a>.</span>
            </div>
          </div>
        </section>
      </main>
      {stopka}
    </div>
  );
}

Object.assign(window, { Powitalna, BelkaGoscia });
