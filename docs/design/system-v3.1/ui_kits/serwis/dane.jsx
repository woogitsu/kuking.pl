const DS = window.KukingPlSystemProjektowy_a664d7;

const ZDJ = "../../assets/photos/";

const OSOBY = {
  basia: { imie: "Basia", avatar: ZDJ + "avatar_user.png" },
  kasia: { imie: "Kasia Wrzosek", avatar: ZDJ + "avatar_kasia.png" },
  piotr: { imie: "Piotr Zalewski", avatar: ZDJ + "avatar_piotr.png" },
  halina: { imie: "Halina z Mazowsza", avatar: null },
};

/* Dane demonstracyjne. Nazwy dań, imiona i daty są danymi, nie napisami
   interfejsu — są zmyślone i dobrane tak, żeby zgadzały się ze zdjęciem.
   Wszystkie NAPISY interfejsu pochodzą z dokumentów marki. */
const WPISY = [
  {
    id: 1,
    autor: OSOBY.kasia,
    czas: "7 września, 18:20",
    widocznosc: "publicznie",
    tytul: "Pierogi ruskie na niedzielę",
    tresc: "Ciasto na gorącej wodzie, farsz jak zawsze — ziemniaki, twaróg i dużo cebuli. Wyszło. I to się liczy.",
    zdjecie: ZDJ + "pierogi.png",
    alt: "Talerz pierogów polanych zesmażoną cebulką i posypanych szczypiorkiem",
    komentarze: 8,
  },
  {
    id: 2,
    autor: OSOBY.piotr,
    czas: "wczoraj, 19:40",
    widocznosc: "publicznie",
    tytul: "Pizza w piątek, bo tak wyszło",
    tresc: "Ciasto stało od rana pod ścierką. Bazylia z parapetu, reszta z lodówki.",
    zdjecie: ZDJ + "pizza.png",
    alt: "Pizza z mozzarellą, pomidorkami i świeżą bazylią na drewnianej desce",
    przepis: { czas: "90 minut", porcje: "6 porcji", poziom: "Średnie" },
    komentarze: 3,
  },
  {
    id: 3,
    autor: OSOBY.halina,
    czas: "wczoraj, 12:05",
    widocznosc: "tylko obserwujący",
    tytul: "Rosół stał od dziewiątej rano",
    bezZdjecia:
      "Cebula opalona nad palnikiem, bo bez tego wywar jest blady. Solę na końcu, tak jak babcia, i za każdym razem ktoś przy stole mówi, że za mało.",
  },
];

