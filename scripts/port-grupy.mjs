export function wybierzGrupe(value = 'wszystko') {
  if (!['wszystko', 'baza', 'rozszerzenia'].includes(value)) {
    throw new Error(`Nieznana PORT_GRUPA: ${value}`);
  }
  return value;
}

export async function wykonajGrupe(wybor, nazwa, pomiar) {
  wybierzGrupe(wybor);
  if (wybor !== 'wszystko' && wybor !== nazwa) return;
  const start = performance.now();
  try {
    await pomiar();
  } finally {
    console.log(`PORT_CZAS grupa=${nazwa} sekundy=${((performance.now() - start) / 1000).toFixed(2)}`);
  }
}
