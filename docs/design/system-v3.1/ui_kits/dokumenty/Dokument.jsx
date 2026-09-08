const { DataTable, Wordmark, SiteFooter, Icon, Button } = window.KukingPlSystemProjektowy_a664d7;

/* Trzeci kształt treści z 03-szablony: długi dokument prawny.
   Kolumna czytania 38rem (~65 znaków), bo nie ma tu zdjęć, które łamałyby
   rytm. Spis treści to zwykłe kotwice — działa bez skryptu i pozwala wrócić
   przyciskiem „wstecz”.

   Tabela jest jedynym kształtem treści, którego nie da się złożyć z kart,
   i przewija się we własnym pudełku, nigdy razem ze stroną. */

const DOKUMENTY = {
  prywatnosc: {
    tytul: "Prywatność",
    lead: "Krótko: zbieramy tyle, ile trzeba, żeby serwis działał. Nic poza tym i nikomu tego nie sprzedajemy.",
    data: "Obowiązuje od 1 października 2026",
    sekcje: [
      {
        id: "co-zbieramy",
        tytul: "Co zbieramy",
        tresc: [
          "Adres e-mail, nazwę, którą sam wybierasz, i to, co dodajesz do serwisu: zdjęcia, wpisy, przepisy, komentarze.",
          "Zapisujemy też adres IP i podstawowe dane o przeglądarce. Służą do jednego: żeby ktoś nie zakładał tysiąca kont naraz.",
        ],
        tabela: {
          wstep: "Co dokładnie, po co i jak długo to trzymamy.",
          naglowki: ["Dane", "Po co", "Jak długo"],
          wiersze: [
            ["Adres e-mail", "Logowanie i odzyskanie hasła", "Do usunięcia konta"],
            ["Nazwa w serwisie", "Podpis pod tym, co dodajesz", "Do usunięcia konta"],
            ["Zdjęcia, wpisy, przepisy", "To jest treść serwisu", "Do usunięcia przez Ciebie"],
            ["Adres IP", "Zabezpieczenie przed nadużyciami", "90 dni"],
            ["Ustawienie wielkości tekstu i motywu", "Żeby serwis wyglądał tak samo na każdym urządzeniu", "Do usunięcia konta"],
          ],
        },
      },
      {
        id: "czego-nie-zbieramy",
        tytul: "Czego nie zbieramy",
        tresc: [
          "Nie pytamy o numer telefonu, datę urodzenia, płeć ani adres zamieszkania. Nie mamy ich i nie chcemy mieć.",
          "Nie ma tu narzędzi śledzących z zewnątrz: żadnego piksela, żadnej reklamy, żadnej mapy ciepła. Statystyki liczymy sami, po stronie serwera, i nie wiążemy ich z kontem.",
        ],
      },
      {
        id: "kto-to-widzi",
        tytul: "Kto widzi to, co dodajesz",
        tresc: [
          "Przy każdym wpisie i przepisie sam wybierasz, kto go widzi: wszyscy (także osoby bez konta), tylko osoby, które Cię obserwują, albo tylko Ty.",
          "Zeszyt jest prywatny zawsze, dopóki sam nie postanowisz inaczej. Nikt nie widzi, co w nim zapisujesz.",
        ],
      },
      {
        id: "zabierzesz",
        tytul: "Zabierzesz stąd wszystko",
        tresc: [
          "W ustawieniach konta jest przycisk, który przygotowuje paczkę ze wszystkim, co dodałeś: zdjęcia w oryginalnym rozmiarze, wpisy i przepisy w postaci, którą otworzysz na swoim komputerze.",
          "Konto usuwa się z tego samego miejsca. Znika po 30 dniach — przez ten czas możesz się rozmyślić, wystarczy się zalogować.",
        ],
      },
      {
        id: "kontakt",
        tytul: "Jak się z nami skontaktować",
        tresc: [
          "Napisz na kontakt@kuking.pl. Wiadomość czyta ten sam człowiek, który prowadzi serwis, więc odpowiedź czasem przychodzi następnego dnia.",
        ],
      },
    ],
  },
  regulamin: {
    tytul: "Regulamin",
    lead: "Zasady korzystania z Kuking, napisane tak, żeby dało się je przeczytać raz i zrozumieć.",
    data: "Obowiązuje od 1 października 2026",
    sekcje: [
      {
        id: "kto-moze",
        tytul: "Kto może mieć konto",
        tresc: [
          "Każda osoba pełnoletnia. Jedno konto na osobę — nie dlatego, że liczymy konta, tylko dlatego, że inaczej nie da się prowadzić rozmowy.",
        ],
      },
      {
        id: "co-mozna",
        tytul: "Co można tu dodawać",
        tresc: [
          "Zdjęcia tego, co ugotowałeś, i przepisy, z których ktoś może ugotować. Także takie po babci, przepisane z zeszytu.",
          "Jeśli przepis jest z książki albo z cudzej strony, napisz skąd. Cudzego tekstu nie wolno przepisywać w całości — własnymi słowami wolno zawsze.",
        ],
      },
      {
        id: "czego-nie-mozna",
        tytul: "Czego nie można",
        tresc: [
          "Reklamować niczego. Obrażać ludzi. Dodawać zdjęć, które nie są Twoje. Podszywać się pod kogoś.",
          "Nie ma tu miejsca na spór światopoglądowy pod przepisem na rosół. Takie komentarze usuwamy bez dyskusji.",
        ],
      },
      {
        id: "co-robimy",
        tytul: "Co robimy, gdy ktoś złamie zasady",
        tresc: [
          "Najpierw piszemy. Dopiero potem usuwamy wpis, a w ostateczności konto. O każdym takim kroku informujemy i mówimy, czego dotyczył.",
        ],
      },
    ],
  },
  zasady: {
    tytul: "Zasady",
    lead: "Nie regulamin, tylko to, co robi z tego miejsca coś, do czego chce się wracać.",
    data: "Ostatnia zmiana: 7 września 2026",
    sekcje: [
      {
        id: "wyszlo",
        tytul: "„Wyszło” znaczy wyszło",
        tresc: [
          "Nikt tu nie ocenia kadru ani tego, że ktoś dał mniej sera, bo tyle miał. Zdjęcie z telefonu przy kuchennym stole jest dokładnie tym, po co jest ten serwis.",
        ],
      },
      {
        id: "bez-rankingow",
        tytul: "Bez rankingów",
        tresc: [
          "Nie ma tu punktów, poziomów, odznak ani listy najlepszych. Nie ma kogo wyprzedzać.",
          "„kuKINGi na dziś” to nie tabela wyników — jutro będzie tam ktoś inny.",
        ],
      },
      {
        id: "po-kim",
        tytul: "Napisz, po kim jest przepis",
        tresc: [
          "To jedno zdanie jest najczęściej czytaną częścią każdego przepisu. Można je zostawić puste, ale prawie nikt tego nie robi.",
        ],
      },
      {
        id: "rozmowa",
        tytul: "Rozmowa pod przepisem",
        tresc: [
          "Pytanie o zamiennik jest zawsze na miejscu. Uwaga z własnej kuchni też.",
          "Jeśli coś Cię zdenerwowało, napisz do nas zamiast pisać pod przepisem. Tego nie rozstrzygniemy w komentarzach.",
        ],
      },
    ],
  },
};

