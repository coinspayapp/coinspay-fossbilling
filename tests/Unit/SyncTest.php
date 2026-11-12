<?php

use Box\Mod\Coinspay\Lib\Module;
use Box\Mod\Coinspay\Lib\Sync;
use PHPUnit\Framework\TestCase;

/**
 * A payment reaches the client balance exactly once and only for what came, and pays the
 * FOSSBilling invoice only while it waits for it, whatever adding it does.
 */
final class SyncTest extends TestCase
{
    private const ID = '7c9e6679-7425-40de-944b-e07fc1f90ae7';

    private FakeHost $host;

    private MemoryRepo $repo;

    private FakeClient $client;

    private Module $module;

    protected function setUp(): void
    {
        $this->host = new FakeHost;
        $this->repo = new MemoryRepo;
        $this->client = new FakeClient;
        $this->module = fake_module($this->host, $this->repo, $this->client);
        $this->repo->add([
            'invoice_id' => self::ID, 'fb_invoice' => 42, 'fb_client' => 7, 'attempt' => 1, 'amount' => '100', 'currency' => 'USD',
            'test' => 0, 'test_admin' => 0, 'status' => 'open', 'pay_url' => '', 'next_poll' => time(), 'poll_until' => time() + 90000,
        ]);
    }

    private function invoice(array $overrides = []): array
    {
        return $overrides + [
            'id' => self::ID, 'number' => 12, 'status' => 'open', 'test' => false, 'currency' => 'USD', 'amount' => '100',
            'amount_paid' => '0', 'amount_pending' => '0', 'amount_due' => '100', 'amount_overpaid' => null,
            'paid_late' => false, 'paid_manually' => false, 'payments' => [],
        ];
    }

    private function paid(array $overrides = []): array
    {
        return $this->invoice($overrides + ['status' => 'paid', 'amount_paid' => '100', 'amount_due' => '0']);
    }

    private function fresh(): Module
    {
        return $this->module = fake_module($this->host, $this->repo, $this->client);
    }

    private function amounts(): array
    {
        return array_map(fn ($call) => $call[1], $this->host->funds);
    }

    public function test_a_paid_invoice_goes_to_the_balance_once_and_pays_the_invoice()
    {
        $result = $this->module->sync()->apply($this->paid());
        $this->assertStringStartsWith('credit', $result);
        $this->assertSame(['100'], $this->amounts());
        $this->assertSame('paid', $this->host->invoices[42]['status']);
        $this->assertSame('0.00', $this->host->balanceOf(7), 'what came paid the invoice');
        $this->assertSame(['invoice' => 42, 'amount' => '100', 'currency' => 'USD'], $this->host->transactions[self::ID]);
        $this->assertSame('applied', $this->repo->rows[self::ID]['apply_state']);
        $this->assertNull($this->repo->rows[self::ID]['next_poll']);
        $this->assertNull($this->repo->rows[self::ID]['attention']);

        $this->assertSame('no change', $this->module->sync()->apply($this->paid()));
        // Another state of the same invoice (a manual mark, say) still adds nothing.
        $this->assertSame('none', $this->module->sync()->apply($this->paid(['paid_manually' => true])));
        $this->assertCount(1, $this->host->funds);
    }

    public function test_money_on_an_open_invoice_is_not_added_yet()
    {
        $result = $this->module->sync()->apply($this->invoice(['amount_paid' => '40', 'amount_due' => '60']));
        $this->assertStringStartsWith('log', $result);
        $this->assertSame([], $this->host->funds);
        $this->assertNotNull($this->repo->rows[self::ID]['next_poll'], 'an open invoice stays in polling');
    }

