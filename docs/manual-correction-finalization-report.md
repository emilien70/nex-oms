# Ręczne zamykanie Korekt poza KSeF

## Preflight

- Gałąź: `main`.
- HEAD: `4d42b7d23bb8f34f1212287dc2c1d3adf27757f9` (`poprawka ksef-korekty2`).
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

- Faktura pierwotna oraz wcześniejsze Korekty są zamknięte i mają własne jawne oznaczenia `OutsideKsef` w aktywnym środowisku.
- Żadna seria dokumentów w łańcuchu nie jest włączona do KSeF, także przy globalnie wyłączonej integracji.
- Żaden dokument w łańcuchu nie ma historii transmisji Online, dokumentu Offline ani korekty technicznej Offline w jakimkolwiek środowisku.
- Operator osobno potwierdza, że bieżąca Korekta jest wystawiana poza KSeF i nie podlega transmisji we wskazanym środowisku. Checkbox nie jest domyślnie zaznaczony.
- Samo wyłączenie serii, integracji lub deployment gate nie stanowi dowodu wystawienia poza KSeF.

Endpoint `POST /invoices/corrections/{correction}/finalize` korzysta z istniejącej grupy `web`, CSRF i tego samego modelu autoryzacji co pozostałe trasy Korekt. Nie dodaje nowego systemu logowania. Ochrona panelu przez zewnętrzny Basic Auth pozostaje obowiązkiem konfiguracji serwera.

Backend wymaga `expected_lock_version`, `expected_ksef_environment` i `confirm_outside_ksef`. W jednej transakcji blokuje zamówienie, dokumenty łańcucha, konfigurację i serie, ponownie sprawdza warunki, wywołuje istniejący serwis finalizacji i zapisuje własne `OutsideKsef` Korekty. Korzysta z istniejącej tabeli `ksef_invoice_provenances`; nie wymaga migracji.

Powtórne żądanie zamknięcia tego samego dokumentu nie zmienia daty, slotu następnej Korekty ani zdarzeń. Nieaktualna wersja dokumentu jest odrzucana. Niepowodzenie zapisu pochodzenia cofa całą transakcję, także finalizację i zdarzenie zwrotu.

Własne oznaczenie Korekty blokuje późniejsze przygotowanie do wysyłki Online, a istniejące reguły blokują również wystawienie Offline. Kontrolki wysyłki na karcie Korekty i zamówienia uwzględniają to oznaczenie. Pozostała ścieżka pierwszej wysyłki, automatyczna finalizacja, XML FA(3), numeracja i obliczenia pozostają bez zmian.

## Testy

Wszystkie testy PHP uruchomiono przez `C:\xampp\php\php.exe tests/Support/Invoices/run-isolated.php`. Runner wymusza SQLite `:memory:`, prywatne pliki robocze oraz blokuje rzeczywiste połączenia sieciowe. Nowe testy dodatkowo używają `Http::preventStrayRequests()`.

### Baseline przed zmianą

Filtr:

```text
InvoiceFinalizationTest|CorrectionSourceOptimisticLockingTest|KsefFa3CorrectionFinalizationGateTest|KsefFa3CorrectionSourceReferenceResolverTest|KsefManualCorrectionSubmissionTest|OrderReturnCorrectionTest
```

Wynik: **121 zaliczonych, 1 niepowodzenie, 879 asercji**.

Jedyny błąd: `KsefManualCorrectionSubmissionTest::test_automatic_first_submission_policy_still_excludes_corrections`, ponieważ `Queue::assertNothingPushed()` wykrywa `Modules\Automation\Jobs\EvaluateAutomationEventJob`. Nie był naprawiany w tym zadaniu.

### Po zmianie