function BelkaDokumentu({ onWybierz, biezacy }) {
  return (
    <header className="pas pas-gorny" style={{ paddingBlock: 0 }}>
      <div className="pas-wnetrze pas-gorny-wnetrze">
        <Wordmark href="#powitalna" />
        <nav className="pas-gorny-akcje" aria-label="Dokumenty">
          {Object.entries(DOKUMENTY).map(([klucz, d]) => (
            <button key={klucz} type="button" className={biezacy === klucz ? "btn btn-secondary" : "btn btn-quiet"}
              aria-pressed={biezacy === klucz ? "true" : "false"} onClick={() => onWybierz(klucz)}>
              {d.tytul}
            </button>
          ))}
        </nav>
      </div>
    </header>
  );
}

function Dokument({ dokument }) {
  const d = DOKUMENTY[dokument];
  return (
    <main className="tresc" id="tresc">
      <section className="pas">
        <div className="pas-wnetrze">
          <div className="tekst-czytany">
            <h1 className="text-title-lg">{d.tytul}</h1>
            <p className="lead">{d.lead}</p>
            <p className="meta">{d.data}</p>

            <nav aria-label="Spis treści" className="card odstep-nad">
              <p className="pomoc" style={{ marginBottom: "var(--spacing-3)" }}>Co jest w tym dokumencie:</p>
              <ul style={{ listStyle: "disc", paddingInlineStart: "var(--spacing-6)", display: "grid", gap: "var(--spacing-2)" }}>
                {d.sekcje.map((s) => (
                  <li key={s.id}><a href={"#" + s.id}>{s.tytul}</a></li>
                ))}
              </ul>
            </nav>

            {d.sekcje.map((s) => (
              <section key={s.id} id={s.id} className="odstep-nad">
                <h2>{s.tytul}</h2>
                {s.tresc.map((p, i) => <p key={i}>{p}</p>)}
                {s.tabela ? (
                  <DataTable wstep={s.tabela.wstep} naglowki={s.tabela.naglowki} wiersze={s.tabela.wiersze} />
                ) : null}
              </section>
            ))}

            <p className="odstep-nad">
              <a className="link-dalej" href="mailto:kontakt@kuking.pl">
                Masz pytanie do tego dokumentu? Napisz<Icon name="strzalka" className="link-dalej-ikona" />
              </a>
            </p>
          </div>
        </div>
      </section>
    </main>
  );
}

Object.assign(window, { DOKUMENTY, BelkaDokumentu, Dokument });