    public function test_a_part_goes_to_the_balance_when_the_invoice_expires_and_a_late_rest_completes_it()
    {
        $this->module->sync()->apply($this->invoice(['status' => 'expired', 'amount_paid' => '40', 'amount_due' => '60']));
        $this->assertSame(['40'], $this->amounts());
        $this->assertSame('unpaid', $this->host->invoices[42]['status'], 'the balance does not cover it yet');
        $this->assertSame('40.00', $this->host->balanceOf(7));
        $this->assertNotNull($this->repo->rows[self::ID]['next_poll'], 'a late payment may still come');

        $this->module->sync()->apply($this->paid(['paid_late' => true]));
        $this->assertSame(['40', '60'], $this->amounts(), 'only what was missing');
        $this->assertSame('paid', $this->host->invoices[42]['status']);
        $this->assertSame('100', $this->host->transactions[self::ID]['amount']);
    }

    public function test_an_expired_invoice_without_money_adds_nothing()
    {
        $this->assertStringContainsString('expired unpaid', $this->module->sync()->apply($this->invoice(['status' => 'expired'])));
        $this->assertSame([], $this->host->funds);
    }

    public function test_a_failure_before_adding_is_retried()
    {
        $this->host->fail = 'throw';
        try {
            $this->module->sync()->apply($this->paid());
            $this->fail('no exception');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('did not add', $e->getMessage());
        }
        $this->assertSame('none', $this->repo->rows[self::ID]['apply_state']);
        $this->assertNull($this->repo->rows[self::ID]['fingerprint'], 'the next webhook decides again');
        $this->assertSame('unpaid', $this->host->invoices[42]['status']);

        $this->fresh()->sync()->apply($this->paid());
        $this->assertSame('paid', $this->host->invoices[42]['status']);
        $this->assertSame('100.00', $this->host->coinspayTotal());
    }

    public function test_a_failure_after_adding_does_not_add_a_second_time()
    {
        $this->host->fail = 'throw_after';
        $this->module->sync()->apply($this->paid());
        $this->assertSame('paid', $this->host->invoices[42]['status']);
        $this->fresh()->sync()->apply($this->paid(['paid_manually' => true]));
        $this->assertSame('100.00', $this->host->coinspayTotal());
        $this->assertCount(1, $this->host->funds);
    }

    public function test_funds_fossbilling_did_not_keep_are_an_error_and_are_retried()
    {
        $this->host->fail = 'silent';
        $this->expectException(RuntimeException::class);
        try {
            $this->module->sync()->apply($this->paid());
        } finally {
            $this->assertSame('0.00', $this->host->coinspayTotal());
            $this->fresh()->sync()->apply($this->paid());
            $this->assertSame('100.00', $this->host->coinspayTotal());
        }
    }

    public function test_a_failed_payment_of_the_invoice_is_tried_again_without_adding_again()
    {
        $this->host->payFail = 'throw';
        $this->module->sync()->apply($this->paid());
        $this->assertSame('unpaid', $this->host->invoices[42]['status']);
        $this->assertSame('100.00', $this->host->balanceOf(7));
        $this->assertNull($this->repo->rows[self::ID]['fingerprint'], 'not settled: the next webhook or poll pays it');
        $this->assertNotNull($this->repo->rows[self::ID]['next_poll']);

        $this->assertStringStartsWith('pay', $this->fresh()->sync()->apply($this->paid()));
        $this->assertSame('paid', $this->host->invoices[42]['status']);
        $this->assertCount(1, $this->host->funds);
    }

    public function test_money_for_a_cancelled_invoice_stays_on_the_balance_and_the_admin_is_told()
    {
        $this->host->invoices[42]['status'] = 'canceled';
        $this->module->sync()->apply($this->paid());
        $this->assertSame('100.00', $this->host->balanceOf(7));
        $this->assertSame('canceled', $this->host->invoices[42]['status'], 'never paid: its services must not start');
        $this->assertSame([], $this->host->payCalls);
        $this->assertStringContainsString('"canceled"', (string) $this->repo->rows[self::ID]['attention']);
        $this->assertNotEmpty($this->host->logged('warning'));
    }

    public function test_money_for_a_deleted_invoice_goes_to_the_balance_of_its_client()
    {
        unset($this->host->invoices[42]);
        $this->module->sync()->apply($this->paid());
        $this->assertSame('100.00', $this->host->balanceOf(7));
        $this->assertStringContainsString('deleted', (string) $this->repo->rows[self::ID]['attention']);
        $this->assertSame('no change', $this->module->sync()->apply($this->paid()));
        $this->assertCount(1, $this->host->funds);
    }

