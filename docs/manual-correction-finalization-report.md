# Ręczne zamykanie Korekt poza KSeF

## Preflight

- Gałąź: `main`.
- HEAD rozszerzenia: `6eb75674e0d01a049e27dfdc9c767a3a35645804` (`poprawka ksef-korekty3`). Pierwszy etap bazował na `4d42b7d23bb8f34f1212287dc2c1d3adf27757f9`.
- Worktree przed rozpoczęciem był czysty. Istniejące zmiany nie były cofane.
- Bez operacji na VPS, commitów, pushowania i wdrożenia.

## Audyt

- `InvoiceFinalizationService::finalize()` jest jedynym wykonawcą finalizacji. Ustawia `finalized_at`, zwalnia slot bieżącej Korekty i zachowuje jej tożsamość oraz cały łańcuch. Ponowne wywołanie jest idempotentne.
- `CorrectionSourceStateService` weryfikuje liniowy łańcuch i slot. Następna Korekta wykorzystuje stan `AFTER` poprzedniej zamkniętej Korekty.
- `KsefFa3CorrectionFinalizationGate` nie jest polityką zgody na ręczne zamknięcie poza KSeF. Przy nieaktywnej integracji lub wyłączonej serii pomija preflight FA(3). Nie został zmieniony.
- `KsefFa3CorrectionSourceReferenceResolver` nadal wymaga zaakceptowanej referencji KSeF wcześniejszych Korekt. Mieszany łańcuch `OutsideKsef -> Korekta poza KSeF -> Korekta w serii KSeF` pozostaje zablokowany kodem `ksef_fa3_correction_previous_ksef_not_accepted`.
- Starszy opis finalizacji w `docs/architecture.md` i `docs/product-spec.md` mówi o braku zdarzeń. Aktualny kod emituje `CorrectionFinalized` po zatwierdzeniu transakcji oraz zapisuje zdarzenie `correction_finalized` w historii powiązanego zwrotu. Implementacja zachowuje obecny kod i te efekty.

## Dostępność i bezpieczeństwo

Przycisk **Zamknij korektę** występuje tylko na karcie edycji wystawionej, niezamkniętej Korekty z poprawnym slotem i łańcuchem. Nie występuje na formularzu tworzenia ani na zamkniętym dokumencie.

Warunki są konserwatywne:

- Wcześniejsze Korekty są zamknięte i mają własne jawne oznaczenia `OutsideKsef` w aktywnym środowisku. Nie są automatycznie klasyfikowane ani uzupełniane.
- Faktura pierwotna może być jeszcze otwarta lub nie mieć oznaczenia OutsideKsef. Wtedy operator musi osobno potwierdzić jej pochodzenie w aktywnym środowisku. Faktura zostaje zamknięta istniejącym serwisem i oznaczona OutsideKsef w tej samej transakcji co Korekta.
- Poprawne, istniejące OutsideKsef Faktury pierwotnej nie wymaga powtórnego potwierdzenia. Oznaczenie w innym środowisku nie jest dowodem dla aktywnego środowiska i wymaga nowego, jawnego potwierdzenia.
- Żadna seria dokumentów w łańcuchu nie jest włączona do KSeF, także przy globalnie wyłączonej integracji.
- Żaden dokument w łańcuchu nie ma historii transmisji Online, dokumentu Offline ani korekty technicznej Offline w jakimkolwiek środowisku.
- Operator osobno potwierdza, że bieżąca Korekta jest wystawiana poza KSeF i nie podlega transmisji we wskazanym środowisku. Checkbox nie jest domyślnie zaznaczony.
- Samo wyłączenie serii, integracji lub deployment gate nie stanowi dowodu wystawienia poza KSeF.

Endpoint `POST /invoices/corrections/{correction}/finalize` korzysta z istniejącej grupy `web`, CSRF i tego samego modelu autoryzacji co pozostałe trasy Korekt. Nie dodaje nowego systemu logowania. Ochrona panelu przez zewnętrzny Basic Auth pozostaje obowiązkiem konfiguracji serwera.

