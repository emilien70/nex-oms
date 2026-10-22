# Allegro - Zwrot Prowizji

Data: 2026-10-22. Rozszerzenie karty zwrotu po ALLEGRO.4B.2.

## Zakres

- Przycisk Zwrot Prowizji znajduje sie po prawej stronie Zwroc wplate.
- Formularz pokazuje produkty, ilosci i istniejace wnioski. Wysylka wymaga jawnej zgody.
- Kazda zaznaczona pozycja tworzy osobny wniosek POST /order/refund-claims z oryginalnym lineItem.id i quantity.
- Wniosek dotyczy prowizji sprzedawcy, nie zwrotu pieniedzy kupujacemu. Nie zalezy od wlaczenia payments:write ani od zaplaty ONLINE.
- Obowiazuje jeden wniosek Allegro na oryginalna pozycje zakupu. Ilosc jest ograniczona do biezacej karty zwrotu i oryginalnego zakupu. Przy zwrocie czesciowym trzeba swiadomie dobrac liczbe sztuk.
- Deduplikacja obejmuje wszystkie karty tego samego zakupu oraz wnioski istniejace w Allegro, takze automatyczne.
- Historia na karcie ma 20 wpisow na strone. Wyniki sa sprawdzane w tle, bez API przy otwieraniu samej karty.
- Brak zmian danych zamowien, kwot zaplaty, faktur, lokalnych statusow i magazynu. Brak automatycznego anulowania lub odwolan.

## Pliki utworzone

- Modules/Integrations/Allegro/Http/Controllers/AllegroCommissionRefundController.php
- Modules/Integrations/Allegro/Jobs/ProcessAllegroCommissionRefundJob.php
- Modules/Integrations/Allegro/Models/AllegroCommissionRefundClaim.php
- Modules/Integrations/Allegro/Services/AllegroCommissionRefundService.php
- database/migrations/2026_10_22_050000_add_allegro_commission_refund_claims.php
- resources/views/returns/commission-refund.blade.php
- resources/views/returns/_commission-refunds.blade.php
- tests/Feature/AllegroCommissionRefundsTest.php
- tests/Unit/AllegroCommissionRefundMigrationTest.php
- docs/allegro-commission-refunds-report.md

## Pliki rozszerzone

- Modules/Integrations/Allegro/Services/AllegroClient.php
- app/Http/Controllers/OrderReturnController.php
- resources/views/returns/show.blade.php
- routes/web.php
- routes/console.php
- docs/product-spec.md
- docs/architecture.md

Nie usunieto plikow. Zachowano zastane zmiany ALLEGRO.4B.2. Bez commit/push i zmian konfiguracji srodowiska.

## Bezpieczenstwo

Formularz wymaga sesyjnego tokenu, waznego przez 5 minut, CSRF i checkboxa potwierdzenia. Kontekst jest ponownie odczytywany przy zatwierdzeniu oraz przed wysylka. Sprawdzane sa tozsamosc konta, pelny link zakupu, wersja zwrotu i jednoznaczne ID pozycji. Zmiany konta, srodowiska, klienta OAuth, pozycji lub zwrotu uniewazniaja stara zgode. Brak lokalnego zamowienia albo jednoznacznego powiazania blokuje operacje.

Rekord sending i started_at sa zapisywane przed HTTP. Stan bazy oraz unikalny indeks, a nie samo unique job, chronia przed duplikatem. Timeout, przerwanie procesu, 5xx, 429 lub niepelne 201 nie powoduja ponownego POST. Wynik jest uzgadniany przez odczyt oryginalnego lineItem.id. HTTP 401 dopuszcza tylko identyczne zadanie po odnowieniu OAuth i ponownej kontroli konta. not_sent moze byc swiadomie potwierdzone ponownie tylko przy jednoczesnym braku started_at i zewnetrznego ID; rozpoczetego wniosku nie wysyla sie ponownie.

