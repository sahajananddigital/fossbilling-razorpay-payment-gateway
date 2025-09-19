<?php
// try to load Composer autoloader (checks a few common locations)
$autoloadCandidates = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
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

    public function __construct($config)
    {
        $this->config = (array)$config;

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
            'description' => 'Razorpay payment gateway for India (UPI, cards, wallets, netbanking).',
            'logo' => [
                'logo' => 'razorpay.png',
                'height' => '30px',
                'width' => '65px',
            ],
            'form' => [
                'key_id' => [
                    'text', [
                        'label' => 'Live Key ID:',
                    ],
                ],
                'key_secret' => [
                    'text', [
                        'label' => 'Live Key Secret:',
                    ],
                ],
                'test_key_id' => [
                    'text', [
                        'label' => 'Test Key ID:',
                        'required' => false,
                    ],
                ],
                'test_key_secret' => [
                    'text', [
                        'label' => 'Test Key Secret:',
                        'required' => false,
                    ],
                ],
                'test_mode' => [
                    'select', [
                        'label' => 'Enable Test Mode?',
                        'multiOptions' => [
                            0 => 'No',
                            1 => 'Yes',
                        ],
                    ],
                ],
            ],
        ];
    }

    private function getAmountInPaise(\Model_Invoice $invoice): int
    {
        $invoiceService = $this->di['mod_service']('Invoice');
        return intval($invoiceService->getTotalWithTax($invoice) * 100);
    }

    public function getHtml($api_admin, $invoice_id, $subscription)
    {
        $invoice = $this->di['db']->getExistingModelById('Invoice', $invoice_id, 'Invoice not found');

        $order = $this->razorpay->order->create([
            'receipt'         => 'INV-' . $invoice->id,
            'amount'          => $this->getAmountInPaise($invoice),
            'currency'        => $invoice->currency,
            'payment_capture' => 1,
        ]);

        $keyId = !empty($this->config['test_mode']) ? $this->config['test_key_id'] : $this->config['key_id'];

        $payGatewayService = $this->di['mod_service']('Invoice', 'PayGateway');
        $payGateway = $this->di['db']->findOne('PayGateway', 'gateway = "Razorpay"');
        $callbackUrl = $payGatewayService->getCallbackUrl($payGateway, $invoice);
        $doesRedirect = true;

        $form = '
        <form action="' . $callbackUrl .'&redirect='. $doesRedirect .'&invoice_hash='. $invoice->hash .'" method="POST">
          <script src="https://checkout.razorpay.com/v1/checkout.js"
                  data-key="' . $keyId . '"
                  data-amount="' . $this->getAmountInPaise($invoice) . '"
                  data-currency="' . $invoice->currency . '"
                  data-order_id="' . $order['id'] . '"
                  data-buttontext="Pay with Razorpay"
                  data-name="Invoice #' . $invoice->id . '"
                  data-description="Payment for Invoice #' . $invoice->id . '"
                  data-prefill.name="' . htmlentities(trim($invoice->buyer_first_name . ' ' . $invoice->buyer_last_name), ENT_QUOTES, 'UTF-8') . '"
                  data-prefill.email="' . htmlentities($invoice->buyer_email, ENT_QUOTES, 'UTF-8') . '"
                  data-theme.color="#3399cc"></script>
          <input type="hidden" name="invoice_id" value="' . $invoice->id . '">
        </form>';

        return $form;
    }

    public function processTransaction($api_admin, $id, $data, $gateway_id)
    {
        $tx = $this->di['db']->getExistingModelById('Transaction', $id, 'Transaction not found');
        $invoice = $this->di['db']->getExistingModelById('Invoice', $data['post']['invoice_id'] ?? null, 'Invoice not found');

        $tx->invoice_id = $invoice->id;

        try {
            $attributes = [
                'razorpay_order_id'   => $data['post']['razorpay_order_id'] ?? '',
                'razorpay_payment_id' => $data['post']['razorpay_payment_id'] ?? '',
                'razorpay_signature'  => $data['post']['razorpay_signature'] ?? '',
            ];
            $this->razorpay->utility->verifyPaymentSignature($attributes);

            $payment = $this->razorpay->payment->fetch($attributes['razorpay_payment_id']);

            $tx->txn_id = $payment->id;
            $tx->amount = $payment->amount / 100;
            $tx->currency = $payment->currency;
            $tx->txn_status = $payment->status;
            $tx->status = $payment->status === 'captured' ? 'processed' : 'error';
            $tx->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($tx);

            if ($payment->status === 'captured') {
                $clientService = $this->di['mod_service']('client');
                $invoiceService = $this->di['mod_service']('Invoice');
                $client = $this->di['db']->getExistingModelById('Client', $invoice->client_id, 'Client not found');

                $clientService->addFunds($client, $tx->amount, 'Razorpay transaction ' . $payment->id, [
                    'amount' => $tx->amount,
                    'description' => 'Razorpay transaction ' . $payment->id,
                    'type' => 'transaction',
                    'rel_id' => $tx->id,
                ]);

                $invoiceService->payInvoiceWithCredits($invoice);
                $invoiceService->doBatchPayWithCredits(['client_id' => $invoice->client_id]);
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
}
