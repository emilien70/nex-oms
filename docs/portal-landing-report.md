# Strona glowna domeny klienta

## Zakres i zachowanie

Poprawka lokalna z 2026-10-28. Zachowano wszystkie wczesniejsze zmiany
robocze. Bez operacji na VPS, commit, push, migracji i zmian danych.

GET / na domenie wskazanej w ustawieniu customer_domain wyswietla
istniejacy widok customer-portal.show ze stanem niedostepnosci zamowienia.
Trescia i wygladem odpowiada nieprawidlowemu linkowi zamowienia. Pozostaje
HTTP 404, bez przekierowania i bez wyszukiwania/generowania linkow.
HEAD zwraca ten sam status i naglowki bez tresci. Inne metody nie sa
obslugiwane. Zachowano CSP, no-store, noindex i osobna sesje klienta.

Dashboard na domenie OMS i lokalnym loopback pozostaje dostepny tak jak
wczesniej. Trasy administracyjne na domenie klienta nadal sa odrzucane.
Wyczyszczenie/zmiana domeny lub brak jej migracji nie ujawnia dashboardu
na dawnym publicznym hoscie. Adresy zamowien, weryfikacja danych i PDF,
PayNow oraz KSeF nie zostaly zmienione.

## Pliki

Zmienione w tym etapie:

- Modules/CustomerPortal/Services/PortalUrlService.php - wspolne rozpoznanie
  dokladnej strony glownej skonfigurowanej domeny klienta.
- Modules/CustomerPortal/Http/Middleware/RestrictCustomerPortalHost.php -
  waski wyjatek dla strony glownej i odmowa obcych hostow bez konfiguracji.
- Modules/CustomerPortal/Http/Controllers/PortalController.php - uzycie
  istniejacego widoku oraz naglowkow niedostepnego zamowienia.
- routes/web.php - wybor strony klienta przed zapytaniami dashboardu.
- docs/006-Deployment.md - dokladny wyjatek Nginx i weryfikacja wdrozenia.

Nowe:

- tests/Feature/CustomerPortalLandingTest.php - 24 przypadki regresyjne.
- docs/portal-landing-report.md - ten raport.

Nie usunieto plikow. Wczesniejsze zmiany portalu w innych plikach pozostaly
nienaruszone; nie sa nowymi zmianami tego etapu.

## Testy

Izolowany SQLite :memory:, osobne storage/cache i wylaczone polaczenia
zewnetrzne przez istniejacy runner. Nie uzywano roboczej bazy danych.

```powershell
C:\xampp\php\php.exe tests/Support/Invoices/run-isolated.php --direct-phpunit '--filter=CustomerPortalLandingTest|CustomerPortalPaymentsTest|CustomerPortalPrivacyTest::test_(customer_host_denies_admin_routes_and_uses_separate_host_only_session|ten_character_codes_are_case_sensitive_order_scoped_and_old_routes_do_not_work|domain_setting_is_normalized_and_old_forms_preserve_privacy_settings|domain_field_rejects_paths_credentials_ports_ips_and_wildcards|changing_customer_domain_preserves_only_signed_historical_payment_returns|paynow_settings_and_new_signed_returns_use_customer_host_without_changing_webhook_or_custom_url|customer_domain_cannot_replace_the_admin_panel_host)' --log-junit=storage/app/private/qa/portal-landing-regression.xml
C:\xampp\php\php.exe tests/Support/Invoices/run-isolated.php --direct-phpunit --filter=ExampleTest::test_the_application_returns_a_successful_response --log-junit=storage/app/private/qa/portal-landing-dashboard.xml
```

- 98 testow portalu/PayNow/ochrony domeny, 631 asercji: PASS.
- 1 istniejacy test dashboardu, 1 asercja: PASS.
- Lacznie 99 roznych testow, 632 asercje, 0 failures/errors/skipped.
- Pint dla czterech plikow aplikacji i nowego testu: PASS.
- php -l dla tych samych pieciu plikow: PASS.
- git diff --check: PASS (istniejace ostrzezenie CRLF w product-spec).

Zgodnie z poleceniem nie uruchamiano testow KSeF ani pelnej regresji.
Poczatkowe trzy bledne oczekiwania nowego testu (surowe atrybuty modelu
przed odczytem i pusta tresc dla nieistniejacych tras) poprawiono; wyniki
powyzej pochodza z zakonczonego uruchomienia koncowej wersji.

## Migracje i wdrozenie

Nie ma nowej migracji ani operacji na danych. Nie potrzeba restartu kolejek.
Na VPS trzeba osobno wdrozyc aplikacje, a nastepnie dodac scisly
`location = /` w istniejacym HTTPS vhoscie klienta wedlug
[dokumentacji wdrozenia](006-Deployment.md). Zachowac pozostale location,
certyfikaty, ochrone Basic Auth panelu i reguly webhookow/platnosci.
Nie dopuszczac calego vhosta przez ogolne try_files lub handler *.php.

W tym etapie nie laczono sie z VPS i nie zmieniano konfiguracji serwera.
Nie wykonano nginx -t/reload ani testu Basic Auth na VPS. Testy aplikacji
nie zastepuja tej weryfikacji. Bez zmiany Nginx serwer nadal moze zwracac
swoj domyslny blad dla samej domeny klienta.

## Weryfikacja reczna po osobnym wdrozeniu

1. Otworzyc https://klient.multi-click.pl/ - komunikat niedostepnosci
   zamowienia w identycznym wygladzie jak przy nieprawidlowym linku.
2. Sprawdzic brak przekierowania, HTTP 404, no-store i noindex;
   sam status bez sprawdzenia tresci nie wystarcza.
3. Otworzyc poprawny i niepoprawny link zamowienia - bez zmiany zachowania.
4. Sprawdzic /orders i /settings/customer-portal na hoscie klienta - nadal 404.
5. Otworzyc panel OMS - nadal wymaga Basic Auth; po uwierzytelnieniu
   jego strona glowna pokazuje dashboard, nie komunikat portalu.

Lokalna pusta domena klienta nadal oznacza brak osobnego publicznego hosta.
Nie zmieniano tego ustawienia ani DNS w celu testowania poprawki.
