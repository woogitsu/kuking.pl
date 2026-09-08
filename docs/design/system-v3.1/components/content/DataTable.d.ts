/**
 * Tabela — jedyny kształt treści, którego nie da się złożyć z kart.
 * Polityka prywatności, regulamin, zasady.
 * @dsAdherence Zawsze w otoczce, która przewija się sama. Nigdy tabela do układu strony. Nigdy lista definicji zamiast tabeli.
 */
export interface DataTableProps {
  /** Nagłówki kolumn — trafiają do th scope="col". Bez nich tabela staje się ciągiem słów. */
  naglowki: React.ReactNode[];
  /** Wiersze, każdy jako tablica komórek. */
  wiersze: React.ReactNode[][];
  /** Zdanie nad tabelą mówiące, co w niej jest. Przy 320 px bywa jedyną orientacją. */
  wstep?: React.ReactNode;
  className?: string;
}

export declare function DataTable(props: DataTableProps): JSX.Element;
