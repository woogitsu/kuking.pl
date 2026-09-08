/**
 * Wątek komentarzy pod przepisem — jeden poziom zagnieżdżenia, kolejność
 * chronologiczna. Ekran przepisu.
 * @dsAdherence Jeden poziom zagnieżdżenia i ani jeden więcej. Nigdy sortowanie po popularności. Odpowiedź oznaczona słowami, nie samym wcięciem.
 */
export interface Komentarz {
  id: string | number;
  autor: string;
  autorHref?: string;
  avatarSrc?: string;
  tresc: React.ReactNode;
  /** Czas: „wczoraj, 20:10”. */
  czas?: React.ReactNode;
  /** Adres formularza odpowiedzi — działa bez skryptu. */
  odpowiedzHref?: string;
  /** Imię osoby, do której to jest odpowiedź. Włącza wcięcie z kreską. */
  wOdpowiedziDo?: string;
}

export interface CommentThreadProps {
  komentarze?: Komentarz[];
  /** Tekst pustego wątku. */
  pusty?: React.ReactNode;
}

export declare function CommentThread(props: CommentThreadProps): JSX.Element;
