<?php

namespace Box\Mod\Coinspay\Lib;

use RuntimeException;

/**
 * Paying a FOSSBilling invoice: makes or reuses a CoinsPay invoice for what is due and sends the
 * client to its payment page. FOSSBilling calls the gateway when the client picks it on the
 * invoice: the client may be signed in, or hold the link of the invoice.
 *
 * What is due is what the client balance lacks: a part of an earlier CoinsPay invoice waits
 * there, and FOSSBilling pays the invoice from the balance once it covers the total. An "add
 * funds" invoice is the balance itself: for it only what earlier CoinsPay invoices of it brought
 * counts.
 */
final class Payment
{
    /** A closed invoice is polled one more day for a late payment. */
    public const POLL_AFTER_CLOSE = 86400;

    /** "Pay" pressed again within this many seconds reuses the open invoice without asking the API. */
    public const REUSE = 10;

    /** Currency of the CoinsPay invoice being made, for the error message. */
    private string $currency = '';

    public function __construct(private readonly Module $module) {}

    /**
     * @param  int  $fbInvoice  FOSSBilling invoice.
     * @return array {redirect} or {error}.
     */
    public function start(int $fbInvoice): array
    {
        $host = $this->module->host();
        $settings = $this->module->settings();
        $lang = Text::code($host->userLanguage());
        $text = new Text($lang);

        if (!$settings->active() || $settings->apiUrl() === '' || $settings->apiKey() === '') {
            return ['error' => $text->t('error_unavailable')];
        }
        if ($settings->isTestMode() && $settings->flag('test_admin_only') && !$host->isAdmin()) {
            return ['error' => $text->t('error_unavailable')];
        }

        $repo = $this->module->repo();
        if (!$repo->ensure()) {
            return ['error' => $text->t('error_unavailable')];
        }
        if (!$repo->lock($fbInvoice)) {
            return ['error' => $text->t('error_busy')];
        }
        try {
            return $this->startLocked($fbInvoice, $lang, $text);
        } catch (ApiError $e) {
            $host->log('error', 'invoice #' . $fbInvoice . ': ' . $e->summary());
            $key = $e->errorCode() === 'CURRENCY_NOT_SUPPORTED' ? 'error_currency' : 'error_api';

            return ['error' => $text->t($key, $this->currency)];
        } catch (RuntimeException $e) {
            $host->log('error', 'invoice #' . $fbInvoice . ': ' . $e->getMessage());

            return ['error' => $text->t('error_busy')];
        } finally {
            $repo->unlock($fbInvoice);
        }
    }

