# JPK_V7M(3), final 1-0E

Downloaded 2026-09-17 from the official CRD/MF sources. All four files are byte-for-byte originals. No types or constraints were modified.

| File | Official source | SHA-256 |
| --- | --- | --- |
| schemat.xsd | https://crd.gov.pl/wzor/2025/12/19/14090/schemat.xsd | 1870324001ba1318f0535841b963571e970b45ef32ba5ca3d433f0dc5da412df |
| KodyKrajow_v13-0E.xsd | http://crd.gov.pl/xml/schematy/dziedzinowe/mf/2023/09/06/eD/KodyKrajow/KodyKrajow_v13-0E.xsd | 447efc6e1a84de71d433666c60635856b8e01f090e5eedb3410b1afe545f8117 |
| KodyUrzedowSkarbowych_v8-0E.xsd | http://crd.gov.pl/xml/schematy/dziedzinowe/mf/2022/01/05/eD/KodyUrzedowSkarbowych/KodyUrzedowSkarbowych_v8-0E.xsd | af27f583ea1ceb44ed37e0ec0d9bfdf2bfda74aadd5841ccbf0fc241175c2e91 |
| StrukturyDanych_v12-0E.xsd | http://crd.gov.pl/xml/schematy/dziedzinowe/mf/2022/09/13/eD/DefinicjeTypy/StrukturyDanych_v12-0E.xsd | 01c45f7515f46cfa9bec843feef1409c126af3bee4d4e25f0325ae6323a9d512 |

The root imports exactly these three files. The imported files have no further imports/includes. The CRD dependency URLs use HTTP (HTTPS redirects to HTTP). Downloading these public resources sends no document data. The local .gitattributes disables line-ending conversion for these XSD files to preserve the original checksums across checkouts.

JpkV7m3SchemaValidator uses a temporary allowlisted libxml entity loader mapping the exact official import URLs to these local files. Other resources are denied; the previous loader is restored. Exports never download schemas, load user schemaLocation, DTD, external entities, XInclude or stylesheets.

Official references:
- https://www.podatki.gov.pl/podatki-firmowe/jednolity-plik-kontrolny/jpk_vat-z-deklaracja/pliki-do-pobrania (updated 2026-09-11)
- https://www.gov.pl/web/finanse/nowe-wzory-struktur-jpkvat-dostepne-na-epuap
- https://www.podatki.gov.pl/media/wgbkrejs/broszura-jpk_vat-z-deklaracj%C4%85-od-1-lutego-2026-r.pdf
- https://www.podatki.gov.pl/media/o1jppu0e/jpk_vat-z-deklaracja-stosowanie-znacznikow_v11.pdf

The XSD allows omission of Deklaracja. This sales-only accounting import is not a complete VAT return or evidence of acceptance by MF. CelZlozenia is not changed to 2 to evade validation.