Odczyt sprawdza rowniez buyer.id, offer.id, zakupiona ilosc, stabilna date i ID wniosku. Nie dopasowuje po nazwie, kwocie ani samej ofercie. GET listy wykorzystuje strony po 100 i count, bez nieistniejacego totalCount; niekompletne/niespojne dane lub 10 pelnych stron blokuja nowa wysylke. Blad odczytu zachowuje poprzedni fakt i ostrzezenie.

Nowe metody uzywaja obecnych orders:read/orders:write i wspolnego User-Agent z services.allegro.user_agent:

```text
NEX-OMS_device/v1.0.0 (+https://multi-click.pl/nex-oms/doc.html)
```

Nie dodano scope ani nowego systemu logowania. Potwierdzonym zabezpieczeniem panelu jest Basic Auth Nginx/Apache. Wymagane HTTPS, ochrona wszystkich tras i brak publicznego obejscia proxy. Nie zapisuje sie surowych odpowiedzi API ani kwot prowizji; funkcja nie wykonuje obliczen pienieznych float.

## Migracja lokalna

Migracja 2026_10_22_050000_add_allegro_commission_refund_claims zostala uruchomiona po osobnej zgodzie wlasciciela. Dodaje tylko tabele allegro_commission_refund_claims. FK do konta, zamowienia i zwrotu maja nullOnDelete. Snapshoty i historia pozostaja po usunieciu rodzica. Automatyczny down jest celowo zablokowany przed utrata historii.

Jawne nazwy ograniczen: acrc_account_fk, acrc_order_fk, acrc_return_fk, acrc_line_unique, acrc_external_unique, acrc_order_index, acrc_due_index, acrc_return_number_index. Wszystkie mieszcza sie w limicie 64 znakow MySQL.

Kopia lokalnej SQLite wykonana spojnym VACUUM INTO przed migracja:

```text
C:\projekty\nex-oms\storage\app\private\backups\database_before_allegro_commission_refunds_20261022_224731.sqlite
SHA256: bd5dd0b7a0dcace180991a32d507968ea6fc14438163760996099fdfbe7778ce
Rozmiar: 7872512 bajtow
```

Kontrola integralnosci: quick_check=ok, 0 naruszen FK. Fingerprinty wszystkich istniejacych wierszy zamowien, pozycji, dokumentow, zwrotow, przypisan refundacji, zlecen refundacji oraz kont Allegro pozostaly identyczne. Nowa tabela zawierala 0 wnioskow. Wlasciciel potwierdzil restart Main Queues po migracji.

## Testy

Testy uruchamiano w odizolowanym storage, SQLite :memory: i z zablokowanym rzeczywistym dostepem sieciowym. Odpowiedzi Allegro sa atrapami. Nie wyslano rzeczywistego wniosku ani refundacji.

```powershell
C:\xampp\php\php.exe tests/Support/Invoices/run-isolated.php --filter=AllegroCommissionRefund --stop-on-defect
C:\xampp\php\php.exe tests/Support/Invoices/run-isolated.php --filter='Allegro|OrderReturnsTest|InvoiceReturnContextTest|MySqlMigrationIdentifiersTest' --stop-on-defect
```

Wynik ukierunkowany: 36 testow, 376 asercji. Wynik rozszerzony: 562 testy, 3672 asercje. Wszystkie poprawne. PHP lint obejmuje 14 plikow PHP/Blade, Pint 11 zmienionych plikow PHP, a git diff --check cale zastane worktree.

Zakres obejmuje sesje i potwierdzenie, ilosci i mapowanie, powtorzenia, wiele kart i produktow, automatyczne wnioski, timeout/przerwanie procesu, 401/403/422/429/503, brak lub bledne ID odpowiedzi, paginacje, zmiany tozsamosci, zachowanie historii po usunieciu rodzicow oraz generowanie SQL MySQL z krotkimi nazwami ograniczen. User-Agent jest sprawdzany we wszystkich zarejestrowanych zadaniach HTTP, takze OAuth. Ochrona CSRF korzysta ze standardowego middleware web i tokenu formularza; nie wykonano osobnego testu end-to-end odrzucenia zadania przez ten middleware.

