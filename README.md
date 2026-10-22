# Zadanie rekrutacyjne - PHP (MID)

Cześć! Zadanie do wykonania w domu.

**Budżet: 2 godziny.** Rozwiązanie nie musi być kompletne - czego nie zdążysz, opisz w notatce.

Po oddaniu spotkamy się na ok. 45-60 minut i poprosimy Cię o przejście przez to, co zrobiłeś i dlaczego. Będzie z nami
osoba nietechniczna z zarządu. Nie musisz się na to spotkanie przygotowywać - wystarczy, że rozumiesz, co oddałeś.

---

## W skrócie

Masz eksport z systemu A: kandydaci i ich rekrutacje, każda rekrutacja ma `brand`. Wylicz każdemu kandydatowi tagi
brandów jego rekrutacji i dopisz brakujące w systemie B przez API.

Dwie rzeczy, z którymi musisz się liczyć:

- **API systemu B bywa zawodne** - czasem zamiast odpowiedzi zwraca błąd.
- **Serwer, na którym uruchamiamy synchronizację, potrafi zabić jej proces w połowie** - `kill -9`, bez ostrzeżenia i
  bez szansy na posprzątanie.

Synchronizacja ma mimo to dojść do końca, a po ponownym uruchomieniu nie ponawiać niepotrzebnie tego, co już zrobiła.

**Interesuje nas przede wszystkim strategia synchronizacji** - to, jak zaprojektujesz ją tak, żeby te dwie rzeczy jej
nie rozłożyły. Chcemy ją zobaczyć w kodzie i o niej porozmawiać.

---

## Wymagania środowiska

**PHP 8.4 lub nowszy**.

Nie potrzebujesz Composera, frameworka ani bazy danych. Chyba że sam zdecydujesz inaczej - masz wolną rękę.

---

## Kontekst

Łączymy systemy. **System A** (rekrutacyjny) trzyma kandydatów i rekrutacje - dostajesz z niego gotowy eksport, dwa
pliki JSON. **System B** to zewnętrzne API, w którym kandydaci mają tagi. Chcemy, żeby każdy kandydat miał w systemie B 
tagi brandów wszystkich rekrutacji, do których należy w A.

W paczce jest sześć rekordów kandydatów - tyle, żeby dało się wszystko sprawdzić okiem.

### Reguły domenowe

`mapping.json` to nasza mapa: nazwa brandu -> tag albo tagi, które z niego wynikają. Jeden brand może dawać więcej niż
jeden tag.

- Kandydat może być w wielu rekrutacjach różnych brandów, więc może mieć wiele tagów.
- Gdy kandydat opuści ostatnią rekrutację danego brandu, tag tego brandu ma zniknąć.
- **Rekrutacje zamknięte też się liczą.** Liczy się członkostwo, nie status rekrutacji.
- **Nie wolno ruszać tagów, które nie pochodzą z `mapping.json`.** Kandydaci mają w B własne tagi, których nie ustawiamy
  my i które nie mają nic wspólnego z brandami.
- Brand, którego nie ma w `mapping.json`, nie daje żadnego tagu.

### API systemu B

```php
require_once __DIR__ . '/system-b/ats.php';

$client = new FakeAtsClient();
```

Klient konfiguruje się sam - nic mu nie podajesz. Dwie metody:

- `tags(int $candidateId): array` - tagi, które kandydat ma teraz
- `addTags(int $candidateId, array $tags): void` - dopisuje tagi

**API nie ma usuwania tagów.** Będzie, ale później. Tagi, które powinny zniknąć, mają zostać **zaraportowane, a nie
zdjęte obejściem**. W jakiej formie - Twoja decyzja.

**B pamięta, co mu wyślesz.** Ma własną bazę (`system-b/var/ats-db.json`), która przeżywa restart Twojego programu -
`addTags()` naprawdę zapisuje, a `tags()` to potem pokazuje. Nie jest to interfejs do systemu B - wszystko idzie przez
`tags()` i `addTags()`. Skasuj cały katalog `system-b/var/`, żeby wrócić do stanu początkowego.

Każde wywołanie ląduje w `system-b/var/calls.log`.

Zachowanie systemu B ustawia `system-b/config.php` - limit zapytań, ubicie procesu. Przy ocenie możemy uruchomić system B
z innymi wartościami tej konfiguracji niż w paczce, więc nie opieraj rozwiązania na konkretnych liczbach z `config.php`.

---

## Co jest w paczce

```
zadanie/
├── export/                    # dane z systemu A
│   ├── recruitments.json      # id, brand, is_closed, updated_at
│   └── memberships.json       # kandydat -> rekrutacje
├── mapping.json               # brand -> tag(i); nasza konfiguracja
└── system-b/                  # system B - nasz katalog, patrz niżej
    ├── ats.php
    └── config.php
```

`system-b/` to cały system B: API, jego awarie i jego baza. Wszystko, co wiesz o tagach, ma przyjść przez `tags()` i
`addTags()` - nie sięgaj do jego wnętrza po dane.


---

## Co masz oddać

### 1. Działający kod

Program, który przechodzi po kandydatach z eksportu, dopisuje w B brakujące tagi brandów i raportuje te, które powinny
zniknąć (API do usuwania nie ma - patrz wyżej). Uruchamiany tak:

```
php sync.php
```

Wymagania:

- **Synchronizacja ma dojść do końca** mimo błędów zwracanych przez API.
- **Ma być odporna na zabicie procesu** (restart serwera, przekroczenie limitu czasu procesora etc). Po restarcie ma
  wznowić pracę bez niepotrzebnego ponawiania operacji, które już zostały wykonane. Czym to osiągniesz - Twoja decyzja.
- **Nie ruszaj tagów, których nie zarządzasz.**
- **Nie modyfikuj `system-b/ats.php`.** Twój kod ma sobie radzić z systemem B takim, jaki jest. Konfigurację
  (`system-b/config.php`) możesz przestawiać, a katalog `system-b/var/` kasować - to jest do testowania.
- **Pisz na ekran, co robisz** - `dodaję X kandydatowi Y`, `zgłaszam do zdjęcia Z`. Chcemy widzieć przebieg.

Testy jednostkowe mile widziane, przynajmniej dla logiki wyliczania tagów. Nie potrzebujesz PHPUnita - plik `test.php` z
gołymi `assert`ami w zupełności wystarczy.

**Dodatkowe uwagi:**

- **Jeden plik w zupełności wystarczy.** Nie oceniamy scaffoldingu. Interesuje nas rozdzielenie odpowiedzialności, nie
  liczba plików.

### 2. Notatka

Kilka zdań - w README Twojego rozwiązania albo w osobnym pliku:

- ile czasu realnie na to poświęciłeś,
- jakie kompromisy podjąłeś: co odpuściłeś przez limit czasu, co zrobiłbyś inaczej produkcyjnie i jakie ryzyka zostają
  w tym, co oddajesz,
- jakie założenia przyjąłeś.

---

## Jak oddać

Repo git (link) albo ZIP: kod i notatka.
