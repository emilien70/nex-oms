# PORTAL.2C - stale linki zamowien

## Zakres

Implementacja lokalna w C:\projekty\nex-oms. Punkt wyjscia:
`main`, HEAD `1f489b94e25af61d8801bf3ecc759812ca3ba145`, czysty worktree.
Bez operacji na VPS, commit, push, wysylek wiadomosci i rzeczywistych API.

## Zachowanie

- Istniejacy 10-znakowy kod, szyfrogram i URL nie zmieniaja sie po edycji
  danych nabywcy, adresow, NIP-u, zrodla, statusu, pozycji ani kwot.
- Podpis HMAC wiaze kod z order_id, nie z nabywca. Model odmawia wymiany
  kodu, zmiany token_hash, przypisania do innego zamowienia i zmiany podpisu.
  Niespojne przypisanie lub uszkodzony szyfrogram blokuje dostep.
- Nowy link powstaje tylko raz. Provisioning, podglad, e-mail i GET nie
  wymieniaja kodu ani nie odnawiaja wygaslego/odwolanego dostepu.
- Ustawienia oferuja Wlacz dostep / Wylacz dostep pod tym samym adresem.
  Wymagaja potwierdzenia, CSRF i aktualnego expected_generation; stare
  formularze sa odrzucane. Powtorzenie aktywacji juz aktywnego dostepu
  nie zapisuje nowego stanu. Stary parametr rotate=1 jest odrzucany.
- Zmiana danych osobowych, Kosz/usuniecie i przywrocenie zwiekszaja rewizje
  weryfikacji bez zmiany URL. Powrot danych do poprzednich wartosci nie
  przywraca starej 15-minutowej weryfikacji ani formularza platnosci.
- Zmiany statusu, pozycji i kwot same nie uniewazniaja weryfikacji PII.
  Formularze platnosci zachowuja swoje odrebne kontrole aktualnych kwot,
  odbiorcy, zrodla, konta, stanu i proby. Nie zmieniono rozliczania PayNow.
- Termin dostepnosci od daty zakupu nadal obowiazuje. Jawna aktywacja moze
  przedluzyc wygasly zapis do aktualnego terminu wynikajacego z ustawien,
  tylko gdy ten termin nadal jest w przyszlosci. Nie wprowadza nowego URL.
  Globalne wylaczenie portalu, Kosz i usuniecie zamowienia nadal blokuja.

Osoba znajaca staly adres nadal widzi podstawowe dane tego zamowienia
po zmianie nabywcy. Przy domyslnym maskowaniu pelne dane i dokumenty
wymagaja aktualnej weryfikacji. Nie jest to mechanizm logowania klienta.
Zmiana domeny w ustawieniach nadal zmienia host, a zmiana APP_KEY
uniewaznilaby szyfrowanie i podpisy; tego zadania nie rozszerzono o te operacje.

## Migracja i uzupelnienie

Nowa addytywna migracja:
`2026_10_27_020000_add_permanent_binding_to_customer_portal_links.php`.
Dodaje nullable char(64) binding_hash; nie zmienia danych biznesowych.
Niepodpisane rekordy do czasu jawnego uzupelnienia zachowuja dotychczasowa
walidacje identity_hash. Nowe rekordy od razu otrzymuja podpis, jezeli
migracja jest wykonana. Rollback odmawia usuniecia kolumny z podpisami.

Komendy lokalne (tylko po zatwierdzonej kopii):

```powershell
C:\xampp\php\php.exe artisan migrate --path=database/migrations/2026_10_27_020000_add_permanent_binding_to_customer_portal_links.php --force --no-interaction
C:\xampp\php\php.exe artisan customer-portal:bind-links --dry-run
C:\xampp\php\php.exe artisan customer-portal:bind-links
```

`customer-portal:bind-links --dry-run` niczego nie zapisuje.
`customer-portal:bind-links` uzupelnia podpis i jeden raz zwieksza generation,
takze w rekordach nieaktywnych. Nie zmienia tokena, token_hash, order_id,
identity_hash, expires_at, revoked_at, ustawien ani dokumentow. Nie wlacza
odwolanych/wygaslych linkow. Zmiana samej tozsamosci nabywcy nie blokuje
uzupelnienia podpisu istniejacego kodu. Niespojny kod/podpis przerywa operacje
z bezpiecznym ID rekordu. Polecenie jest idempotentne; nie ma go w schedulerze.
Historyczna komenda replace-legacy-links jest zablokowana i nie zapisuje nic.
Poprawne stare 32-znakowe kody bez podpisu sa pomijane i zliczane osobno.
Nie zmieniaja sie ich adresy/stany i nie przywraca sie ich obslugi w routingu.

Lokalny stan przed operacja: 294 linki (267 obecnych i 27 historycznych),
brak niespojnych skrotow, jedna oczekujaca migracja powyzej.
Po testach, za osobna zgoda wlasciciela, wykonano lokalna spojna kopie SQLite:
`storage/app/private/backups/portal-permanent-20261027_180625-59a2425d.sqlite`.
SHA-256: `8843a73bf5bfc46b2c6faa65b2ab7aba1305ab7d680105cb01950efda687bb3a`.
Integrity check kopii i bazy roboczej: ok.