## Weryfikacja reczna

1. Otworz lokalna karte zwrotu powiazana z zamowieniem Allegro, np. /orders/returns/20. Zweryfikowano w przegladarce polozenie Zwrot Prowizji po prawej stronie Zwroc wplate, bez nakladania elementow. Podglad nie wysylal wnioskow.
2. Kliknij Zwrot Prowizji. To wykona odczyt Allegro i otworzy formularz, ale nie utworzy wniosku.
3. Sprawdz konto, srodowisko, produkty, zakupione ilosci oraz status istniejacych wnioskow. Anuluj, jezeli chcesz zakonczyc bez wysylki.
4. Tylko dla rzeczywiscie zamierzonej operacji wybierz produkty i liczbe sztuk, zaznacz potwierdzenie i kliknij Wyslij wniosek. Jest to rzeczywisty zapis do Allegro.
5. Historia karty pokaze kolejke, wyslanie i odczytany wynik. Wynik odswieza sie w tle co okolo 5 minut; przeladuj karte, aby go zobaczyc. Przyznanie prowizji nie oznacza zwrotu pieniedzy klientowi.

## Wdrozenie VPS - reczne

Nie wykonywano zadnych operacji na VPS. Przed wdrozeniem wykonaj i sprawdz kopie bazy metoda odpowiednia dla tego VPS, zachowujac dane dostepowe poza historia polecen. Wgraj komplet kodu, w tym zachowana zaleznosc ALLEGRO.4B.2, zabezpiecz panel HTTPS i Basic Auth oraz zatrzymaj lub odczekaj zakonczenie biezacych zadan kolejki zgodnie z istniejaca procedura wdrozenia.

W katalogu projektu na VPS sprawdz status migracji. Jezeli zaleznosci sa juz wdrozone i brakuje wylacznie nowej tabeli:

```bash
php artisan migrate:status
php artisan migrate --force --path=database/migrations/2026_10_22_050000_add_allegro_commission_refund_claims.php
```

Nie powtarzaj migracji, jezeli tabela pozostala po czesciowym niepowodzeniu; najpierw porownaj jej strukture, historie migracji i dane. Nie kasuj tabeli ani wnioskow automatycznie. Po poprawnej migracji przeladuj Main Queues istniejacym panelem lub menedzerem procesow. Harmonogram Laravel i kolejka integrations musza dzialac, aby wysylac zlecenia i odczytywac wyniki. Nowa funkcja nie wymaga ponownego OAuth, jesli konto zachowalo obecne orders:read/orders:write.

## Ograniczenia

Nie wykonano rzeczywistego POST Allegro ani testu end-to-end na koncie produkcyjnym. Rzeczywista kwalifikacja do rabatu i termin jego przyznania naleza do Allegro. Nie bylo osobnego MySQL 8: lokalny serwer XAMPP jest MariaDB 10.4, wiec zgodnosc MySQL sprawdzono przez generowanie SQL dla MySQL 8.0.46, nie rzeczywiste wykonanie na tym serwerze. Formularz zweryfikowano testami renderowania z atrapami HTTP; w lokalnej przegladarce sprawdzono tylko karte i polozenie przycisku.

## Zrodla kontraktu

Oficjalna dokumentacja Allegro sprawdzona 2026-10-22:

- https://developer.allegro.pl/tutorials/jak-obslugiwac-zamowienia-GRaj0qyvwtR#jak-utworzyc-wniosek-o-rabat-transakcyjny
- https://developer.allegro.pl/swagger.yaml
- https://help.allegro.com/pl/sell/c/kiedy-i-jak-skladac-wniosek-o-rabat-transakcyjny

