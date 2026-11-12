<?php

use Box\Mod\Coinspay\Lib\Payment;
use PHPUnit\Framework\TestCase;

/**
 * "Pay": what is due now (the total less the client balance), reuse and replacement of CoinsPay
 * invoices, the test key.
 */
final class PaymentTest extends TestCase
{
    private const FIRST = '00000000-0000-4000-8000-000000000001';

    private const SECOND = '00000000-0000-4000-8000-000000000002';

    private FakeHost $host;

    private MemoryRepo $repo;

    private FakeClient $client;

    protected function setUp(): void
    {
        $this->host = new FakeHost;
        $this->repo = new MemoryRepo;
        $this->client = new FakeClient;
    }

    private function payment(): Payment
    {
        return new Payment(fake_module($this->host, $this->repo, $this->client));
    }

    public function test_pay_takes_the_client_to_the_payment_page()
    {
        $result = $this->payment()->start(42);
        $this->assertSame(['redirect' => 'https://pay.example/pay/' . self::FIRST . '?lang=en'], $result);
        [$body, $key] = $this->client->created[0];
        $this->assertSame('USD', $body['currency']);
        $this->assertSame('100', $body['amount']);
        $this->assertSame('42', $body['reference']);
        $this->assertSame('Invoice FOSS00042', $body['description']);
        $this->assertSame(60, $body['lifetime_minutes']);
        $this->assertTrue($body['auto_return']);
        $this->assertStringStartsWith('https://billing.example/coinspay/return?invoice=42&cp_sig=', $body['return_url']);
        $this->assertArrayNotHasKey('title', $body);
        $this->assertMatchesRegularExpression('/^fossbilling-[0-9a-f]{8}-42-1-[0-9a-f]{12}$/', $key);

        $row = $this->repo->rows[self::FIRST];
        $this->assertSame('100', $row['amount']);
        $this->assertSame('100', $row['due']);
        $this->assertSame('USD', $row['currency']);
        $this->assertSame(7, $row['fb_client']);
    }

    public function test_the_payment_page_speaks_the_language_of_the_client()
    {
        $this->host->locale = 'ru_RU';
        $this->assertSame('https://pay.example/pay/' . self::FIRST . '?lang=ru', $this->payment()->start(42)['redirect']);
        $this->assertSame('Счёт FOSS00042', $this->client->created[0][0]['description']);
    }

    public function test_a_repeated_pay_reuses_the_open_invoice()
    {
        $this->payment()->start(42);
        $this->payment()->start(42);
        $this->assertCount(1, $this->client->created);
    }

    public function test_what_the_balance_holds_is_not_asked_again()
    {
        // A part of an earlier CoinsPay invoice, or any credit of the client.
        $this->host->balance[] = ['client_id' => 7, 'type' => 'coinspay', 'rel_id' => '9', 'amount' => '40.00', 'description' => ''];
        $this->payment()->start(42);
        $this->assertSame('60', $this->client->created[0][0]['amount']);
        $this->assertSame('60', $this->repo->rows[self::FIRST]['due']);
    }

    public function test_a_client_with_a_balance_in_another_currency_cannot_pay()
    {
        // The payment could not go to the balance: refused before a CoinsPay invoice is made.
        $this->host->clients[7]['currency'] = 'EUR';
        $this->assertArrayHasKey('error', $this->payment()->start(42));
        $this->assertCount(0, $this->client->created);
    }

    public function test_only_the_client_or_an_admin_spends_the_balance()
    {
        $this->host->balance[] = ['client_id' => 7, 'type' => 'gift', 'rel_id' => null, 'amount' => '150.00', 'description' => ''];
        // A guest holding the link of the invoice.
        $this->host->signedIn = null;
        $this->assertSame(['redirect' => 'https://billing.example/invoice/h42'], $this->payment()->start(42));
        $this->assertSame('unpaid', $this->host->invoices[42]['status']);
        $this->host->signedIn = 8;
        $this->payment()->start(42);
        $this->assertSame('unpaid', $this->host->invoices[42]['status'], 'another client');
        $this->host->admin = true;
        $this->payment()->start(42);
        $this->assertSame('paid', $this->host->invoices[42]['status']);
        $this->assertCount(0, $this->client->created);
    }

    public function test_pressing_pay_again_and_again_asks_the_api_once()
    {
        $this->payment()->start(42);
        $this->payment()->start(42);
        $this->payment()->start(42);
        $this->assertSame(1, $this->client->reads, 'the open invoice is read once within the reuse time');
        $this->assertCount(1, $this->client->created);
    }

    public function test_the_attempt_follows_the_highest_one()
    {
        foreach ([1, 3] as $attempt) {
            $this->repo->add(['invoice_id' => sprintf('aaaaaaaa-0000-4000-8000-%012d', $attempt), 'fb_invoice' => 42, 'fb_client' => 7, 'attempt' => $attempt, 'amount' => '100', 'currency' => 'USD',
                'test' => 0, 'test_admin' => 0, 'status' => 'expired', 'pay_url' => '', 'next_poll' => null, 'poll_until' => 0]);
        }
        $this->payment()->start(42);
        $this->assertMatchesRegularExpression('/-42-4-/', $this->client->created[0][1]);
    }

    public function test_a_balance_that_covers_the_invoice_pays_it_without_coinspay()
    {
        $this->host->balance[] = ['client_id' => 7, 'type' => 'gift', 'rel_id' => null, 'amount' => '150.00', 'description' => ''];
        $this->assertSame(['redirect' => 'https://billing.example/invoice/h42'], $this->payment()->start(42));
        $this->assertSame('paid', $this->host->invoices[42]['status']);
        $this->assertCount(0, $this->client->created);
    }

