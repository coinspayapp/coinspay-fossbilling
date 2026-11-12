# CoinsPay for FOSSBilling

Let your clients pay FOSSBilling invoices in cryptocurrency. The client pays on the CoinsPay payment page, the payment goes to the client's balance and pays the invoice, and the money goes on to your own wallets.

## Requirements

FOSSBilling 0.8.0 or newer (PHP 8.3 or newer, as FOSSBilling 0.8 needs) with the cron of FOSSBilling running, and a [CoinsPay](https://coinspay.app) account with a shop.

## Description

The client opens an invoice, picks CoinsPay among the payment methods, then a coin and a network on the CoinsPay payment page, and pays. When the payment is confirmed, it goes to the client's balance in FOSSBilling and the invoice is paid from it. FOSSBilling then carries on as usual: the invoice is paid, the order is activated or renewed, the client gets the email.

The amount is what is left to pay on the invoice at that moment: the total, less what the client's balance already holds. CoinsPay converts it at the current rate and holds the coin amount for 30 minutes.

### Coins and networks

More than 30 coins and tokens on TRON, Ethereum, BNB Smart Chain, Solana, Bitcoin and Litecoin, among them USDT, USDC, BTC, ETH, BNB, TRX, LTC and SOL. The full list is on [coinspay.app/coins](https://coinspay.app/coins). You can limit the payment page to stablecoins.

### What the gateway takes care of

- What came is what counts. A part paid on a CoinsPay invoice that expires goes to the client's balance, and the next payment asks only for the rest. A late rest of the same CoinsPay invoice is added too.
- Each payment is added exactly once, even when the webhook, the client's return and polling arrive at the same moment. FOSSBilling does not guard its payment callbacks against that itself.
- If the invoice is cancelled, deleted, paid another way or its amount changes, the gateway cancels the open CoinsPay invoice. The next payment creates one for the new amount.
- A payment for an invoice that was cancelled or deleted meanwhile goes to the client's balance, and the invoice is not paid: FOSSBilling would otherwise mark the cancelled invoice paid and activate its order. An overpayment stays on the balance too. Such payments are listed on the page of the module for your look.
- "Add funds" invoices work: the payment is the new balance.
- Webhooks are signed and checked. The gateway also asks CoinsPay about open invoices on every run of the FOSSBilling cron and when the client comes back from the payment page.
- The page of the module shows the shop of the key, the test or live mode, the webhook address to put into the CoinsPay dashboard, whether the test event came, whether the cron runs, and the currencies of your FOSSBilling that CoinsPay does not accept.
- English and Russian.

### Why CoinsPay

Payments go straight to your wallets. Each network takes up to five payout addresses with percentage shares, for example 70% to you and 30% to a partner, and nothing is kept on the platform.

The fee is as low as 0.3% of each payout: the rate goes down as the monthly turnover of all your shops grows. There is no monthly or setup fee. For the first 10 days after your first payment, payouts up to $500 carry no fee. You can let the client pay the CoinsPay fee and the network fee on top of the invoice. Details are on the [pricing page](https://coinspay.app/pricing).

## Installation

1. Get the archive `coinspay-fossbilling-<version>.zip` from the releases of this repository, or build it yourself (see [Development](#development)).
2. Unpack it into the root folder of FOSSBilling, the one with `config.php`. It adds two folders: `modules/Coinspay` (the module) and `library/Payment/Adapter/Coinspay` (the payment gateway).
3. In the admin area open Extensions, find "CoinsPay" and activate it. This creates the tables of the gateway and connects it to invoice changes and the cron.
4. Open System → Payment gateways → "New payment gateway" and install "Coinspay". Give it the title your clients see, for example "Crypto (CoinsPay)".

The installation guide is also available as a separate document in [English](docs/install-en.md) and [Russian](docs/install-ru.md).

## Configuration

1. In the CoinsPay dashboard open your shop:
   - under "API keys" create a key with "Invoices only" access and paste it into "API key" of the gateway. The gateway needs nothing more, and such a key cannot withdraw money;
   - under "Webhook" enter the address the page of the module shows, `https://<your FOSSBilling>/coinspay/webhook`, save, and copy the `whsec_…` secret into "Webhook secret".
2. Leave "API address" empty: the gateway then uses `https://coinspay.app`.
3. Turn the gateway on and save. Open Extensions → CoinsPay: the page shows the shop, the key mode and the currencies. Press "Send test event" in the CoinsPay dashboard and open the page again: it notes the event.

Other settings:

| Field | Default | What it does |
|---|---|---|
| Invoice lifetime, minutes | 60 | Minutes the client has to pay, from 15 to 1440 |
| Return automatically | Yes | After payment the CoinsPay page takes the client back to the invoice |
| Stablecoins only | No | The client can pay in USDT, USDC and other stablecoins only |
| Client pays the CoinsPay fee / network fee | No | The fees are added to the client's amount; the invoice gets its own amount |
| Test key for admins only | Yes | With a test key, only a signed-in admin can pay |
| Add test payments | No | Whether an admin's test payments go to the balance and pay the invoice |
| Debug log | No | More lines in the log of FOSSBilling |

The gateway refuses invoices in currencies CoinsPay does not accept, and tells the client why.

## Test mode

With a test key (`cpk_test_…`) invoices are paid with a button on the payment page and no coins move. Only a signed-in admin can pay: open the client's invoice while signed in to the admin area.

By default a test payment is only written to the log. An added test payment is real money on the client's balance for FOSSBilling, with orders activated and emails sent, so turn on "Add test payments" only on a test copy of FOSSBilling.

## Removal

Delete the gateway in Payment gateways, deactivate the module in Extensions and delete the folders `modules/Coinspay` and `library/Payment/Adapter/Coinspay`. The tables `coinspay_invoices` and `coinspay_kv` stay in the database: they tell which payments were added, so keep them if you may install the gateway again.

## Development

```
modules/Coinspay/Service.php              activation, the hooks of invoices and the cron
modules/Coinspay/Controller/              the webhook and the return (Client), the page (Admin)
modules/Coinspay/Lib/FossbillingHost.php  the only class that knows the models and tables of FOSSBilling
modules/Coinspay/Lib/                     the rest: no framework, covered by unit tests
modules/Coinspay/lang/                    en and ru texts
library/Payment/Adapter/Coinspay/         the payment gateway: its form, "pay", ipn.php
```

FOSSBilling lists every PHP file in the folder of a payment adapter as a gateway, so the adapter is one file and the gateway lives in the module.

A payment goes to the client's balance with the type `coinspay` and the first 20 characters of the CoinsPay invoice id as its reference. Before adding anything the gateway reads what the balance already holds for that CoinsPay invoice, under a named lock of the invoice and a claim of the row, so two concurrent requests cannot add it twice. Each CoinsPay invoice is also one transaction on the FOSSBilling invoice, with the CoinsPay invoice id as the transaction id, for the admin to see.

Unit tests, the PHP compatibility check and the build:

```bash
P=$(pwd)
docker run --rm -v "$P:/app" -w /app composer:2 composer install
docker run --rm -v "$P:/app" -w /app php:8.3-cli vendor/bin/phpunit
docker run --rm -v "$P:/app" -w /app php:8.5-cli vendor/bin/phpunit
docker run --rm -v "$P:/app" -w /app php:8.3-cli vendor/bin/phpcs -q
python bin/build.py
```

`bin/build.py` writes `dist/coinspay-fossbilling-<version>.zip`.

## Changelog

### 0.1.0

- First release.

## License

GPL-3.0-or-later. The full text is in [LICENSE](LICENSE).
