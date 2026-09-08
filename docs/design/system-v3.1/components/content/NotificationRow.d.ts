/**
 * Wiersz powiadomienia — ktoś ugotował z Twojego przepisu, napisał komentarz
 * albo zaczął Cię obserwować. Ekran powiadomień.
 * @dsAdherence Nieprzeczytane po tle I po słowie „nowe”. Nigdy kształt karty. Nigdy dwie akcje w wierszu.
 */
export interface NotificationRowProps {
  /** Imię osoby, której dotyczy powiadomienie — źródło awatara. */
  imie: string;
  avatarSrc?: string;
  /** Pełne zdanie: „Halina ugotowała Twój rosół.”, nie „Nowa aktywność”. */
  tresc: React.ReactNode;
  /** Czas względny: „2 godziny temu”. */
  czas?: React.ReactNode;
  /** Tło brand-tint, kreska przy krawędzi I plakietka „nowe”. */
  nieprzeczytane?: boolean;
}

/** Otoczka listy wierszy — ul, więc czytnik poda liczbę pozycji. */
export interface RowListProps {
  className?: string;
  children: React.ReactNode;
}

export declare function NotificationRow(props: NotificationRowProps): JSX.Element;
export declare function RowList(props: RowListProps): JSX.Element;
