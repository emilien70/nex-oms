<?php

namespace Tests\Support;

final class KsefUpoFixture
{
    public const CONTEXT_NIP = '9876543210';

    public const SELLER_NIP = '9876543210';

    public const SESSION_REFERENCE = '20260821-SO-ABCDEF1234-123456789A-BC';

    public const INVOICE_REFERENCE = '20260821-FA-123456789A-ABCDEF1234-CD';

    /** @param array<string, string|bool> $overrides */
    public static function xml(array $overrides = []): string
    {
        $values = array_replace([
            'context_nip' => self::CONTEXT_NIP,
            'seller_nip' => self::SELLER_NIP,
            'session_reference' => self::SESSION_REFERENCE,
            'ksef_number' => self::ksefNumber(self::SELLER_NIP),
            'invoice_number' => 'FV 1/2026',
            'invoice_hash' => base64_encode(hash('sha256', '<Faktura>TEST</Faktura>', true)),
            'mode' => 'Online',
            'namespace' => 'http://upo.schematy.mf.gov.pl/KSeF/v4-3',
            'version' => '4-3',
            'logical_structure' => 'Schemat_FA(3)_v1-0E.xsd',
            'form_code' => 'FA (3)',
            'duplicate_document' => false,
        ], $overrides);
        $document = self::document($values);
        $documents = $document.($values['duplicate_document'] ? $document : '');

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<Potwierdzenie xmlns="'.self::escape($values['namespace']).'" wersjaSchemy="'.self::escape($values['version']).'">'."\n"
            .'  <NazwaPodmiotuPrzyjmujacego>Ministerstwo Finansów</NazwaPodmiotuPrzyjmujacego>'."\n"
            .'  <NumerReferencyjnySesji>'.self::escape($values['session_reference']).'</NumerReferencyjnySesji>'."\n"
            .'  <Uwierzytelnienie>'."\n"
            .'    <IdKontekstu><Nip>'.self::escape($values['context_nip']).'</Nip></IdKontekstu>'."\n"
            .'    <SkrotDokumentuUwierzytelniajacego>'.base64_encode(hash('sha256', 'synthetic-auth-document', true)).'</SkrotDokumentuUwierzytelniajacego>'."\n"
            .'  </Uwierzytelnienie>'."\n"
            .'  <NazwaStrukturyLogicznej>'.self::escape($values['logical_structure']).'</NazwaStrukturyLogicznej>'."\n"
            .'  <KodFormularza>'.self::escape($values['form_code']).'</KodFormularza>'."\n"
            .$documents
            .'</Potwierdzenie>';
    }

    public static function ksefNumber(string $sellerNip = self::SELLER_NIP): string
    {
        $base = $sellerNip.'-20260821-0100001AF629';
        $checksum = 0;

        foreach (str_split($base) as $character) {
            $checksum ^= ord($character);
            for ($bit = 0; $bit < 8; $bit++) {
                $checksum = ($checksum & 0x80) !== 0
                    ? (($checksum << 1) ^ 0x07) & 0xFF
                    : ($checksum << 1) & 0xFF;
            }
        }

        return $base.'-'.strtoupper(str_pad(dechex($checksum), 2, '0', STR_PAD_LEFT));
    }

    /** @param array<string, string|bool> $values */
    private static function document(array $values): string
    {
        return '  <Dokument>'."\n"
            .'    <NipSprzedawcy>'.self::escape($values['seller_nip']).'</NipSprzedawcy>'."\n"
            .'    <NumerKSeFDokumentu>'.self::escape($values['ksef_number']).'</NumerKSeFDokumentu>'."\n"
            .'    <NumerFaktury>'.self::escape($values['invoice_number']).'</NumerFaktury>'."\n"
            .'    <DataWystawieniaFaktury>2026-08-21</DataWystawieniaFaktury>'."\n"
            .'    <DataPrzeslaniaDokumentu>2026-08-21T10:00:00Z</DataPrzeslaniaDokumentu>'."\n"
            .'    <DataNadaniaNumeruKSeF>2026-08-21T10:00:01Z</DataNadaniaNumeruKSeF>'."\n"
            .'    <SkrotDokumentu>'.self::escape($values['invoice_hash']).'</SkrotDokumentu>'."\n"
            .'    <TrybWysylki>'.self::escape($values['mode']).'</TrybWysylki>'."\n"
            .'  </Dokument>'."\n";
    }

    private static function escape(string|bool $value): string
    {
        return htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
