# Zasady pracy nad NEX-OMS

- NEX-OMS jest modularnym monolitem.
- Kazdy wiekszy obszar systemu ma wlasny modul.
- Kontrolery nie powinny zawierac logiki biznesowej.
- Logika biznesowa powinna byc w serwisach.
- Integracje API powinny byc w osobnych modulach.
- Kazde zewnetrzne API powinno miec logowanie request/response.
- Operacje API docelowo powinny dzialac przez kolejki.
- Numery seryjne sa osobnym modulem, nie zwyklym polem tekstowym.
- Zamowienia z Allegro i PrestaShop maja trafiac do wspolnego modelu Orders.
- Panel konfiguracji przyszlych integracji kurierskich powinien korzystac ze wzorca UX zastosowanego w `InPost Kurier`.
- Wspolny wzorzec kurierski obejmuje panel operacyjny przesylek, filtry, akcje zbiorcze, wspolna paginacje, konfiguracje konta w modalu i dodatkowe sekcje zalezne od mozliwosci przewoznika.
- Nie nalezy wymuszac na integracji kurierskiej opcji, ktorych dany przewoznik lub jego API nie obsluguje.