Backend wymaga `expected_lock_version`, `expected_ksef_environment`, `expected_source_invoice_id`, `expected_source_invoice_lock_version` i `confirm_outside_ksef`. Jeśli Faktura pierwotna nie ma jeszcze poprawnego OutsideKsef w aktywnym środowisku, wymaga także `confirm_source_outside_ksef`.

Polityka rozdziela możliwość rozpoczęcia potwierdzenia (`review` / `inspect`) od końcowej zgody (`assertAllowed`). Brak oznaczenia Faktury nie ukrywa przycisku, ale bez jej osobnego potwierdzenia backend niczego nie zapisuje. Dla pozostałych blokad karta pokazuje konkretny powód niedostępności akcji.

W jednej transakcji serwis blokuje zamówienie, dokumenty łańcucha, konfigurację i serie oraz ponownie sprawdza warunki i oczekiwane wersje obu dokumentów. W razie potrzeby wywołuje `InvoiceFinalizationService::finalize()` dla Faktury i `KsefInvoiceProvenanceService::markOutsideKsef()`, następnie sprawdza końcową zgodę, finalizuje Korektę i zapisuje jej własne OutsideKsef. Korzysta z istniejącej tabeli `ksef_invoice_provenances`; nie wymaga migracji.

Samo wystawienie otwartej Korekty nadal nie finalizuje Faktury. Usunięcie takiej Korekty może odblokować niezfinalizowaną Fakturę. Dopiero zatwierdzone zamknięcie pary trwale blokuje oba dokumenty. Widok Faktury korzysta z istniejącego komunikatu zamknięcia i przestaje sugerować usunięcie Korekty.

Publiczne nazwy metod i trasa pozostają zachowane. Nowe argumenty serwisu są opcjonalne dla dotychczasowych wewnętrznych wywołań dotyczących już zamkniętej i oznaczonej Faktury. Nie pozwalają jednak sklasyfikować niepotwierdzonego źródła bez jawnej zgody i oczekiwanego ID oraz wersji. Starszy formularz HTTP bez nowych pól wymaga odświeżenia i jest odrzucany bez zmian danych.

Powtórne żądanie zamknięcia tego samego dokumentu nie zmienia daty, slotu następnej Korekty ani zdarzeń. Nieaktualna wersja dokumentu jest odrzucana. Niepowodzenie zapisu pochodzenia cofa całą transakcję, także finalizację i zdarzenie zwrotu.

Własne oznaczenie Korekty blokuje późniejsze przygotowanie do wysyłki Online, a istniejące reguły blokują również wystawienie Offline. Kontrolki wysyłki na karcie Korekty i zamówienia uwzględniają to oznaczenie. Pozostała ścieżka pierwszej wysyłki, automatyczna finalizacja, XML FA(3), numeracja i obliczenia pozostają bez zmian.

## Testy

Wszystkie testy PHP uruchomiono przez `C:\xampp\php\php.exe tests/Support/Invoices/run-isolated.php`. Runner wymusza SQLite `:memory:`, prywatne pliki robocze oraz blokuje rzeczywiste połączenia sieciowe. Nowe testy dodatkowo używają `Http::preventStrayRequests()`.

### Pierwszy etap: baseline historyczny

Filtr:

```text
InvoiceFinalizationTest|CorrectionSourceOptimisticLockingTest|KsefFa3CorrectionFinalizationGateTest|KsefFa3CorrectionSourceReferenceResolverTest|KsefManualCorrectionSubmissionTest|OrderReturnCorrectionTest
```

Wynik: **121 zaliczonych, 1 niepowodzenie, 879 asercji**.

Jedyny błąd: `KsefManualCorrectionSubmissionTest::test_automatic_first_submission_policy_still_excludes_corrections`, ponieważ `Queue::assertNothingPushed()` wykrywa `Modules\Automation\Jobs\EvaluateAutomationEventJob`. Nie był naprawiany w tym zadaniu.

### Pierwszy etap: wyniki historyczne