    public function test_an_add_funds_invoice_asks_its_total_whatever_the_balance()
    {
        $this->host->invoices[42]['deposit'] = true;
        $this->host->balance[] = ['client_id' => 7, 'type' => 'gift', 'rel_id' => null, 'amount' => '150.00', 'description' => ''];
        $this->payment()->start(42);
        $this->assertSame('100', $this->client->created[0][0]['amount']);
    }

    public function test_an_invoice_that_does_not_wait_goes_back_to_its_page()
    {
        foreach (['paid', 'canceled', 'refunded'] as $status) {
            $this->host->invoices[42]['status'] = $status;
            $this->assertSame(['redirect' => 'https://billing.example/invoice/h42'], $this->payment()->start(42), $status);
        }
        $this->assertSame(['redirect' => 'https://billing.example/invoice/'], $this->payment()->start(43));
        $this->assertCount(0, $this->client->created);
    }

    public function test_an_invoice_whose_client_is_gone_cannot_be_paid()
    {
        unset($this->host->clients[7]);
        $this->assertArrayHasKey('error', $this->payment()->start(42));
        $this->assertCount(0, $this->client->created);
    }

    public function test_a_test_key_works_for_an_admin_only()
    {
        $this->host->settings['api_key'] = 'cpk_test_key';
        $this->client->test = true;
        $this->assertArrayHasKey('error', $this->payment()->start(42));
        $this->host->admin = true;
        $this->assertArrayHasKey('redirect', $this->payment()->start(42));
        $this->assertSame(1, $this->repo->rows[self::FIRST]['test_admin']);
        $this->assertSame(1, $this->repo->rows[self::FIRST]['test']);
    }

    public function test_a_new_sum_replaces_the_open_invoice_and_cancels_it()
    {
        $this->payment()->start(42);
        $this->host->invoices[42]['total'] = '120.00';
        $this->payment()->start(42);
        $this->assertCount(2, $this->client->created);
        $this->assertSame('120', $this->client->created[1][0]['amount']);
        $this->assertSame([self::FIRST], $this->client->cancelled);
        $this->assertSame(2, $this->repo->rows[self::SECOND]['attempt']);
    }

    public function test_an_open_invoice_with_money_is_not_cancelled()
    {
        $this->payment()->start(42);
        $this->client->set(self::FIRST, ['amount_pending' => '50']);
        $this->host->invoices[42]['total'] = '120.00';
        $this->payment()->start(42);
        $this->assertSame([], $this->client->cancelled);
        $this->assertSame([], $this->host->funds, 'money on its way is added only when confirmed');
    }

    public function test_a_paid_open_invoice_is_applied_before_a_new_one()
    {
        $this->payment()->start(42);
        $this->client->set(self::FIRST, ['status' => 'paid', 'amount_paid' => '100', 'amount_due' => '0']);
        $this->assertSame(['redirect' => 'https://billing.example/invoice/h42'], $this->payment()->start(42));
        $this->assertSame('paid', $this->host->invoices[42]['status']);
        $this->assertCount(1, $this->client->created);
    }

    public function test_a_currency_coinspay_does_not_take_is_explained()
    {
        $this->host->invoices[42]['currency'] = 'XYZ';
        $this->host->clients[7]['currency'] = 'XYZ';
        $result = $this->payment()->start(42);
        $this->assertStringContainsString('XYZ', $result['error']);
        $this->assertCount(0, $this->client->created);
    }

    public function test_a_test_invoice_of_an_admin_is_not_handed_to_a_client()
    {
        $this->host->settings['api_key'] = 'cpk_test_key';
        $this->host->settings['test_admin_only'] = '0';
        $this->client->test = true;
        $this->host->admin = true;
        $this->payment()->start(42);
        $this->host->admin = false;
        $result = $this->payment()->start(42);
        $this->assertSame('https://pay.example/pay/' . self::SECOND . '?lang=en', $result['redirect']);
        $this->assertSame(0, $this->repo->rows[self::SECOND]['test_admin']);
        $this->assertSame([self::FIRST], $this->client->cancelled);
    }

    public function test_a_currency_without_cents_is_asked_whole_and_remembers_the_cents()
    {
        $this->host->invoices[42] = ['total' => '1078.32', 'currency' => 'JPY'] + $this->host->invoices[42];
        $this->host->clients[7]['currency'] = 'JPY';
        $this->payment()->start(42);
        $this->assertSame('1078', $this->client->created[0][0]['amount']);
        $this->assertSame('1078.32', $this->repo->rows[self::FIRST]['due']);
        // Asked again: the same invoice, not a new one for the cents.
        $this->payment()->start(42);
        $this->assertCount(1, $this->client->created);
    }

    public function test_only_a_web_address_goes_to_the_browser()
    {
        $this->client->payUrl = 'javascript:alert(1)';
        $result = $this->payment()->start(42);
        $this->assertArrayHasKey('error', $result);
        $this->assertArrayNotHasKey('redirect', $result);
    }

    public function test_an_old_invoice_met_by_the_idempotency_key_is_never_adopted()
    {
        // The tables were wiped: the keys of both attempts meet closed invoices made before.
        $this->client->createStatus = 'paid';
        $result = $this->payment()->start(42);
        $this->assertArrayHasKey('error', $result);
        $this->assertCount(2, $this->client->created);
        $this->assertSame([], $this->repo->rows);
        $this->assertSame([], $this->host->funds);
    }

    public function test_no_key_no_payment()
    {
        $this->host->settings['api_key'] = '';
        $this->assertArrayHasKey('error', $this->payment()->start(42));
    }
}