const PRZEPIS = {
  tytul: "Pierogi ruskie po babci Halinie",
  autor: { imie: "Marek", avatar: null },
  dopisek: "przepis po babci",
  zdjecie: ZDJ + "pierogi.png",
  alt: "Talerz pierogów polanych zesmażoną cebulką i posypanych szczypiorkiem",
  czas: "90 minut",
  porcje: "6 porcji",
  poziom: "Kuchnia domowa",
  zajawka:
    "Ciasto na gorącej wodzie, farsz z ziemniaków ugotowanych dzień wcześniej i tyle pieprzu, żeby było czuć. U babci stały na stole w każdą niedzielę.",
  poKim: "Po babci Halinie, z Rzeszowszczyzny.",
  historia: [
    "Babcia lepiła je na blacie posypanym mąką, bez stolnicy, i nigdy nie mierzyła niczego szklanką. Mówiła, że ciasto samo powie, ile wody weźmie.",
    "Kartka z tym przepisem leżała dwadzieścia lat w kieszeni fartucha. Jest na niej dopisek innym długopisem: „mniej pieprzu, dzieci nie lubią”.",
  ],
  skladniki: [
    "3 szklanki mąki",
    "szklanka gorącej wody, może trochę więcej",
    "1 kg ziemniaków, ugotowanych dzień wcześniej",
    "40 dag twarogu półtłustego",
    "2 duże cebule",
    "sól i sporo pieprzu",
  ],
  kroki: [
    "Ziemniaki ugotuj dzień wcześniej i zostaw w chłodnym miejscu. Zimne lepiej się przepuszcza.",
    "Mąkę wsyp do miski i zalej gorącą wodą. Mieszaj łyżką, dopóki nie da się dotknąć ręką.",
    "Wyrabiaj ciasto, aż przestanie kleić się do rąk. Odstaw pod ściereczką na pół godziny.",
    "Ziemniaki i twaróg przepuść przez maszynkę. Jedną cebulę zesmaż na złoto i dodaj do farszu.",
    "Farsz dopraw solą i pieprzem. Spróbuj — na zimno ma być wyraźnie za mocno.",
    "Ciasto rozwałkuj po kawałku, nie od razu całe. Wykrawaj szklanką.",
    "Lepij brzegi mocno, dwa razy. Gotowe odkładaj na ściereczkę posypaną mąką.",
    "Wrzucaj na osoloną, ledwo mrugającą wodę. Po wypłynięciu gotuj jeszcze dwie minuty.",
    "Drugą cebulę zesmaż na maśle i polej pierogi na talerzu.",
  ],
  wykonania: [
    {
      id: 1,
      autor: "Basia",
      avatar: ZDJ + "avatar_user.png",
      czas: "3 dni temu",
      tresc: "Zrobiłam pół porcji i wyszło pięćdziesiąt sztuk. Nie wiem, jak to policzyć inaczej.",
    },
  ],
  komentarze: [
    {
      id: 1,
      autor: "Halina z Mazowsza",
      autorHref: "/@halina",
      tresc: "U mnie ciasto zawsze się rwie. Ile trzymasz je pod ściereczką?",
      czas: "wczoraj, 20:10",
      odpowiedzHref: "#odpowiedz",
    },
    {
      id: 2,
      autor: "Marek",
      autorHref: "/@marek",
      wOdpowiedziDo: "Haliny",
      tresc: "Pół godziny. I nie wałkuję od razu całego, tylko po kawałku.",
      czas: "wczoraj, 20:40",
    },
  ],
};

const POWIADOMIENIA = [
  { id: 1, imie: "Halina z Mazowsza", avatar: null, tresc: "Halina z Mazowsza ugotowała Twój rosół.", czas: "2 godziny temu", nowe: true },
  { id: 2, imie: "Kasia Wrzosek", avatar: ZDJ + "avatar_kasia.png", tresc: "Kasia Wrzosek napisała komentarz pod „Pierogi ruskie po babci Halinie”.", czas: "wczoraj, 20:10", nowe: true },
  { id: 3, imie: "Piotr Zalewski", avatar: ZDJ + "avatar_piotr.png", tresc: "Piotr Zalewski zaczął Cię obserwować.", czas: "w poniedziałek", nowe: false },
  { id: 4, imie: "Basia", avatar: ZDJ + "avatar_user.png", tresc: "Twój przepis „Pierogi ruskie po babci Halinie” jest widoczny dla wszystkich.", czas: "5 września", nowe: false },
];

const ZESZYT = [
  { id: 1, tytul: "Pizza z pieca w ogrodzie", autor: OSOBY.piotr, czas: "zapisane wczoraj", zdjecie: ZDJ + "pizza.png", alt: "Pizza z mozzarellą, pomidorkami i świeżą bazylią na drewnianej desce", czasPrzygotowania: "90 minut", porcje: "6 porcji" },
  { id: 2, tytul: "Pomidorowa z własnych pomidorów", autor: OSOBY.halina, czas: "zapisane 3 września", zdjecie: ZDJ + "soup.png", alt: "Talerz pomidorowej z ryżem, łyżka obok", czasPrzygotowania: "40 minut", porcje: "4 porcje" },
  { id: 3, tytul: "Sernik bez spodu", autor: OSOBY.kasia, czas: "zapisane 28 sierpnia", zdjecie: ZDJ + "cake.png", alt: "Sernik w przekroju, na paterze", czasPrzygotowania: "90 minut", porcje: "12 porcji" },
  { id: 4, tytul: "Makaron z cukinią", autor: OSOBY.piotr, czas: "zapisane 20 sierpnia", zdjecie: ZDJ + "pasta.png", alt: "Makaron z cukinią na talerzu", czasPrzygotowania: "25 minut", porcje: "2 porcje" },
];

Object.assign(window, { DS, ZDJ, OSOBY, WPISY, PRZEPIS, POWIADOMIENIA, ZESZYT });
