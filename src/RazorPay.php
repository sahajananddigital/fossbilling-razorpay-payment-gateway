<?php

use Razorpay\Api\Api;

class Payment_Adapter_Razorpay implements FOSSBilling\InjectionAwareInterface
{
    protected ?Pimple\Container $di = null;
    private Api $razorpay;

    public function setDi(Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?Pimple\Container
    {
        return $this->di;
    }

    public function __construct(private $config)
    {
        if ($this->config['test_mode']) {
            if (!isset($this->config['test_key_id']) || !isset($this->config['test_key_secret'])) {
                throw new Payment_Exception('Razorpay test mode keys are missing in configuration.');
            }
            $this->razorpay = new Api($this->config['test_key_id'], $this->config['test_key_secret']);
        } else {
            if (!isset($this->config['key_id']) || !isset($this->config['key_secret'])) {
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
            ],
        ];
    }

    private function getAmountInPaise(Model_Invoice $invoice): int
    {
        $invoiceService = $this->di['mod_service']('Invoice');
        return $invoiceService->getTotalWithTax($invoice) * 100;
    }

    public function getHtml($api_admin, $invoice_id, $subscription)
    {
        $invoice = $this->di['db']->load('Invoice', $invoice_id);

        // Create Razorpay order
        $order = $this->razorpay->order->create([
            'receipt'         => 'INV-' . $invoice->id,
            'amount'          => $this->getAmountInPaise($invoice),
            'currency'        => $invoice->currency,
            'payment_capture' => 1,
        ]);

        $keyId = $this->config['test_mode'] ? $this->config['test_key_id'] : $this->config['key_id'];

        $payGatewayService = $this->di['mod_service']('Invoice', 'PayGateway');
        $payGateway = $this->di['db']->findOne('PayGateway', 'gateway = "Razorpay"');

        $bindings = [
            ':callbackUrl' => $payGatewayService->getCallbackUrl($payGateway, $invoice),
            ':keyId'       => $keyId,
            ':amount'      => $this->getAmountInPaise($invoice),
            ':currency'    => $invoice->currency,
            ':orderId'     => $order['id'],
            ':title'       => 'Invoice #' . $invoice->id,
            ':description' => 'Payment for Invoice #' . $invoice->id,
            ':buyer_name'  => trim($invoice->buyer_first_name . ' ' . $invoice->buyer_last_name),
            ':buyer_email' => $invoice->buyer_email,
        ];

        $form = '
        <form action=":callbackUrl" method="POST">
          <script src="https://checkout.razorpay.com/v1/checkout.js"
                  data-key=":keyId"
                  data-amount=":amount"
                  data-currency=":currency"
                  data-order_id=":orderId"
                  data-buttontext="Pay with Razorpay"
                  data-name=":title"
                  data-description=":description"
                  data-prefill.name=":buyer_name"
                  data-prefill.email=":buyer_email"
                  data-theme.color="#3399cc"></script>
          <input type="hidden" name="invoice_id" value="' . $invoice->id . '">
        </form>';

        return strtr($form, $bindings);
    }

    public function processTransaction($api_admin, $id, $data, $gateway_id)
    {
        $tx = $this->di['db']->getExistingModelById('Transaction', $id);

        $invoice = $this->di['db']->getExistingModelById('Invoice', $data['post']['invoice_id']);
        $tx->invoice_id = $invoice->id;

        try {
            // Razorpay signature verification
            $attributes = [
                'razorpay_order_id'   => $data['post']['razorpay_order_id'] ?? '',
                'razorpay_payment_id' => $data['post']['razorpay_payment_id'] ?? '',
                'razorpay_signature'  => $data['post']['razorpay_signature'] ?? '',
            ];
            $this->razorpay->utility->verifyPaymentSignature($attributes);

            // Fetch payment details
            $payment = $this->razorpay->payment->fetch($attributes['razorpay_payment_id']);

            $tx->txn_id = $payment->id;
            $tx->amount = $payment->amount / 100;
            $tx->currency = $payment->currency;
            $tx->txn_status = $payment->status;
            $tx->status = $payment->status === 'captured' ? 'processed' : 'error';
            $tx->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($tx);

            if ($payment->status === 'captured' && $tx->status !== 'processed') {
                $clientService = $this->di['mod_service']('client');
                $invoiceService = $this->di['mod_service']('Invoice');
                $client = $this->di['db']->getExistingModelById('Client', $invoice->client_id);

                $clientService->addFunds($client, $tx->amount, 'Razorpay transaction ' . $payment->id, [
                    'amount' => $tx->amount,
                    'description' => 'Razorpay transaction ' . $payment->id,
                    'type' => 'transaction',
                    'rel_id' => $tx->id,
                ]);

                $invoiceService->payInvoiceWithCredits($invoice);
            }
        } catch (\Exception $e) {
            $tx->status = 'error';
            $tx->error = $e->getMessage();
            $tx->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($tx);

            throw new FOSSBilling\Exception('There was an error when processing the Razorpay transaction: ' . $e->getMessage());
        }
    }
}