    public function test_a_test_payment_is_added_only_when_allowed_with_its_own_transaction_id()
    {
        $test = $this->paid(['test' => true]);
        $this->repo->update(self::ID, ['test' => 1, 'test_admin' => 1]);
        $this->assertStringStartsWith('log', $this->module->sync()->apply($test));
        $this->assertSame([], $this->host->funds);

        $this->host->settings['apply_test'] = '1';
        $this->repo->update(self::ID, ['fingerprint' => null]);
        $this->fresh()->sync()->apply($test);
        $this->assertSame('paid', $this->host->invoices[42]['status']);
        $this->assertArrayHasKey('test-' . self::ID, $this->host->transactions);
    }

    public function test_a_payment_claimed_by_another_request_is_not_added_again()
    {
        $this->repo->update(self::ID, ['apply_state' => 'applying', 'claimed' => time()]);
        try {
            $this->module->sync()->apply($this->paid());
            $this->fail('no exception');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('another request', $e->getMessage());
        }
        $this->assertSame([], $this->host->funds);

        // A claim of a request that died long ago is taken over.
        $this->repo->update(self::ID, ['claimed' => time() - Sync::CLAIM_STALE - 1]);
        $this->fresh()->sync()->apply($this->paid());
        $this->assertSame('100.00', $this->host->coinspayTotal());
    }

    public function test_an_invoice_of_another_client_now_is_left_to_the_admin()
    {
        $this->host->invoices[42]['client_id'] = 8;
        $this->host->clients[8] = ['id' => 8, 'currency' => 'USD'];
        $this->module->sync()->apply($this->paid());
        $this->assertSame([], $this->host->funds);
        $this->assertStringContainsString('another client', (string) $this->repo->rows[self::ID]['attention']);
    }

    public function test_a_deleted_client_is_left_to_the_admin()
    {
        unset($this->host->clients[7]);
        $this->module->sync()->apply($this->paid());
        $this->assertSame([], $this->host->funds);
        $this->assertStringContainsString('client is deleted', (string) $this->repo->rows[self::ID]['attention']);
    }

    public function test_a_balance_in_another_currency_is_left_to_the_admin()
    {
        $this->host->clients[7]['currency'] = 'EUR';
        $this->module->sync()->apply($this->paid());
        $this->assertSame([], $this->host->funds);
        $this->assertNotNull($this->repo->rows[self::ID]['attention']);
    }

    public function test_an_overpayment_goes_to_the_balance_whole_and_pays_the_invoice()
    {
        $this->module->sync()->apply($this->paid(['amount_paid' => '105', 'amount_overpaid' => '5']));
        $this->assertSame(['105'], $this->amounts());
        $this->assertSame('paid', $this->host->invoices[42]['status']);
        $this->assertSame('5.00', $this->host->balanceOf(7), 'the excess stays for the next invoices');
        $this->assertStringContainsString('Overpaid by 5 USD', (string) $this->repo->rows[self::ID]['attention']);
    }

    public function test_a_paid_invoice_cancels_the_other_open_coinspay_invoices_without_money()
    {
        $other = '11111111-1111-4111-8111-111111111111';
        $this->repo->add(['invoice_id' => $other, 'fb_invoice' => 42, 'fb_client' => 7, 'attempt' => 0, 'amount' => '100', 'currency' => 'USD',
            'test' => 0, 'test_admin' => 0, 'status' => 'open', 'pay_url' => '', 'next_poll' => time(), 'poll_until' => time() + 90000]);
        $this->client->invoices[$other] = $this->invoice(['id' => $other]);
        $this->module->sync()->apply($this->paid());
        $this->assertSame([$other], $this->client->cancelled);
    }