- `--filter=ManualCorrectionFinalizationTest`: **43 zaliczone, 315 asercji**.
- Filtr baseline rozszerzony o `|ManualCorrectionFinalizationTest`: **164 zaliczone, 1 to samo niepowodzenie, 1194 asercje**. Nowe niepowodzenia: **0**. Niepowodzenia ustępujące: **0**. Wspólne: **1**.
- Dodatkowy filtr `OrderSalesDocumentActionsTest|OrderInvoiceAjaxTest|OrderReturnCorrectionsTest|KsefManualInvoiceSubmissionTest|KsefOfflineCorrectionTest`: **322 zaliczone, 3251 asercji, 0 niepowodzeń**.
- `node --test tests/Frontend/*.test.cjs`: **39 zaliczonych, 0 niepowodzeń**.
- `vendor/bin/pint --test` dla wszystkich zmienionych klas PHP, tras i nowego testu: **PASS**.
- `php -l` dla tych samych dziewięciu plików PHP: **PASS**.
- `git diff --check`: **PASS**.

Uwaga: historyczny filtr baseline zawiera nazwę `OrderReturnCorrectionTest` w liczbie pojedynczej, która nie wybiera istniejącej klasy `OrderReturnCorrectionsTest`. Właściwa klasa jest objęta osobnym, dodatkowym zestawem powyżej.

### Rozszerzenie: zamknięcie pary dokumentów

Baseline bieżącego HEAD uruchomiony przed edycją:

```text
ManualCorrectionFinalizationTest|InvoiceFinalizationTest|CorrectionSourceOptimisticLockingTest|KsefFa3CorrectionFinalizationGateTest|KsefFa3CorrectionSourceReferenceResolverTest|KsefManualCorrectionSubmissionTest
```

Wynik bazowy: **164 zaliczone, 1 znane niepowodzenie automatyzacji, 1194 asercje**.

- `--filter=ManualCorrectionFinalizationTest`: **69 zaliczonych, 654 asercje**. Dwa wcześniejsze przypadki braku oznaczenia źródła zostały zastąpione scenariuszami z osobnym potwierdzeniem, jego brakiem i ponowną weryfikacją środowiska.
- `--filter=CorrectionSourceOptimisticLockingTest|ManualCorrectionFinalizationTest`: **74 zaliczone, 697 asercji**. Nowe pole HTTP `expected_source_invoice_lock_version` nie koliduje z istniejącym polem wersji źródła formularza tworzenia Korekty.
- Ten sam filtr regresji po zmianach: **190 zaliczonych, 1 to samo niepowodzenie automatyzacji, 1533 asercje**. Nowe niepowodzenia: **0**. Niepowodzenia ustępujące: **0**. Wspólne: **1**. Znany błąd dotyczy `KsefManualCorrectionSubmissionTest::test_automatic_first_submission_policy_still_excludes_corrections` i nieoczekiwanego `EvaluateAutomationEventJob` w `QueueFake`.
- Dodatkowy filtr `OrderSalesDocumentActionsTest|OrderInvoiceAjaxTest|OrderReturnCorrectionsTest|KsefManualInvoiceSubmissionTest|KsefOfflineCorrectionTest`: **322 zaliczone, 3251 asercji, 0 niepowodzeń**.
- `node --test tests/Frontend/*.test.cjs`: **40 zaliczonych, 0 niepowodzeń**.
- Pint, `php -l` pięciu zmienionych plików PHP i `git diff --check`: **PASS**.

Nowe testy obejmują otwartą i zamkniętą Fakturę bez oznaczenia, oznaczenie wyłącznie w innym środowisku, oddzielne zgody, brak lub konflikt ID/wersji źródła, rollback obu dokumentów i obu oznaczeń, replay, przeplot żądań, niezmienność treści i numeracji, brak sugestii usuwania Korekty po zamknięciu, utworzenie kolejnego ogniwa oraz pojawienie się blokad KSeF/Offline przed zapisem.

Sprawdzono w przeglądarce przycisk i obie niezaznaczone zgody na BLK 6/2026 do BL 20/2026, a następnie anulowano okno. Nie zatwierdzano żadnej operacji na tych dokumentach. Odczyt bazy z wymuszonym trybem tylko do odczytu potwierdził `finalized_at = null`, `lock_version = 1` dla obu dokumentów i brak oznaczeń OutsideKsef.

