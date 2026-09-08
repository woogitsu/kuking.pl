import type { PostCardProps } from "./PostCard";

/**
 * Karta przepisu — karta wpisu plus pasek danych: ile to trwa, na ile osób,
 * czy dam radę. Sześć ekranów.
 * @dsAdherence Maksimum trzy dane. Jednostki pełnym słowem, liczby cyframi. Nigdy gwiazdki zamiast poziomu. Nie chowaj czasu i porcji do środka przepisu.
 */
export interface RecipeCardProps extends Omit<PostCardProps, "dane"> {
  /** Czas przygotowania z jednostką: „90 minut”. (`czas` zostaje datą wpisu, jak w karcie wpisu.) */
  czasPrzygotowania?: string;
  /** Porcje z jednostką: „6 porcji”. */
  porcje?: string;
  /** Poziom słowem: „Łatwe”, „Średnie”, „Trudne”. Nigdy gwiazdki. */
  poziom?: string;
}

export declare function RecipeCard(props: RecipeCardProps): JSX.Element;
