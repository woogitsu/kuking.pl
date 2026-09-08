/**
 * Karta „Ugotowałem” — dowód, że przepis wyszedł u kogoś w domu.
 * Sekcja „Komu wyszło” na ekranie przepisu, archiwum profilu.
 * @dsAdherence Nigdy głośna plakietka „Ugotowałem” w każdej karcie. Nigdy gwiazdki ani ocena. Nigdy miniatura zdjęcia.
 */
export interface CookedCardProps {
  autor: string;
  autorHref?: string;
  avatarSrc?: string;
  /** Nazwa przepisu jako treść odnośnika, nie „tutaj”. */
  przepis: string;
  przepisHref?: string;
  /** „ugotowała” albo „ugotował”. Formy żeńskiej nazwy funkcji nie tworzymy. */
  forma?: string;
  /** Czas względny: „3 dni temu”. */
  czas?: string;
  /** Kilka słów od osoby, która gotowała: „Wyszło. I to się liczy.” */
  tresc?: React.ReactNode;
  zdjecie?: string;
  /** Alt opisuje, co jest na zdjęciu. Wariant bez zdjęcia jest pełnoprawny. */
  alt?: string;
  className?: string;
}

export declare function CookedCard(props: CookedCardProps): JSX.Element;