W rozszerzeniu zmieniono wyłącznie osiem istniejących plików kodu/testów oraz ten raport: `CorrectionController`, `FinalizeCorrectionRequest`, `ManualCorrectionFinalizationPolicy`, `ManualCorrectionFinalizationService`, `_finalize-modal.blade.php`, `corrections/create.blade.php`, `ManualCorrectionFinalizationTest.php`, `correction-finalization.test.cjs`. Nie utworzono ani nie usunięto plików źródłowych.

## Pliki bieżącego rozszerzenia

Zmienione:

- `Modules/Invoices/Http/Controllers/CorrectionController.php`
- `Modules/Invoices/Http/Requests/FinalizeCorrectionRequest.php`
- `Modules/Invoices/Services/ManualCorrectionFinalizationPolicy.php`
- `Modules/Invoices/Services/ManualCorrectionFinalizationService.php`
- `resources/views/invoices/corrections/_finalize-modal.blade.php`
- `resources/views/invoices/corrections/create.blade.php`
- `tests/Feature/Invoices/ManualCorrectionFinalizationTest.php`
- `tests/Frontend/correction-finalization.test.cjs`
- `docs/manual-correction-finalization-report.md`

Utworzone lub usunięte pliki źródłowe: brak. Migracje: brak. Nie uruchamiano migracji na bazie użytkownika. Prywatny, ignorowany zrzut potwierdzenia UI zapisano w `storage/app/private/codex-qa/manual-correction-pair-confirmation.png`.

## Weryfikacja ręczna

1. W środowisku testowym otwórz Korektę spełniającą powyższe warunki. Sprawdź przycisk **Zamknij korektę** w górnym pasku.
2. Otwórz potwierdzenie. Dla Faktury bez oznaczenia sprawdź dwa osobne, domyślnie niezaznaczone checkboxy oraz ostrzeżenie o zamknięciu Faktury, jeśli jest otwarta. Anuluj; oba dokumenty powinny pozostać bez zmian.
3. Na fikcyjnych dokumentach testowych zaznacz wymagane potwierdzenia OutsideKsef i zamknij Korektę. Sprawdź zamknięcie obu dokumentów, komunikat sukcesu, zachowanie kontekstu Powrotu oraz brak edycji i usuwania. Jeśli Faktura miała już poprawne OutsideKsef, sprawdź brak dodatkowego checkboxa źródła i zachowanie jej wcześniejszej daty zamknięcia.
4. Utwórz następną Korektę. Jej dane przed zmianą powinny odpowiadać danym po poprzedniej Korekcie.
5. Dla dokumentu obsługiwanego przez KSeF przycisk ręcznego zamknięcia nie powinien być dostępny. Nie wysyłaj rzeczywistych dokumentów w celu sprawdzenia interfejsu.

## Ograniczenia

- Nie uruchamiano pełnego zestawu wszystkich modułów OMS.
- Nie testowano na osobnym MySQL 8 ani rzeczywistego wyścigu dwóch połączeń MySQL. Test przeplotu żądań używa SQLite i dwóch wejść do serwisu; dodatkowo sprawdzono replay, konflikt rzeczywistej edycji i rollback. Blokady rekordów wymagają osobnej próby współbieżności na jednorazowej bazie MySQL przed wdrożeniem o takim wymaganiu.
- Nie sprawdzano konfiguracji Basic Auth na VPS. UI na danych użytkownika sprawdzono tylko przez otwarcie i anulowanie potwierdzenia. Nie zmieniano danych dokumentów w bazie roboczej.
- Operator odpowiada za prawdziwość osobnego potwierdzenia OutsideKsef. OMS nie ustala prawnego zwolnienia automatycznie i nie nadaje go przez wyłączenie serii.
- Bez backfillu, oznaczania starszych dokumentów i zmiany konserwatywnej polityki referencji mieszanego łańcucha.
