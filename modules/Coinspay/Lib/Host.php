<?php

namespace Box\Mod\Coinspay\Lib;

use PDO;

/**
 * What the module needs of FOSSBilling. FossbillingHost is the real one; the unit tests have a fake.
 *
 * FOSSBilling pays an invoice from the balance of its client: a payment adds funds to the balance,
 * then the invoice is paid with them when the balance covers it.
 */
interface Host
{
    /** PDO of the FOSSBilling database (MySQL or MariaDB). */
    public function pdo(): PDO;

    /**
     * What tells this FOSSBilling from another on the same CoinsPay shop or database server, the
     * same for every request: the configured address and the database.
     */
    public function siteKey(): string;

    /** Whether a database transaction is open on the connection. */
    public function inTransaction(): bool;

    /** Language of the admin texts: the default locale of FOSSBilling. */
    public function adminLanguage(): string;

    /** Language of the person on the page now. */
    public function userLanguage(): string;

    /** Whether the person on the page now is a signed-in FOSSBilling admin. */
    public function isAdmin(): bool;

    /** The client signed in on the page now, or null. */
    public function currentClient(): ?int;

    /**
     * An invoice, fresh from the database.
     *
     * @return array|null {id, number, client_id, status (unpaid|paid|canceled|refunded), approved,
     *                    deposit (an "add funds" invoice), total (with tax), currency, hash}
     */
    public function invoice(int $invoiceId): ?array;

    /**
     * A client, fresh from the database.
     *
     * @return array|null {id, currency, balance}
     */
    public function client(int $clientId): ?array;

    /**
     * What the module added to the balance of a client for one CoinsPay invoice, in total.
     *
     * @param  string  $reference  Sync::reference() of the CoinsPay invoice.
     */
    public function credited(int $clientId, string $reference): string;

    /**
     * Adds funds to the balance of a client for one CoinsPay invoice: a row of the balance with
     * the type "coinspay" and the reference of the CoinsPay invoice.
     */
    public function addFunds(int $clientId, string $amount, string $description, string $reference): void;

    /**
     * Pays an unpaid invoice from the balance of its client, if the balance covers it; an "add
     * funds" invoice is marked paid instead, when the CoinsPay invoices of it brought its total
     * (its money is the balance itself).
     *
     * @param  array  $references  Sync::reference() of the CoinsPay invoices of an "add funds" invoice.
     * @return bool Whether the invoice is paid now.
     */
    public function payInvoice(int $invoiceId, array $references = []): bool;

    /**
     * Shows the payment on the invoice in the admin area: one transaction row per CoinsPay
     * invoice, with its id as the transaction id, its amount raised as more is credited.
     */
    public function recordTransaction(int $invoiceId, string $transactionId, string $amount, string $currency, string $note): void;

    /** Currency codes of FOSSBilling. */
    public function currencies(): array;

    /** Address of an invoice page for the client. */
    public function invoiceUrl(int $invoiceId): string;

    /** Address of the "payment received, it may take a moment" page of an invoice. */
    public function thankYouUrl(int $invoiceId): string;

    /** Address of an invoice in the admin area. */
    public function adminInvoiceUrl(int $invoiceId): string;

    /** Address of a route of the module: webhook or return. */
    public function routeUrl(string $name, array $query = []): string;

    /**
     * A line of the log.
     *
     * @param  string  $level  info, warning or error.
     */
    public function log(string $level, string $message, array $context = []): void;
}
