# PDF names and e-mail attachment references

## Scope

Local implementation, 2026-11-03. No VPS operations, real SMTP, Allegro
uploads or KSeF transmissions. No invoice data, numbering, financial
calculations or KSeF configuration changes.

Standard downloads, the order-card KSeF PDF download name, customer portal,
new e-mails and new Allegro exports use the same filename generator:

- `Faktura N_6-2026.pdf`
- `Korekta NK_2-2026.pdf`
- `Proforma PF_1-2026.pdf`

Invoice numbers, PDF contents/layout, internal cache paths, XML/UPO and
special Offline presentation names are unchanged.

## Attachment lifecycle

New e-mails store immutable source references, not attachment copies.
`source_mode=document` binds the exact invoice ID, type, number and order.
`template_file` points to the original selected template file. Content is
read into memory only when the queue builds the e-mail. Missing invoice
cache is recreated by the existing PDF service. No retention lock or
`order-email-messages/{id}` directory is created for new references.

The explicitly accepted behavior is current PDF/source bytes at send time,
not the bytes available when the e-mail was queued. A missing source or
invalid/oversized PDF stops the e-mail before SMTP; another invoice is never
silently substituted. No exact archived file is retained for new e-mails.

The manifest seals the source mode and canonical source JSON in addition
to the existing metadata. JSON key order is irrelevant; value types remain
significant. Old rows (`source_mode=null`) retain their original manifest
format, copied file, checksum verification and filename. The existing
idempotency, recipient checks, SMTP fingerprint, claim and unknown-result
policy remain in place.

The UI shows escaped, non-clickable attachment names. The old private
download endpoint remains compatible with legacy copies only; references
are not downloadable from message history. Deleting a message does not
delete a referenced template file or the invoice cache. No historical
attachment cleanup or backfill is part of this change.

## Allegro compatibility

Already frozen export filenames and files are retained. Before freezing a
new export, both the new name and the exact previous download name are
recognized in the linked Allegro order only. Matching a previous name
preserves that name in the export and performs no new upload. Ambiguous
matches, including simultaneous old/new names, remain uncertain. UUID
filenames keep their original stricter invoice-number check. Security
verification and private export copies are unchanged.

## Migration

`2026_11_03_010000_add_sources_to_order_email_attachments` adds nullable
`source_mode` and widens nullability of `file_path`, `file_size` and
`checksum_sha256`. Existing values, relations and indexes are preserved.
The new null values mean no materialized attachment snapshot, not an empty
file. Down is blocked when reference attachments or null source-file
metadata exist, rather than destroying references or fabricating bytes.

After a verified database backup, run only this migration:

```powershell
C:\xampp\php\php.exe artisan migrate --path=database/migrations/2026_11_03_010000_add_sources_to_order_email_attachments.php --force
```

On Linux use `php` instead of the Windows executable. Migration must precede
loading the new preparation code in workers. The shared group/default ACL
setup in [deployment](006-Deployment.md) is still required; the code does
not configure Linux users or repair all existing permissions by itself.
Once references have been created, rolling back only to snapshot-only code
is not safe. Old workers must not consume new reference messages. No
automatic schema rollback or restoration over current business data is
part of this feature.

## Changed files

Application:

- `app/Enums/OrderEmailAttachmentSource.php` (new)
- `app/Services/OrderEmailAttachmentResolver.php` (new)
- `app/Services/OrderEmailAttachmentFreezer.php`
- `app/Services/OrderEmailAttachmentManifest.php`
- `app/Services/OrderEmailSender.php`
- `app/Models/OrderEmailMessageAttachment.php`
- `app/Http/Controllers/OrderEmailMessageController.php`
- `Modules/Invoices/Services/InvoicePdfFilenameGenerator.php`
- `Modules/Invoices/Services/OrderSalesDocumentActionsView.php`
- `Modules/Integrations/Allegro/Services/AllegroInvoiceExportService.php`
- `resources/views/components/order-email-message-item.blade.php`
- `database/migrations/2026_11_03_010000_add_sources_to_order_email_attachments.php` (new)

Tests:

- `tests/Feature/OrderEmailAttachmentReferenceTest.php` (new)
- `tests/Unit/Invoices/InvoicePdfFilenameGeneratorTest.php` (new)
- `tests/Feature/OrderEmailDispatchTest.php`
- `tests/Feature/OrderEmailManualUiTest.php`
- `tests/Feature/AllegroInvoiceExportTest.php`
- `tests/Feature/AllegroCorrectionExportTest.php`
- `tests/Feature/Invoices/InvoicePdfTest.php`
- `tests/Feature/Invoices/OrderSalesDocumentActionsTest.php`