    private function startLocked(int $fbInvoice, string $lang, Text $text): array
    {
        $host = $this->module->host();
        $repo = $this->module->repo();
        $settings = $this->module->settings();
        $back = ['redirect' => $host->invoiceUrl($fbInvoice)];

        // The invoice now, not as the page saw it: paid, cancelled or deleted meanwhile — back to it.
        $fb = $host->invoice($fbInvoice);
        if (!$fb || $fb['status'] !== 'unpaid') {
            return $back;
        }
        $due = $this->due($fb);
        $owner = $host->client((int) $fb['client_id']);
        // The balance keeps the currency of the client: a payment in another one would not go there.
        if ($due === null || $owner === null || strtoupper((string) $owner['currency']) !== strtoupper((string) $fb['currency'])) {
            return ['error' => $text->t('error_unavailable')];
        }
        if (!Util::positive($due)) {
            // The balance covers it: paid from it, no CoinsPay invoice needed. Only for the client
            // or an admin: anyone else holding the link of the invoice must not spend the balance.
            if ($host->isAdmin() || $host->currentClient() === (int) $fb['client_id']) {
                $host->payInvoice($fbInvoice);
            }

            return $back;
        }

        $currency = $this->currency = strtoupper((string) $fb['currency']);
        [$due, $amount] = $this->amounts($due, $currency);
        if (!Util::positive($amount)) {
            return $back;
        }
        $test = $settings->isTestMode();
        $admin = $host->isAdmin();
        $client = $this->module->client();

        $current = $repo->current($fbInvoice);
        // A test invoice of an admin is not handed to a client: its payment would be added.
        if ($current && $current['status'] === 'open' && Util::compare($current['due'], $due, Decision::SCALE) === 0 && $current['currency'] === $currency
            && (bool) $current['test'] === $test && (!$test || (bool) $current['test_admin'] === $admin)
            && (int) $current['fb_client'] === (int) $fb['client_id']) {
            // Pressed again within seconds: the page it was given, without asking the API each time.
            if ((string) $current['pay_url'] !== '' && !$repo->takeTurn('start_' . $fbInvoice, self::REUSE)) {
                return ['redirect' => self::payPage(['pay_url' => $current['pay_url']], $lang)];
            }
            try {
                $invoice = $client->invoice($current['invoice_id']);
                if (Util::value($invoice, 'status') === 'open') {
                    return ['redirect' => self::payPage($invoice, $lang)];
                }
                // Paid or closed meanwhile: its state goes to FOSSBilling, and a new invoice is made if still due.
                $this->module->sync()->apply($invoice);
                $fb = $host->invoice($fbInvoice);
                $due = $fb && $fb['status'] === 'unpaid' ? $this->due($fb) : null;
                if ($due === null || !Util::positive($due)) {
                    return $back;
                }
                [$due, $amount] = $this->amounts($due, $currency);
            } catch (ApiError $e) {
                if (!$e->isNotFound()) {
                    throw $e;
                }
            }
        }

        $attempt = 1;
        foreach ($repo->forInvoice($fbInvoice) as $row) {
            $attempt = max($attempt, (int) $row['attempt'] + 1);
        }
        $body = InvoiceRequest::body(
            [
                'reference' => (string) $fbInvoice,
                'currency' => $currency,
                'amount' => $amount,
                'description' => $text->t('invoice_description', (string) $fb['number']),
                'return_url' => $this->module->returnUrl($fbInvoice),
            ],
            $settings
        );
        $invoice = $client->createInvoice($body, InvoiceRequest::idempotencyKey($host->siteKey(), $fbInvoice, $attempt, $body));
        if (Util::value($invoice, 'status') !== 'open') {
            // The key met an invoice made earlier and closed since — the tables were wiped, say. One more attempt.
            $attempt++;
            $invoice = $client->createInvoice($body, InvoiceRequest::idempotencyKey($host->siteKey(), $fbInvoice, $attempt, $body));
            if (Util::value($invoice, 'status') !== 'open') {
                // Never adopt an old invoice: its money belongs to another payment.
                throw new ApiError(0, 'INVOICE_NOT_OPEN', 'The idempotency key met closed invoices twice');
            }
        }

        $invoiceId = ApiClient::id(Util::value($invoice, 'id'));
        $page = self::payPage($invoice, $lang);
        if (!$repo->find($invoiceId)) {
            $repo->add([
                'invoice_id' => $invoiceId,
                'fb_invoice' => $fbInvoice,
                'fb_client' => (int) $fb['client_id'],
                'attempt' => $attempt,
                'amount' => $amount,
                'due' => $due,
                'currency' => $currency,
                'test' => !empty($invoice['test']) ? 1 : 0,
                'test_admin' => $admin ? 1 : 0,
                'status' => Util::value($invoice, 'status'),
                'pay_url' => Util::value($invoice, 'pay_url'),
                'snapshot' => Util::json(Sync::snapshot($invoice)),
                'next_poll' => time() + Sync::POLL_OPEN,
                'poll_until' => time() + $settings->lifetime() * 60 + self::POLL_AFTER_CLOSE,
            ]);
            $this->module->debug(sprintf('invoice #%d: CoinsPay invoice %s for %s %s', $fbInvoice, $invoiceId, $amount, $currency));
        }

        $this->cancelReplaced($fbInvoice, $invoiceId);
        // An older CoinsPay invoice found paid while cancelling may have paid it meanwhile.
        $now = $host->invoice($fbInvoice);
        if ($now === null || $now['status'] !== 'unpaid') {
            return $back;
        }

        return ['redirect' => $page];
    }