Uruchomiono tylko migracje 020000 wskazana powyzej. Dry-run wykazal
267 brakujacych podpisow i 27 historycznych kodow do pominiecia.
Uzupelniono 267 podpisow, bez wymiany kodow i przywracania dostepow.
Koncowy dry-run: 0 brakujacych podpisow, 27 kodow historycznych bez zmian.
Porownanie z kopia: 86 tabel chronionych bez zmian, identyczne poprzednie
szyfrogramy/kody, token_hash, order_id, identity_hash, daty waznosci i odwolan,
niezmienione ustawienia portalu i platnosci. Lacznie: 294 rekordy linkow.
Zmiana generation dotyczy tylko pierwszego uzupelnienia podpisu.

## Testy

Srodowisko: PHP 8.2.12, SQLite :memory: i jednorazowe bazy testow
wspolbieznosci, osobny katalog storage i cache,
wylaczone transporty sieciowe, symulowane HTTP/kolejka. Nie testowano na bazie
roboczej uzytkownika. Zapisane raporty QA sa prywatne i nie naleza do Git.

```powershell
C:\xampp\php\php.exe tests/Support/Invoices/run-isolated.php --direct-phpunit --filter=CustomerPortal --log-junit=storage/app/private/qa/portal-permanent-focused.xml
C:\xampp\php\php.exe tests/Support/Invoices/run-isolated.php --log-junit=storage/app/private/qa/portal-permanent-full.xml
```

Testy portalu: 217 testow, 1802 asercje, 0 failures, 0 errors.
Pint --test, PHP lint zmienionych plikow i git diff --check: PASS.
Pelna regresja: 4562 testy, 42162 asercje, 0 failures/errors/skipped,
kod wyjscia 0, 1436 s wedlug runnera. Poprzedni zapisany wynik HEAD:
4522 testy, 41742 asercje, 0 failures/errors/skipped (regression-72-full.xml).
Roznica: +40 testow, +420 asercji; brak nowych niepowodzen wzgledem tego
wyniku. Pierwszy niepelny przebieg przerwano po wykryciu historycznych kodow;
wynik powyzej pochodzi z ponownego, zakonczonego przebiegu koncowego kodu.

Pokrycie obejmuje wszystkie zrodla, zmiany danych i powrot do poprzednich
wartosci, PDF klienta i panelu, rollback zapisu, Kosz/przywrocenie,
manipulacje przypisaniem/kodem, stare formularze, ponowne wlaczenie,
nieaktywne rekordy, dry-run/idempotencje oraz brak migracji.

## Weryfikacja reczna

1. Otworz zamowienie i zanotuj adres na dole Zarzadzania.
2. Otworz adres w drugiej przegladarce i potwierdz dane, bez platnosci.
3. Zmien dane nabywcy/adres/e-mail w OMS. Adres ma pozostac ten sam,
   a pelne dane i PDF klienta maja ponownie wymagac aktualnych danych.
4. Przywroc poprzednie dane: stara weryfikacja nie powinna sie odnowic.
5. W Ustawienia -> Strony informacyjne wybierz numer zamowienia.
   Wylacz dostep, sprawdz niedostepnosc, wlacz i porownaj ten sam adres.
6. Sprawdz, ze podglad i PDF w panelu OMS nie maja nowej bramki klienta.

Nie wykonywac prawdziwych platnosci ani operacji na VPS w ramach tych testow.
Testy SQLite nie potwierdzaja wspolbieznosci blokad w MySQL; stale formularze
i powtorzone zadania sprawdzono lokalnie, a kod uzywa istniejacej kolejnosci
blokad i unikalnego order_id. Zmiana wymaga ponownego uruchomienia tylko
lokalnych Main Queues, aby importy wczytaly nowy observer i serwis.
VPS nie byl aktualizowany; nie wykonano commit ani push. Zatwierdzona kopia,
migracja i uzupelnienie podpisow dotyczyly wylacznie lokalnej bazy.

## Pliki

Nowe: migracja powyzej, app/Console/Commands/BindCustomerPortalLinks.php,
tests/Feature/CustomerPortalPermanentLinksTest.php i ten raport.

Zmienione: Modules/CustomerPortal/Models/PortalLink.php,
Modules/CustomerPortal/Services/PortalAccessService.php,
Modules/CustomerPortal/Observers/PortalOrderObserver.php,
Modules/CustomerPortal/Http/Controllers/PortalSettingsController.php,
app/Console/Commands/ReplaceLegacyCustomerPortalLinks.php,
resources/views/settings/customer-portal/edit.blade.php,
resources/views/customer-portal/order-link.blade.php,
tests/Feature/CustomerPortalAutomaticLinksTest.php,
tests/Feature/CustomerPortalPrivacyTest.php,
tests/Feature/CustomerPortalPaymentsTest.php,
tests/Feature/CustomerPortalTest.php,
docs/product-spec.md, docs/architecture.md, docs/006-Deployment.md.
Nie usunieto plikow.
