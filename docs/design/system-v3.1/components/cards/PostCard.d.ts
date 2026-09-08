/**
 * Karta wpisu — jedno danie: kto je ugotował, co to jest, jak wygląda i co
 * można z tym zrobić. Najczęściej powtarzany element serwisu.
 * @dsAdherence Nigdy cała karta jako jeden link. Nigdy miniatura zdjęcia. Nigdy więcej niż dwie kolumny. Alt opisuje, co jest na talerzu. W stopce jedna akcja ma wagę.
 */
export interface PostCardProps {
  /** Imię autora. Bez niego karta nie ma główki. */
  autor?: string;
  autorHref?: string;
  avatarSrc?: string;
  /** Czas względny: „wczoraj, 18:40”, „3 dni temu”. */
  czas?: React.ReactNode;
  /** Plakietka przy dacie — w strumieniu ZAWSZE cicha (D-103). */
  plakietka?: React.ReactNode;
  /** Tytuł wpisu, 24 px / 800. Tu siada oko. */
  tytul?: React.ReactNode;
  tytulHref?: string;
  /** Treść wpisu, 20 px. */
  tresc?: React.ReactNode;
  /** Adres zdjęcia. Pełna szerokość karty, 4:3 (16:9 w wariancie zwartym). */
  zdjecie?: string;
  /** Alt opisuje, CO JEST NA TALERZU, a nie „zdjęcie dania”. */
  alt?: string;
  /** Wpis bez zdjęcia: ciepłe pole z tekstem 22 px, nie pusta ramka (D-105). */
  bezZdjecia?: React.ReactNode;
  /** Wariant zwarty: zdjęcie 16:9, bez treści i stopki. Profil, tag, szukanie, zeszyt. */
  zwarta?: boolean;
  /** h2 w strumieniu, h3 w sekcji. */
  poziomTytulu?: "h2" | "h3";
  /** Pasek danych przepisu — patrz RecipeCard. */
  dane?: React.ReactNode;
  /** Akcje w stopce. Dokładnie jedna ma wagę. */
  akcje?: React.ReactNode;
  className?: string;
  children?: React.ReactNode;
}

export declare function PostCard(props: PostCardProps): JSX.Element;