    /**
     * What is left to pay on an unpaid invoice: its total less what the client balance holds, or
     * for an "add funds" invoice less what its earlier CoinsPay invoices brought.
     *
     * @param  array  $fb  The invoice, as Host::invoice() gives it.
     * @return string|null Null when its client is gone.
     */
    public function due(array $fb): ?string
    {
        $host = $this->module->host();
        $client = $host->client((int) $fb['client_id']);
        if ($client === null) {
            return null;
        }
        $total = Util::formatAmount((string) $fb['total'], Decision::SCALE);
        if (!empty($fb['deposit'])) {
            $brought = '0';
            foreach ($this->module->repo()->forInvoice((int) $fb['id']) as $row) {
                if ((int) $row['fb_client'] === (int) $fb['client_id']) {
                    $brought = Util::add($brought, $host->credited((int) $row['fb_client'], Sync::reference($row['invoice_id'])), Decision::SCALE);
                }
            }
            $covered = $brought;
        } else {
            // The balance is in the currency of the client: it counts only when the invoice is in it too.
            $same = strtoupper((string) $client['currency']) === strtoupper((string) $fb['currency']);
            $covered = $same && Util::positive((string) $client['balance']) ? (string) $client['balance'] : '0';
        }
        $left = (float) $total - (float) $covered;

        return Util::formatAmount($left > 0 ? $left : 0, Decision::SCALE);
    }

    /**
     * What the CoinsPay invoice stands for on the FOSSBilling invoice (2 decimals, as FOSSBilling
     * keeps it) and what it asks: the same, in the decimals CoinsPay takes for the currency. For a
     * currency without cents the two differ, and the payment adds the first.
     *
     * @return array {0: due, 1: amount}
     */
    private function amounts(string $due, string $currency): array
    {
        $scale = min(Decision::SCALE, $this->module->currencies()->scale($currency));

        return [Util::formatAmount($due, Decision::SCALE), Util::formatAmount($due, $scale)];
    }

    /**
     * The payment page in the client's language. Only an http(s) address goes to the browser.
     *
     * @throws ApiError BAD_RESPONSE for anything else.
     */
    private static function payPage(array $invoice, string $lang): string
    {
        $url = Util::value($invoice, 'pay_url');
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['https', 'http'], true) || (string) parse_url($url, PHP_URL_HOST) === '') {
            throw new ApiError(0, 'BAD_RESPONSE', 'The payment page address is not a web address');
        }

        return self::withLang($url, $lang);
    }

    /**
     * Cancels the open CoinsPay invoices of a FOSSBilling invoice that hold no money: replaced by
     * a new one, or not wanted any more — the invoice was cancelled, changed or paid otherwise.
     * CoinsPay cancels an open invoice even with a payment on its way, so each one is read fresh
     * first; what still lands in the gap is decided as for any closed invoice.
     *
     * @param  string  $keep  CoinsPay invoice to leave open; '' cancels all.
     */
    public function cancelReplaced(int $fbInvoice, string $keep = ''): void
    {
        foreach ($this->module->repo()->forInvoice($fbInvoice) as $row) {
            if ($row['invoice_id'] !== $keep) {
                $this->cancelRow($row);
            }
        }
    }

    /** Cancels one open CoinsPay invoice if it holds no money, and applies its state. */
    public function cancelRow(array $row): void
    {
        if ($row['status'] !== 'open') {
            return;
        }
        $fbInvoice = (int) $row['fb_invoice'];
        try {
            $invoice = $this->module->client()->invoice($row['invoice_id']);
            $hasMoney = Util::positive(Util::value($invoice, 'amount_paid')) || Util::positive(Util::value($invoice, 'amount_pending'));
            if (Util::value($invoice, 'status') === 'open' && !$hasMoney) {
                $invoice = $this->module->client()->cancelInvoice($row['invoice_id']);
            }
            $this->module->sync()->apply($invoice);
        } catch (ApiError $e) {
            // Money came meanwhile (INVOICE_NOT_OPEN) or the API is away: its real state comes with polling.
            $this->module->host()->log(
                in_array($e->status(), [404, 409], true) ? 'info' : 'error',
                sprintf('invoice #%d: CoinsPay invoice %s not cancelled: %s', $fbInvoice, $row['invoice_id'], $e->summary())
            );
        } catch (RuntimeException $e) {
            // Busy, or funds FOSSBilling did not add: the next webhook or poll applies it.
            $this->module->host()->log('error', sprintf('invoice #%d: CoinsPay invoice %s: %s', $fbInvoice, $row['invoice_id'], $e->getMessage()));
        }
    }

    /** The payment page in the client's language. */
    public static function withLang(string $payUrl, string $lang): string
    {
        if ($payUrl === '') {
            return '';
        }

        return $payUrl . (str_contains($payUrl, '?') ? '&' : '?') . 'lang=' . ($lang === 'ru' ? 'ru' : 'en');
    }
}