    public function test_an_invoice_cancelled_between_the_decision_and_the_payment_is_not_paid()
    {
        $this->host->onRead = function (int $read, FakeHost $host): void {
            // First read: the decision. Then the admin cancels it.
            if ($read === 2) {
                $host->invoices[42]['status'] = 'canceled';
            }
        };
        $this->module->sync()->apply($this->paid());
        $this->assertSame('canceled', $this->host->invoices[42]['status']);
        $this->assertSame('100.00', $this->host->balanceOf(7), 'the money stays on the balance');
        $this->assertStringContainsString('"canceled"', (string) $this->repo->rows[self::ID]['attention']);
    }

    public function test_a_currency_without_cents_pays_the_whole_invoice()
    {
        $this->host->invoices[42] = ['currency' => 'JPY', 'total' => '1078.32'] + $this->host->invoices[42];
        $this->host->clients[7]['currency'] = 'JPY';
        $this->repo->update(self::ID, ['amount' => '1078', 'due' => '1078.32', 'currency' => 'JPY']);
        $this->module->sync()->apply($this->paid(['currency' => 'JPY', 'amount' => '1078', 'amount_paid' => '1078']));
        $this->assertSame(['1078.32'], $this->amounts());
        $this->assertSame('paid', $this->host->invoices[42]['status']);
    }

    public function test_an_add_funds_invoice_is_paid_when_what_came_covers_it()
    {
        $this->host->invoices[42]['deposit'] = true;
        $this->module->sync()->apply($this->invoice(['status' => 'expired', 'amount_paid' => '40']));
        $this->assertSame('unpaid', $this->host->invoices[42]['status']);
        $this->module->sync()->apply($this->paid(['paid_late' => true]));
        $this->assertSame('paid', $this->host->invoices[42]['status']);
        $this->assertSame('100.00', $this->host->balanceOf(7), 'its money is the balance itself');
    }

    public function test_the_balance_is_matched_by_the_coinspay_invoice_not_by_a_row_number()
    {
        // Rows of the balance of another CoinsPay invoice, or of tables made anew, are not this one's.
        $this->host->balance[] = ['client_id' => 7, 'type' => 'coinspay', 'rel_id' => '1', 'amount' => '100.00', 'description' => 'old'];
        $this->host->balance[] = ['client_id' => 7, 'type' => 'coinspay', 'rel_id' => Sync::reference('11111111-1111-4111-8111-111111111111'), 'amount' => '100.00', 'description' => 'other'];
        $this->module->sync()->apply($this->paid());
        $this->assertSame(['100'], $this->amounts());
        $this->assertSame('7c9e6679742540de944b', Sync::reference(self::ID));
        $this->assertSame(20, strlen(Sync::reference(self::ID)));
    }

    public function test_a_payment_not_added_or_not_paid_is_shown_to_the_admin_until_a_retry_goes_through()
    {
        $this->host->fail = 'throw';
        try {
            $this->module->sync()->apply($this->paid());
        } catch (RuntimeException) {
        }
        $this->assertStringContainsString('not on the client balance yet', (string) $this->repo->rows[self::ID]['problem']);

        $this->host->payFail = 'throw';
        $this->fresh()->sync()->apply($this->paid());
        $this->assertStringContainsString('could not be paid from it yet', (string) $this->repo->rows[self::ID]['problem']);

        $this->fresh()->sync()->apply($this->paid());
        $this->assertSame('paid', $this->host->invoices[42]['status']);
        $this->assertNull($this->repo->rows[self::ID]['problem'], 'cleared once it went through');
    }

    public function test_money_found_while_an_admin_deletes_the_invoice_does_not_pay_it()
    {
        Sync::$deleting[42] = true;
        try {
            $this->module->sync()->apply($this->paid());
        } finally {
            unset(Sync::$deleting[42]);
        }
        $this->assertSame('unpaid', $this->host->invoices[42]['status']);
        $this->assertSame('100.00', $this->host->balanceOf(7));
        $this->assertStringContainsString('deleted', (string) $this->repo->rows[self::ID]['attention']);
    }

    public function test_an_unknown_invoice_is_not_touched()
    {
        $this->assertSame('not an invoice of this FOSSBilling', $this->module->sync()->apply($this->paid(['id' => '22222222-2222-4222-8222-222222222222'])));
        $this->assertSame([], $this->host->funds);
    }
}