- `--filter=ManualCorrectionFinalizationTest`: **43 zaliczone, 315 asercji**.
- Filtr baseline rozszerzony o `|ManualCorrectionFinalizationTest`: **164 zaliczone, 1 to samo niepowodzenie, 1194 asercje**. Nowe niepowodzenia: **0**. Niepowodzenia ustępujące: **0**. Wspólne: **1**.
- Dodatkowy filtr `OrderSalesDocumentActionsTest|OrderInvoiceAjaxTest|OrderReturnCorrectionsTest|KsefManualInvoiceSubmissionTest|KsefOfflineCorrectionTest`: **322 zaliczone, 3251 asercji, 0 niepowodzeń**.
- `node --test tests/Frontend/*.test.cjs`: **39 zaliczonych, 0 niepowodzeń**.
- `vendor/bin/pint --test` dla wszystkich zmienionych klas PHP, tras i nowego testu: **PASS**.
- `php -l` dla tych samych dziewięciu plików PHP: **PASS**.
- `git diff --check`: **PASS**.

Uwaga: historyczny filtr baseline zawiera nazwę `OrderReturnCorrectionTest` w liczbie pojedynczej, która nie wybiera istniejącej klasy `OrderReturnCorrectionsTest`. Właściwa klasa jest objęta osobnym, dodatkowym zestawem powyżej.

## Pliki

Utworzone:

- `Modules/Invoices/Http/Requests/FinalizeCorrectionRequest.php`
- `Modules/Invoices/Services/ManualCorrectionFinalizationPolicy.php`
- `Modules/Invoices/Services/ManualCorrectionFinalizationService.php`
- `resources/views/invoices/corrections/_finalize-modal.blade.php`
- `tests/Feature/Invoices/ManualCorrectionFinalizationTest.php`
- `tests/Frontend/correction-finalization.test.cjs`
- `docs/manual-correction-finalization-report.md`

Zmienione:

- `Modules/Invoices/Http/Controllers/CorrectionController.php`
- `Modules/Invoices/Services/OrderSalesDocumentActionsView.php`
- `Modules/Ksef/Services/KsefDocumentViewData.php`
- `Modules/Ksef/Services/KsefInvoiceSubmissionService.php`
- `resources/views/invoices/corrections/create.blade.php`
- `resources/views/orders/partials/correction-ksef-action.blade.php`
- `routes/web.php`

Usunięte: brak. Migracje: brak. Nie uruchamiano migracji na bazie użytkownika.

## Weryfikacja ręczna

1. W środowisku testowym otwórz Korektę spełniającą powyższe warunki. Sprawdź przycisk **Zamknij korektę** w górnym pasku.
2. Otwórz potwierdzenie, sprawdź pełne ostrzeżenie i domyślnie niezaznaczony checkbox. Anuluj; dokument powinien pozostać otwarty.
3. Na fikcyjnym dokumencie testowym zaznacz osobne potwierdzenie OutsideKsef i zamknij Korektę. Sprawdź komunikat sukcesu, zachowanie kontekstu Powrotu oraz brak edycji i usuwania.
4. Utwórz następną Korektę. Jej dane przed zmianą powinny odpowiadać danym po poprzedniej Korekcie.
5. Dla dokumentu obsługiwanego przez KSeF przycisk ręcznego zamknięcia nie powinien być dostępny. Nie wysyłaj rzeczywistych dokumentów w celu sprawdzenia interfejsu.

## Ograniczenia

- Nie uruchamiano pełnego zestawu wszystkich modułów OMS.
- Nie testowano na osobnym MySQL 8 ani rzeczywistego wyścigu dwóch połączeń MySQL. Test przeplotu żądań używa SQLite i dwóch wejść do serwisu; dodatkowo sprawdzono replay, konflikt rzeczywistej edycji i rollback. Blokady rekordów wymagają osobnej próby współbieżności na jednorazowej bazie MySQL przed wdrożeniem o takim wymaganiu.
- Nie sprawdzano konfiguracji Basic Auth na VPS ani działania UI na danych użytkownika. Nie zmieniano danych dokumentów w bazie roboczej.
- Operator odpowiada za prawdziwość osobnego potwierdzenia OutsideKsef. OMS nie ustala prawnego zwolnienia automatycznie i nie nadaje go przez wyłączenie serii.
- Bez backfillu, oznaczania starszych dokumentów i zmiany konserwatywnej polityki referencji mieszanego łańcucha.