Documentation: this report, `docs/006-Deployment.md`, `docs/architecture.md`
and `docs/product-spec.md`.

Earlier local permission changes are preserved in `config/filesystems.php`,
`Modules/Invoices/Services/InvoicePdfStorage.php` and the new
`tests/Feature/PrivateFilesystemTest.php`. No files were deleted.

## Verification

PHPUnit uses the checked-in `phpunit.xml`, PHP 8.2.12, SQLite `:memory:`,
fake storage, simulated SMTP and mocked HTTP. No real messages or
integrations were triggered.

Final focused regression: 502 tests / 3983 assertions, 501 passed and one
POSIX permission test skipped on Windows, no failures. This includes the
e-mail, invoice PDF/order-card naming, Allegro, portal, NEX Printer and
product/storage suites. It excludes the unrelated sales register tests
listed below; these were not removed or weakened.

Expanded regression: 1508 tests / 19834 assertions, 1505 passed, 1 POSIX
permission test skipped on Windows, 2 pre-existing failures:

| Test | Current result | Isolated HEAD a87d767 result |
| --- | --- | --- |
| `SalesRegisterJpkTest::test_more_than_one_batch_preserves_selected_scope_and_has_bounded_query_count` | 84 queries, expected fewer than 80 | Identical |
| `SalesRegisterXlsxTest::test_period_and_ids_over_batch_limit_match_html_and_do_not_write_or_read_ksef` | 15 queries, expected fewer than 15 | Identical |

Only these two failing tests were run on baseline, not the entire baseline
suite. Baseline came from `git archive HEAD` in an ignored private scratch
directory with the same PHP/SQLite/vendor dependencies. Composer PSR-4 and
classmap paths were remapped and verified to use the extracted application
code, not the modified workspace; both comparison runs used the same test
key. The original worktree and dependencies were not restored or rewritten.
Sales register code and query-count thresholds were not changed.

Pint passed on 22 PHP files; `php -l` passed on 23 files including Blade.
`git diff --check` passed. Shared filesystem probes in deployment docs were
syntax-checked, not executed on VPS.

The expanded run selected `tests/Feature/Invoices`, `tests/Unit/Invoices`,
the reference/dispatch/manual/foundation/recovery e-mail suites,
AutomationEmailActionTest, EmailTemplateSettingsTest, Allegro invoice and
correction export suites, CustomerPortal/Privacy/Payments tests, NexPrinter,
AutomationNexPrinter, ProductCatalog and PrivateFilesystem tests. It did
not run the separate KSeF test suite or real transmissions.

## Local migration result

Applied only `2026_11_03_010000_add_sources_to_order_email_attachments`,
batch 120. Backup via SQLite `VACUUM INTO`:

```text
storage/app/private/backups/before-email-attachment-references-20261103_152208-0c60473f.sqlite
SHA-256: f64cd35547392791ec3d71bcf02883f460c1a2ebeca0cd496adf6d2315a5c7c1
```

Backup and live `PRAGMA integrity_check`: OK. Comparing original-column
hashes/counts before and after confirmed unchanged orders, order items,
invoices, invoice items, series, counters, counter adjustments, document
slots, e-mail messages and attachment metadata. All 13 historical attachment
rows remain legacy; the same 13 files still occupy 982361 bytes. No old
file was removed or rewritten. The migration did not dispatch jobs.

## Manual check

1. Open an existing invoice/proforma/correction and check the downloaded name.
2. Check the order-card KSeF PDF download name on an already accepted test
   document; this implementation did not perform such a live download.
3. Verify that message history shows plain filenames without download links.
4. In an isolated test setup with a simulated mail transport, queue an e-mail,
   change the PDF before executing the job, and verify current bytes are used.
5. Remove only that test cache and verify standard regeneration; remove the
   source document/file and verify controlled failure without SMTP.
6. Confirm legacy messages/exports retain their original file names and that
   an old remote Allegro name does not cause another upload.

Native two-user Linux permission checks and a real MySQL migration remain
deployment checks; the local environment is Windows/PHP 8.2 with isolated
SQLite `:memory:` tests. No new PDF rendering or financial rules were added.
