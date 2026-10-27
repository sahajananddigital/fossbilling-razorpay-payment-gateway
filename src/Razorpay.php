<?php
// try to load Composer autoloader (checks a few common locations)
if (!class_exists(\Razorpay\Api\Api::class)) {
    $autoloadCandidates = [
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/vendor/autoload.php',
        __DIR__ . '/../../vendor/autoload.php',
        __DIR__ . '/../../../../vendor/autoload.php',
    ];

    $autoloaderFound = false;
    foreach ($autoloadCandidates as $candidate) {
        if (file_exists($candidate)) {
            require_once $candidate;
            $autoloaderFound = true;
            break;
        }
    }

    if (!$autoloaderFound) {
        // If your FOSSBilling installation already loads vendor/autoload elsewhere you can remove this exception.
        throw new \Exception('Autoloader not found. Run "composer install" in the project root or update the autoloader path in this file.');
    }
}

use Razorpay\Api\Api;
use Razorpay\Api\Errors\SignatureVerificationError;

class Payment_Adapter_Razorpay extends Payment_AdapterAbstract implements \FOSSBilling\InjectionAwareInterface
{
    protected ?Pimple\Container $di = null;
    private Api $razorpay;
    private array $config;

    public function setDi(Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?Pimple\Container
    {
        return $this->di;
    }

    public function setRazorpayApi(Api $api): void
    {
        $this->razorpay = $api;
    }

    public function __construct($config)
    {
        $this->config = (array) $config;

        if (!empty($this->config['test_mode'])) {
            if (empty($this->config['test_key_id']) || empty($this->config['test_key_secret'])) {
                throw new Payment_Exception('Razorpay test mode keys are missing in configuration.');
            }
            $this->razorpay = new Api($this->config['test_key_id'], $this->config['test_key_secret']);
        } else {
            if (empty($this->config['key_id']) || empty($this->config['key_secret'])) {
                throw new Payment_Exception('Razorpay live mode keys are missing in configuration.');
            }
            $this->razorpay = new Api($this->config['key_id'], $this->config['key_secret']);
        }
    }

    public static function getConfig()
    {
        return [
            'supports_one_time_payments' => true,
            'supports_subscriptions'     => true,
            'description'                => 'Razorpay payment gateway for India (UPI, cards, wallets, netbanking, and recurring subscriptions).',
            'logo' => [
                'logo'   => 'Razorpay.png',
                'height' => '30px',
                'width'  => '65px',
            ],
            'form' => [
                'key_id' => [
                    'text', [
                        'label' => 'Live Key ID:',
                    ],
                ],
                'key_secret' => [
                    'password', [
                        'label'  => 'Live Key Secret:',
                        'secret' => true,
                    ],
                ],
                'webhook_secret' => [
                    'password', [
                        'label'    => 'Live Webhook Secret:',
                        'secret'   => true,
                        'required' => false,
                    ],
                ],
                'test_key_id' => [
                    'text', [
                        'label'    => 'Test Key ID:',
                        'required' => false,
                    ],
                ],
                'test_key_secret' => [
                    'password', [
                        'label'    => 'Test Key Secret:',
                        'secret'   => true,
                        'required' => false,
                    ],
                ],
                'test_webhook_secret' => [
                    'password', [
                        'label'    => 'Test Webhook Secret:',
                        'secret'   => true,
                        'required' => false,
                    ],
                ],
            ],
        ];
    }

    private function getAmountInPaise(\Model_Invoice $invoice): int
    {
        $invoiceService = $this->di['mod_service']('Invoice');

        return intval(round($invoiceService->getTotalWithTax($invoice) * 100));
    }

    private function parseSubscriptionPeriod(?string $periodCode): array
    {
        $periodCode = !empty($periodCode) ? strtoupper(trim($periodCode)) : '1M';
        if (preg_match('/^(\d+)([A-Z])$/', $periodCode, $matches)) {
            $qty = max(1, (int) $matches[1]);
            $unit = $matches[2];
        } else {
            $qty = 1;
            $unit = 'M';
        }

        return match ($unit) {
            'D' => [
                'period'      => 'daily',
                'interval'    => $qty,
                'total_count' => max(1, intdiv(36500, $qty)),
                'code'        => $qty . 'D',
            ],
            'W' => [
                'period'      => 'weekly',
                'interval'    => $qty,
                'total_count' => max(1, intdiv(5200, $qty)),
                'code'        => $qty . 'W',
            ],
            'Y' => [
                'period'      => 'yearly',
                'interval'    => $qty,
                'total_count' => max(1, intdiv(100, $qty)),
                'code'        => $qty . 'Y',
            ],
            default => [
                'period'      => 'monthly',
                'interval'    => $qty,
                'total_count' => max(1, intdiv(1200, $qty)),
                'code'        => $qty . 'M',
            ],
        };
    }

    private function getInvoiceSubscriptionPeriod($invoice): array
    {
        $periodCode = null;

        // Try getting period from Invoice Subscription service
        if (isset($this->di['mod_service'])) {
            try {
                $subscriptionService = $this->di['mod_service']('Invoice', 'Subscription');
                if (is_object($subscriptionService) && method_exists($subscriptionService, 'getSubscriptionPeriod')) {
                    $periodCode = $subscriptionService->getSubscriptionPeriod($invoice);
                }
            } catch (\Throwable $e) {}
        }

        // Fallback: look directly in invoice_item table
        if (empty($periodCode) && isset($this->di['db'])) {
            try {
                $item = $this->di['db']->findOne('InvoiceItem', 'invoice_id = :id AND period IS NOT NULL AND period != ""', [':id' => $invoice->id]);
                if ($item && !empty($item->period)) {
                    $periodCode = $item->period;
                }
            } catch (\Throwable $e) {}
        }

        return $this->parseSubscriptionPeriod($periodCode ?? '1M');
    }

    public function getHtml($api_admin, $invoice_id, $subscription)
    {
        $invoice = $this->di['db']->getExistingModelById('Invoice', $invoice_id, 'Invoice not found');

        $keyId = !empty($this->config['test_mode']) ? $this->config['test_key_id'] : $this->config['key_id'];

        $payGatewayService = $this->di['mod_service']('Invoice', 'PayGateway');
        $payGateway = $this->di['db']->findOne('PayGateway', 'gateway = "Razorpay"');
        $callbackUrl = $payGatewayService->getCallbackUrl($payGateway, $invoice);
        $doesRedirect = true;
        $amountInPaise = $this->getAmountInPaise($invoice);
        $buyerName = trim(($invoice->buyer_first_name ?? '') . ' ' . ($invoice->buyer_last_name ?? ''));
        $buyerEmail = $invoice->buyer_email ?? '';

        if ($subscription) {
            $periodDetails = $this->getInvoiceSubscriptionPeriod($invoice);

            $plan = $this->razorpay->plan->create([
                'period'   => $periodDetails['period'],
                'interval' => $periodDetails['interval'],
                'item'     => [
                    'name'        => 'Invoice #' . $invoice->id,
                    'amount'      => $amountInPaise,
                    'currency'    => $invoice->currency,
                    'description' => 'Subscription for Invoice #' . $invoice->id,
                ],
                'notes'    => [
                    'invoice_id' => (string) $invoice->id,
                ],
            ]);

            $subData = [
                'plan_id'         => $plan->id,
                'total_count'     => $periodDetails['total_count'],
                'quantity'        => 1,
                'customer_notify' => 1,
                'notes'           => [
                    'invoice_id' => (string) $invoice->id,
                    'client_id'  => (string) $invoice->client_id,
                ],
            ];
            $razorpaySub = $this->razorpay->subscription->create($subData);

            $form = '
            <form id="razorpay-sub-form" action="' . htmlspecialchars($callbackUrl . '&redirect=' . $doesRedirect . '&invoice_hash=' . $invoice->hash, ENT_QUOTES, 'UTF-8') . '" method="POST">
              <input type="hidden" name="razorpay_payment_id" id="razorpay_payment_id">
              <input type="hidden" name="razorpay_subscription_id" id="razorpay_subscription_id">
              <input type="hidden" name="razorpay_signature" id="razorpay_signature">
              <input type="hidden" name="invoice_id" value="' . (int) $invoice->id . '">
              <button type="button" id="rzp-sub-button" class="btn btn-primary" style="background-color: #3399cc; color: #fff; border: 1px solid #3399cc; padding: 10px 20px; font-size: 15px; font-weight: 600; border-radius: 4px; cursor: pointer;">Subscribe with Razorpay</button>
            </form>
            <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
            <script>
              (function() {
                var options = {
                  "key": ' . json_encode($keyId) . ',
                  "subscription_id": ' . json_encode($razorpaySub->id) . ',
                  "name": ' . json_encode('Invoice #' . $invoice->id) . ',
                  "description": ' . json_encode('Subscription for Invoice #' . $invoice->id) . ',
                  "prefill": {
                    "name": ' . json_encode($buyerName) . ',
                    "email": ' . json_encode($buyerEmail) . '
                  },
                  "theme": {
                    "color": "#3399cc"
                  },
                  "handler": function (response) {
                    document.getElementById("razorpay_payment_id").value = response.razorpay_payment_id || "";
                    document.getElementById("razorpay_subscription_id").value = response.razorpay_subscription_id || "";
                    document.getElementById("razorpay_signature").value = response.razorpay_signature || "";
                    document.getElementById("razorpay-sub-form").submit();
                  }
                };
                var rzp = new Razorpay(options);
                var btn = document.getElementById("rzp-sub-button");
                if (btn) {
                  btn.onclick = function(e) {
                    e.preventDefault();
                    rzp.open();
                  };
                }
              })();
            </script>';

            return $form;
        }

        $order = $this->razorpay->order->create([
            'receipt'         => 'INV-' . $invoice->id,
            'amount'          => $amountInPaise,
            'currency'        => $invoice->currency,
            'payment_capture' => 1,
        ]);

        $form = '
        <form action="' . htmlspecialchars($callbackUrl . '&redirect=' . $doesRedirect . '&invoice_hash=' . $invoice->hash, ENT_QUOTES, 'UTF-8') . '" method="POST">
          <script src="https://checkout.razorpay.com/v1/checkout.js"
                  data-key="' . htmlspecialchars($keyId, ENT_QUOTES, 'UTF-8') . '"
                  data-amount="' . $amountInPaise . '"
                  data-currency="' . htmlspecialchars($invoice->currency, ENT_QUOTES, 'UTF-8') . '"
                  data-order_id="' . htmlspecialchars($order['id'], ENT_QUOTES, 'UTF-8') . '"
                  data-buttontext="Pay with Razorpay"
                  data-name="' . htmlspecialchars('Invoice #' . $invoice->id, ENT_QUOTES, 'UTF-8') . '"
                  data-description="' . htmlspecialchars('Payment for Invoice #' . $invoice->id, ENT_QUOTES, 'UTF-8') . '"
                  data-prefill.name="' . htmlspecialchars($buyerName, ENT_QUOTES, 'UTF-8') . '"
                  data-prefill.email="' . htmlspecialchars($buyerEmail, ENT_QUOTES, 'UTF-8') . '"
                  data-theme.color="#3399cc"></script>
          <input type="hidden" name="invoice_id" value="' . (int) $invoice->id . '">
        </form>';

        return $form;
    }

    private function isWebhook(array $data): bool
    {
        $rawBody = $data['http_raw_post_data'] ?? '';
        if (empty($rawBody)) {
            return false;
        }

        $payload = json_decode($rawBody, true);

        return is_array($payload) && isset($payload['event']);
    }

    private function getWebhookSecret(): string
    {
        if (!empty($this->config['test_mode'])) {
            return !empty($this->config['test_webhook_secret'])
                ? $this->config['test_webhook_secret']
                : ($this->config['webhook_secret'] ?? '');
        }

        return $this->config['webhook_secret'] ?? '';
    }

    private function processWebhook($api_admin, $id, array $data, int $gateway_id): void
    {
        $rawBody = $data['http_raw_post_data'] ?? '';
        $webhookSecret = $this->getWebhookSecret();

        if (!empty($webhookSecret)) {
            $signature = $data['server']['HTTP_X_RAZORPAY_SIGNATURE']
                ?? $data['server']['X-Razorpay-Signature']
                ?? '';

            if (empty($signature) && function_exists('getallheaders')) {
                $headers = getallheaders();
                $signature = $headers['X-Razorpay-Signature'] ?? $headers['x-razorpay-signature'] ?? '';
            }

            if (empty($signature)) {
                throw new Payment_Exception('Missing X-Razorpay-Signature header in webhook request');
            }

            $this->razorpay->utility->verifyWebhookSignature($rawBody, $signature, $webhookSecret);
        }

        $payload = json_decode($rawBody, true);
        $event = $payload['event'] ?? '';
        $tx = $this->di['db']->getExistingModelById('Transaction', $id, 'Transaction not found');

        switch ($event) {
            case 'subscription.charged':
                $this->handleSubscriptionCharged($api_admin, $tx, $payload, $gateway_id);
                break;

            case 'subscription.authenticated':
            case 'subscription.activated':
                $this->handleSubscriptionStatusChange($api_admin, $tx, $payload, 'active');
                break;

            case 'subscription.paused':
                $this->handleSubscriptionStatusChange($api_admin, $tx, $payload, 'paused');
                break;

            case 'subscription.resumed':
                $this->handleSubscriptionStatusChange($api_admin, $tx, $payload, 'active');
                break;

            case 'subscription.cancelled':
            case 'subscription.completed':
                $this->handleSubscriptionStatusChange($api_admin, $tx, $payload, 'canceled');
                break;

            case 'payment.captured':
                $this->handlePaymentCaptured($api_admin, $tx, $payload, $gateway_id);
                break;

            case 'payment.failed':
                $this->handlePaymentFailed($api_admin, $tx, $payload);
                break;

            default:
                $tx->status = 'processed';
                $tx->updated_at = date('Y-m-d H:i:s');
                $this->di['db']->store($tx);
                break;
        }

        $this->output = json_encode(['status' => 'ok']);
    }

    private function handleSubscriptionCharged($api_admin, $tx, array $payload, int $gateway_id): void
    {
        $subEntity = $payload['payload']['subscription']['entity'] ?? [];
        $payEntity = $payload['payload']['payment']['entity'] ?? [];

        $subscriptionId = $subEntity['id'] ?? '';
        $paymentId = $payEntity['id'] ?? '';
        $amount = (float) (($payEntity['amount'] ?? 0) / 100);
        $currency = $payEntity['currency'] ?? 'INR';
        $notes = $subEntity['notes'] ?? [];
        $invoiceId = $notes['invoice_id'] ?? null;
        $clientId = $notes['client_id'] ?? null;

        // Check for duplicate transaction processing
        if (!empty($paymentId)) {
            $existingTx = $this->di['db']->findOne('Transaction', 'txn_id = :txn_id AND status = "processed"', [':txn_id' => $paymentId]);
            if ($existingTx && $existingTx->id != $tx->id) {
                $tx->status = 'processed';
                $tx->txn_id = $paymentId;
                $tx->amount = $amount;
                $tx->currency = $currency;
                $tx->s_id = $subscriptionId;
                $tx->updated_at = date('Y-m-d H:i:s');
                $this->di['db']->store($tx);
                return;
            }
        }

        $invoice = null;
        if (!empty($invoiceId)) {
            try {
                $invoice = $this->di['db']->load('Invoice', $invoiceId);
            } catch (\Throwable $e) {}
        }

        $invoiceService = $this->di['mod_service']('Invoice');

        // Check if renewal payment
        if ($invoice && $invoice->id && $invoice->status === 'paid') {
            if (method_exists($invoiceService, 'generateRenewalInvoiceForSubscriptionPayment') && !empty($subscriptionId)) {
                $targetClientId = (int) ($clientId ?? $invoice->client_id);
                try {
                    $renewalInvoice = $invoiceService->generateRenewalInvoiceForSubscriptionPayment($subscriptionId, $targetClientId);
                    if ($renewalInvoice && !empty($renewalInvoice->id)) {
                        $invoice = $this->di['db']->load('Invoice', $renewalInvoice->id);
                    }
                } catch (\Throwable $e) {
                    if (isset($this->di['logger'])) {
                        $this->di['logger']->warning('Could not generate renewal invoice: ' . $e->getMessage());
                    }
                }
            }
        }

        $this->createOrUpdateSubscriptionRecord($api_admin, $subEntity, $invoice, $gateway_id);

        $tx->invoice_id = ($invoice && $invoice->id) ? $invoice->id : null;
        $tx->txn_id = $paymentId;
        $tx->amount = $amount;
        $tx->currency = $currency;
        $tx->txn_status = $payEntity['status'] ?? 'captured';
        $tx->status = 'processed';
        $tx->s_id = $subscriptionId;
        $tx->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($tx);

        $targetClientId = $clientId ?? ($invoice ? $invoice->client_id : null);
        if ($targetClientId) {
            $client = $this->di['db']->load('Client', $targetClientId);
            if ($client && $client->id) {
                $clientService = $this->di['mod_service']('client');
                $clientService->addFunds($client, $amount, 'Razorpay subscription ' . $subscriptionId . ' charge ' . $paymentId, [
                    'amount'      => $amount,
                    'description' => 'Razorpay subscription charge ' . $paymentId,
                    'type'        => 'transaction',
                    'rel_id'      => $tx->id,
                ]);

                if ($invoice && $invoice->id && $invoice->status !== 'paid') {
                    $invoiceService->payInvoiceWithCredits($invoice);
                }
                $invoiceService->doBatchPayWithCredits(['client_id' => $targetClientId]);
            }
        }
    }

    private function handleSubscriptionStatusChange($api_admin, $tx, array $payload, string $status): void
    {
        $subEntity = $payload['payload']['subscription']['entity'] ?? [];
        $subscriptionId = $subEntity['id'] ?? '';
        if (!empty($subscriptionId)) {
            $this->updateSubscriptionStatus($api_admin, $subscriptionId, $status);
        }

        $tx->txn_status = $payload['event'] ?? $status;
        $tx->status = 'processed';
        $tx->s_id = $subscriptionId;
        $tx->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($tx);
    }

    private function handlePaymentCaptured($api_admin, $tx, array $payload, int $gateway_id): void
    {
        $payEntity = $payload['payload']['payment']['entity'] ?? [];
        $paymentId = $payEntity['id'] ?? '';
        $amount = (float) (($payEntity['amount'] ?? 0) / 100);
        $currency = $payEntity['currency'] ?? 'INR';
        $notes = $payEntity['notes'] ?? [];
        $invoiceId = $notes['invoice_id'] ?? null;

        if (!empty($paymentId)) {
            $existingTx = $this->di['db']->findOne('Transaction', 'txn_id = :txn_id AND status = "processed"', [':txn_id' => $paymentId]);
            if ($existingTx && $existingTx->id != $tx->id) {
                $tx->status = 'processed';
                $tx->txn_id = $paymentId;
                $tx->amount = $amount;
                $tx->currency = $currency;
                $tx->updated_at = date('Y-m-d H:i:s');
                $this->di['db']->store($tx);
                return;
            }
        }

        $invoice = null;
        if (!empty($invoiceId)) {
            try {
                $invoice = $this->di['db']->load('Invoice', $invoiceId);
            } catch (\Throwable $e) {}
        }

        $tx->invoice_id = ($invoice && $invoice->id) ? $invoice->id : null;
        $tx->txn_id = $paymentId;
        $tx->amount = $amount;
        $tx->currency = $currency;
        $tx->txn_status = $payEntity['status'] ?? 'captured';
        $tx->status = 'processed';
        $tx->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($tx);

        if ($invoice && $invoice->id && $invoice->status !== 'paid') {
            $client = $this->di['db']->load('Client', $invoice->client_id);
            if ($client && $client->id) {
                $clientService = $this->di['mod_service']('client');
                $invoiceService = $this->di['mod_service']('Invoice');

                $clientService->addFunds($client, $amount, 'Razorpay transaction ' . $paymentId, [
                    'amount'      => $amount,
                    'description' => 'Razorpay transaction ' . $paymentId,
                    'type'        => 'transaction',
                    'rel_id'      => $tx->id,
                ]);

                $invoiceService->payInvoiceWithCredits($invoice);
                $invoiceService->doBatchPayWithCredits(['client_id' => $invoice->client_id]);
            }
        }
    }

    private function handlePaymentFailed($api_admin, $tx, array $payload): void
    {
        $payEntity = $payload['payload']['payment']['entity'] ?? [];
        $paymentId = $payEntity['id'] ?? '';
        $tx->txn_id = $paymentId;
        $tx->txn_status = 'failed';
        $tx->status = 'error';
        $tx->error = $payEntity['error_description'] ?? 'Razorpay payment failed';
        $tx->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($tx);
    }

    private function createOrUpdateSubscriptionRecord($api_admin, array $subEntity, $invoice, int $gateway_id): void
    {
        $subscriptionId = $subEntity['id'] ?? '';
        if (empty($subscriptionId)) {
            return;
        }

        $existing = $this->di['db']->findOne('Subscription', 'sid = :sid', [':sid' => $subscriptionId]);
        if (!$existing) {
            $periodCode = null;
            if ($invoice && !empty($invoice->id)) {
                $periodDetails = $this->getInvoiceSubscriptionPeriod($invoice);
                $periodCode = $periodDetails['code'];
            }

            $sd = [
                'client_id'  => $invoice ? $invoice->client_id : ($subEntity['notes']['client_id'] ?? null),
                'gateway_id' => $gateway_id,
                'currency'   => $invoice ? $invoice->currency : 'INR',
                'sid'        => $subscriptionId,
                'status'     => 'active',
                'period'     => $periodCode ?? '1M',
                'amount'     => (string) (($subEntity['plan']['item']['amount'] ?? 0) / 100),
                'rel_type'   => 'invoice',
                'rel_id'     => $invoice ? $invoice->id : null,
            ];

            try {
                $api_admin->invoice_subscription_create($sd);
            } catch (\Throwable $e) {
                try {
                    $newSub = $this->di['db']->dispense('Subscription');
                    $newSub->client_id = $sd['client_id'];
                    $newSub->pay_gateway_id = $sd['gateway_id'];
                    $newSub->currency = $sd['currency'];
                    $newSub->sid = $sd['sid'];
                    $newSub->status = $sd['status'];
                    $newSub->period = $sd['period'];
                    $newSub->amount = $sd['amount'];
                    $newSub->rel_type = $sd['rel_type'];
                    $newSub->rel_id = $sd['rel_id'];
                    $newSub->created_at = date('Y-m-d H:i:s');
                    $newSub->updated_at = date('Y-m-d H:i:s');
                    $this->di['db']->store($newSub);
                } catch (\Throwable $ex) {
                    if (isset($this->di['logger'])) {
                        $this->di['logger']->error('Could not create subscription in FOSSBilling: ' . $ex->getMessage());
                    }
                }
            }
        } else {
            $existing->status = 'active';
            $existing->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($existing);
        }
    }

    private function updateSubscriptionStatus($api_admin, string $subscriptionId, string $status): void
    {
        $sub = $this->di['db']->findOne('Subscription', 'sid = :sid', [':sid' => $subscriptionId]);
        if ($sub && $sub->id) {
            $updated = false;
            if (isset($api_admin) && is_object($api_admin) && method_exists($api_admin, 'invoice_subscription_update')) {
                try {
                    $api_admin->invoice_subscription_update([
                        'id'     => $sub->id,
                        'status' => $status,
                    ]);
                    $updated = true;
                } catch (\Throwable $e) {}
            }
            if (!$updated) {
                $sub->status = $status;
                $sub->updated_at = date('Y-m-d H:i:s');
                $this->di['db']->store($sub);
            }
        }
    }

    public function processTransaction($api_admin, $id, $data, $gateway_id)
    {
        if ($this->isWebhook($data)) {
            $this->processWebhook($api_admin, $id, $data, $gateway_id);
            return;
        }

        $tx = $this->di['db']->getExistingModelById('Transaction', $id, 'Transaction not found');
        $invoiceId = $data['post']['invoice_id'] ?? $data['get']['invoice_id'] ?? $tx->invoice_id ?? null;
        $invoice = $this->di['db']->getExistingModelById('Invoice', $invoiceId, 'Invoice not found');

        $tx->invoice_id = $invoice->id;

        try {
            $isSubscription = !empty($data['post']['razorpay_subscription_id']);

            if ($isSubscription) {
                $attributes = [
                    'razorpay_subscription_id' => $data['post']['razorpay_subscription_id'] ?? '',
                    'razorpay_payment_id'      => $data['post']['razorpay_payment_id'] ?? '',
                    'razorpay_signature'       => $data['post']['razorpay_signature'] ?? '',
                ];
                $this->razorpay->utility->verifyPaymentSignature($attributes);
                $subscriptionId = $attributes['razorpay_subscription_id'];
                $tx->s_id = $subscriptionId;
            } else {
                $attributes = [
                    'razorpay_order_id'   => $data['post']['razorpay_order_id'] ?? '',
                    'razorpay_payment_id' => $data['post']['razorpay_payment_id'] ?? '',
                    'razorpay_signature'  => $data['post']['razorpay_signature'] ?? '',
                ];
                $this->razorpay->utility->verifyPaymentSignature($attributes);
            }

            $payment = $this->razorpay->payment->fetch($attributes['razorpay_payment_id']);

            $tx->txn_id = $payment->id;
            $tx->amount = $payment->amount / 100;
            $tx->currency = $payment->currency;
            $tx->txn_status = $payment->status;
            $tx->status = $payment->status === 'captured' ? 'processed' : 'error';
            $tx->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($tx);

            if ($payment->status === 'captured') {
                if ($isSubscription) {
                    $periodDetails = $this->getInvoiceSubscriptionPeriod($invoice);
                    $tx->s_period = $periodDetails['code'];
                    $this->di['db']->store($tx);

                    $existingSub = $this->di['db']->findOne('Subscription', 'sid = :sid', [':sid' => $subscriptionId]);
                    if (!$existingSub) {
                        $sd = [
                            'client_id'  => $invoice->client_id,
                            'gateway_id' => $gateway_id,
                            'currency'   => $invoice->currency,
                            'sid'        => $subscriptionId,
                            'status'     => 'active',
                            'period'     => $periodDetails['code'],
                            'amount'     => (string) ($payment->amount / 100),
                            'rel_type'   => 'invoice',
                            'rel_id'     => $invoice->id,
                        ];
                        try {
                            $api_admin->invoice_subscription_create($sd);
                        } catch (\Throwable $e) {
                            try {
                                $newSub = $this->di['db']->dispense('Subscription');
                                $newSub->client_id = $sd['client_id'];
                                $newSub->pay_gateway_id = $sd['gateway_id'];
                                $newSub->currency = $sd['currency'];
                                $newSub->sid = $sd['sid'];
                                $newSub->status = $sd['status'];
                                $newSub->period = $sd['period'];
                                $newSub->amount = $sd['amount'];
                                $newSub->rel_type = $sd['rel_type'];
                                $newSub->rel_id = $sd['rel_id'];
                                $newSub->created_at = date('Y-m-d H:i:s');
                                $newSub->updated_at = date('Y-m-d H:i:s');
                                $this->di['db']->store($newSub);
                            } catch (\Throwable $ex) {}
                        }
                    }
                }

                // Credit client balance and pay invoice if invoice is not yet paid
                if ($invoice->status !== 'paid') {
                    $clientService = $this->di['mod_service']('client');
                    $invoiceService = $this->di['mod_service']('Invoice');
                    $client = $this->di['db']->getExistingModelById('Client', $invoice->client_id, 'Client not found');

                    $clientService->addFunds($client, $tx->amount, 'Razorpay transaction ' . $payment->id, [
                        'amount'      => $tx->amount,
                        'description' => 'Razorpay transaction ' . $payment->id,
                        'type'        => 'transaction',
                        'rel_id'      => $tx->id,
                    ]);

                    $invoiceService->payInvoiceWithCredits($invoice);
                    $invoiceService->doBatchPayWithCredits(['client_id' => $invoice->client_id]);
                }
            }
        } catch (SignatureVerificationError $e) {
            $tx->status = 'error';
            $tx->error = 'Signature verification failed: ' . $e->getMessage();
            $tx->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($tx);

            throw new Payment_Exception('Invalid signature');
        } catch (\Exception $e) {
            $tx->status = 'error';
            $tx->error = $e->getMessage();
            $tx->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($tx);

            throw new Payment_Exception('There was an error when processing the Razorpay transaction: ' . $e->getMessage());
        }
    }

    public function cancelSubscription(string $subscriptionId): void
    {
        try {
            $subscription = $this->razorpay->subscription->fetch($subscriptionId);
            if (in_array($subscription->status, ['cancelled', 'completed'], true)) {
                return;
            }
            $subscription->cancel(['cancel_at_cycle_end' => 0]);
        } catch (\Throwable $e) {
            if (stripos($e->getMessage(), 'cancell') !== false) {
                return;
            }
            if (isset($this->di['logger'])) {
                $this->di['logger']->error('Razorpay cancelSubscription error: ' . $e->getMessage());
            }
            throw new Payment_Exception('Failed to cancel Razorpay subscription: ' . $e->getMessage());
        }
    }

    public function cancelSubscriptionAtPeriodEnd(string $subscriptionId): void
    {
        try {
            $subscription = $this->razorpay->subscription->fetch($subscriptionId);
            if (in_array($subscription->status, ['cancelled', 'completed'], true)) {
                return;
            }
            $subscription->cancel(['cancel_at_cycle_end' => 1]);
        } catch (\Throwable $e) {
            if (stripos($e->getMessage(), 'cancell') !== false) {
                return;
            }
            if (isset($this->di['logger'])) {
                $this->di['logger']->error('Razorpay cancelSubscriptionAtPeriodEnd error: ' . $e->getMessage());
            }
            throw new Payment_Exception('Failed to schedule cancellation for Razorpay subscription: ' . $e->getMessage());
        }
    }

    public function pauseSubscription(string $subscriptionId, array $options = []): void
    {
        try {
            $subscription = $this->razorpay->subscription->fetch($subscriptionId);
            $subscription->pause($options);
        } catch (\Throwable $e) {
            throw new Payment_Exception('Failed to pause Razorpay subscription: ' . $e->getMessage());
        }
    }

    public function resumeSubscription(string $subscriptionId, array $options = []): void
    {
        try {
            $subscription = $this->razorpay->subscription->fetch($subscriptionId);
            $subscription->resume($options);
        } catch (\Throwable $e) {
            throw new Payment_Exception('Failed to resume Razorpay subscription: ' . $e->getMessage());
        }
    }
}
