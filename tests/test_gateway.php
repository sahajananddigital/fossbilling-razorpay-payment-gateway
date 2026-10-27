<?php

declare(strict_types=1);

namespace Pimple {
    if (!class_exists(\Pimple\Container::class)) {
        class Container implements \ArrayAccess {
            private array $values = [];
            public function offsetExists(mixed $offset): bool { return isset($this->values[$offset]); }
            public function offsetGet(mixed $offset): mixed { return $this->values[$offset] ?? null; }
            public function offsetSet(mixed $offset, mixed $value): void { $this->values[$offset] = $value; }
            public function offsetUnset(mixed $offset): void { unset($this->values[$offset]); }
        }
    }
}

namespace FOSSBilling {
    if (!interface_exists(\FOSSBilling\InjectionAwareInterface::class)) {
        interface InjectionAwareInterface
        {
            public function setDi(\Pimple\Container $di): void;
            public function getDi(): ?\Pimple\Container;
        }
    }
}

namespace {
    require_once __DIR__ . '/../vendor/autoload.php';

    if (!class_exists('Payment_AdapterAbstract')) {
        abstract class Payment_AdapterAbstract
        {
            protected ?string $output = null;
            public function getOutput(): ?string { return $this->output; }
        }
    }

    if (!class_exists('Payment_Exception')) {
        class Payment_Exception extends \Exception {}
    }

    if (!class_exists('Model_Invoice')) {
        class Model_Invoice {
            public $id = 42;
            public $client_id = 10;
            public $currency = 'INR';
            public $hash = 'hash123456';
            public $status = 'unpaid';
            public $buyer_first_name = 'Jane';
            public $buyer_last_name = 'Doe';
            public $buyer_email = 'jane@example.com';
        }
    }

    require_once __DIR__ . '/../src/Razorpay.php';

    echo "Running tests for Razorpay Payment Gateway Adapter...\n\n";

    $passCount = 0;
    $failCount = 0;

    function assertCondition(bool $cond, string $message) {
        global $passCount, $failCount;
        if ($cond) {
            echo "  [PASS] $message\n";
            $passCount++;
        } else {
            echo "  [FAIL] $message\n";
            $failCount++;
        }
    }

    // 1. Test getConfig
    echo "1. Testing getConfig()...\n";
    $config = Payment_Adapter_Razorpay::getConfig();
    assertCondition($config['supports_subscriptions'] === true, "supports_subscriptions is true");
    assertCondition($config['supports_one_time_payments'] === true, "supports_one_time_payments is true");
    assertCondition(isset($config['form']['key_id']), "key_id in form");
    assertCondition(isset($config['form']['key_secret']), "key_secret in form");
    assertCondition(isset($config['form']['webhook_secret']), "webhook_secret in form");
    assertCondition(isset($config['form']['test_webhook_secret']), "test_webhook_secret in form");

    // 2. Test period parsing
    echo "\n2. Testing subscription period parsing...\n";
    $adapter = new Payment_Adapter_Razorpay([
        'test_mode' => true,
        'test_key_id' => 'rzp_test_123',
        'test_key_secret' => 'secret_123',
        'test_webhook_secret' => 'whsec_123',
    ]);

    $reflection = new ReflectionClass($adapter);
    $parsePeriodMethod = $reflection->getMethod('parseSubscriptionPeriod');

    $p1M = $parsePeriodMethod->invoke($adapter, '1M');
    assertCondition($p1M['period'] === 'monthly' && $p1M['interval'] === 1 && $p1M['code'] === '1M', "1M parses to monthly, 1");

    $p3M = $parsePeriodMethod->invoke($adapter, '3M');
    assertCondition($p3M['period'] === 'monthly' && $p3M['interval'] === 3 && $p3M['total_count'] === 400, "3M parses to monthly, 3");

    $p1Y = $parsePeriodMethod->invoke($adapter, '1Y');
    assertCondition($p1Y['period'] === 'yearly' && $p1Y['interval'] === 1 && $p1Y['total_count'] === 100, "1Y parses to yearly, 1");

    $p1W = $parsePeriodMethod->invoke($adapter, '1W');
    assertCondition($p1W['period'] === 'weekly' && $p1W['interval'] === 1 && $p1W['total_count'] === 5200, "1W parses to weekly, 1");

    $p30D = $parsePeriodMethod->invoke($adapter, '30D');
    assertCondition($p30D['period'] === 'daily' && $p30D['interval'] === 30, "30D parses to daily, 30");

    // 3. Test getHtml for subscription
    echo "\n3. Testing getHtml() for subscription...\n";

    // Set up mock DI container
    $di = new Pimple\Container();
    $invoice = new Model_Invoice();

    $dbMock = new class($invoice) {
        private $invoice;
        public function __construct($inv) { $this->invoice = $inv; }
        public function getExistingModelById($model, $id, $msg) { return $this->invoice; }
        public function findOne($table, $where, $params = []) {
            if ($table === 'PayGateway') {
                return (object)['id' => 1, 'gateway' => 'Razorpay'];
            }
            if ($table === 'InvoiceItem') {
                return (object)['period' => '1M'];
            }
            return null;
        }
    };

    $di['db'] = $dbMock;
    $di['mod_service'] = function($mod, $sub = null) {
        if ($mod === 'Invoice' && $sub === 'PayGateway') {
            return new class {
                public function getCallbackUrl($gateway, $inv) {
                    return 'https://example.com/ipn.php?gateway_id=' . $gateway->id . '&invoice_id=' . $inv->id;
                }
            };
        }
        if ($mod === 'Invoice') {
            return new class {
                public function getTotalWithTax($inv) { return 49.99; }
            };
        }
        return null;
    };

    $adapter->setDi($di);

    // Mock Razorpay API for plan and subscription creation
    $mockApi = new class('rzp_test_123', 'secret_123') extends \Razorpay\Api\Api {
        public $plan;
        public $subscription;
        public $order;
        public function __construct($k, $s) {
            parent::__construct($k, $s);
            $this->plan = new class {
                public function create($data) {
                    return (object)['id' => 'plan_test123'];
                }
            };
            $this->subscription = new class {
                public function create($data) {
                    return (object)['id' => 'sub_test999'];
                }
            };
            $this->order = new class {
                public function create($data) {
                    return ['id' => 'order_test456'];
                }
            };
        }
    };

    $reflectionApi = new ReflectionProperty($adapter, 'razorpay');
    $reflectionApi->setValue($adapter, $mockApi);

    $subscriptionHtml = $adapter->getHtml(null, 42, true);
    assertCondition(strpos($subscriptionHtml, 'sub_test999') !== false, "Subscription HTML contains subscription ID");
    assertCondition(strpos($subscriptionHtml, 'Subscribe with Razorpay') !== false, "Subscription HTML contains subscribe button");
    assertCondition(strpos($subscriptionHtml, 'razorpay_subscription_id') !== false, "Subscription HTML contains hidden subscription field");

    // Test one-time payment HTML
    $oneTimeHtml = $adapter->getHtml(null, 42, false);
    assertCondition(strpos($oneTimeHtml, 'order_test456') !== false, "One-time HTML contains order ID");
    assertCondition(strpos($oneTimeHtml, 'data-amount="4999"') !== false, "One-time HTML contains correct amount in paise (4999)");

    // 4. Test Webhook Processing (subscription.charged)
    echo "\n4. Testing Webhook Processing (subscription.charged)...\n";
    $whSecret = 'whsec_123';
    $webhookPayload = json_encode([
        'entity' => 'event',
        'account_id' => 'acc_test',
        'event' => 'subscription.charged',
        'payload' => [
            'subscription' => [
                'entity' => [
                    'id' => 'sub_test999',
                    'status' => 'active',
                    'plan_id' => 'plan_test123',
                    'notes' => [
                        'invoice_id' => '42',
                        'client_id' => '10',
                    ],
                ],
            ],
            'payment' => [
                'entity' => [
                    'id' => 'pay_test888',
                    'amount' => 4999,
                    'currency' => 'INR',
                    'status' => 'captured',
                ],
            ],
        ],
    ]);

    $expectedSignature = hash_hmac('sha256', $webhookPayload, $whSecret);

    $txObject = new class {
        public $id = 101;
        public $invoice_id = null;
        public $txn_id = null;
        public $amount = null;
        public $currency = null;
        public $txn_status = null;
        public $status = 'received';
        public $s_id = null;
        public $s_period = null;
        public $error = null;
        public $updated_at = null;
    };

    $clientObject = (object)['id' => 10, 'balance' => 0];

    $fundsAdded = false;
    $invoicePaid = false;

    $dbMockWithTx = new class($invoice, $txObject, $clientObject) {
        public $invoice;
        public $tx;
        public $client;
        public $stored = [];
        public function __construct($inv, $tx, $cl) {
            $this->invoice = $inv;
            $this->tx = $tx;
            $this->client = $cl;
        }
        public function getExistingModelById($m, $id, $msg) {
            if ($m === 'Transaction') return $this->tx;
            if ($m === 'Invoice') return $this->invoice;
            if ($m === 'Client') return $this->client;
            return null;
        }
        public function load($m, $id) {
            if ($m === 'Invoice') return $this->invoice;
            if ($m === 'Client') return $this->client;
            return null;
        }
        public function findOne($t, $w, $p = []) {
            return null;
        }
        public function dispense($t) {
            return (object)['id' => 1];
        }
        public function store($obj) {
            $this->stored[] = $obj;
        }
    };

    $di['db'] = $dbMockWithTx;
    $di['mod_service'] = function($mod, $sub = null) use (&$fundsAdded, &$invoicePaid) {
        if ($mod === 'client') {
            return new class($fundsAdded) {
                private $ref;
                public function __construct(&$ref) { $this->ref = &$ref; }
                public function addFunds($client, $amount, $desc, $data) {
                    $this->ref = true;
                }
            };
        }
        if ($mod === 'Invoice') {
            return new class($invoicePaid) {
                private $ref;
                public function __construct(&$ref) { $this->ref = &$ref; }
                public function payInvoiceWithCredits($inv) {
                    $this->ref = true;
                }
                public function doBatchPayWithCredits($d) {}
                public function getTotalWithTax($inv) { return 49.99; }
            };
        }
        return null;
    };

    $webhookData = [
        'http_raw_post_data' => $webhookPayload,
        'server' => [
            'HTTP_X_RAZORPAY_SIGNATURE' => $expectedSignature,
        ],
    ];

    // Set real Utility back into adapter for signature check
    $realApi = new \Razorpay\Api\Api('rzp_test_123', 'secret_123');
    $adapter->setRazorpayApi($realApi);

    $adapter->processTransaction(null, 101, $webhookData, 1);

    assertCondition($txObject->status === 'processed', "Transaction status is processed");
    assertCondition($txObject->txn_id === 'pay_test888', "Transaction txn_id is pay_test888");
    assertCondition($txObject->amount === 49.99, "Transaction amount is 49.99");
    assertCondition($txObject->s_id === 'sub_test999', "Transaction s_id is sub_test999");
    assertCondition($fundsAdded === true, "Client funds were added");
    assertCondition($invoicePaid === true, "Invoice was paid with credits");

    // 5. Test Webhook status updates (subscription.cancelled)
    echo "\n5. Testing Webhook status updates (subscription.cancelled)...\n";
    $cancelPayload = json_encode([
        'entity' => 'event',
        'event' => 'subscription.cancelled',
        'payload' => [
            'subscription' => [
                'entity' => [
                    'id' => 'sub_test999',
                    'status' => 'cancelled',
                ],
            ],
        ],
    ]);
    $cancelSig = hash_hmac('sha256', $cancelPayload, $whSecret);

    $cancelData = [
        'http_raw_post_data' => $cancelPayload,
        'server' => [
            'HTTP_X_RAZORPAY_SIGNATURE' => $cancelSig,
        ],
    ];

    $txObject->status = 'received';
    $adapter->processTransaction(null, 101, $cancelData, 1);
    assertCondition($txObject->status === 'processed', "Cancellation webhook marked transaction processed");

    // 6. Test cancelSubscription methods
    echo "\n6. Testing cancelSubscription methods...\n";
    $cancelCalled = false;
    $cancelCycleEnd = null;

    $subApiMock = new class($cancelCalled, $cancelCycleEnd) extends \Razorpay\Api\Api {
        public $subscription;
        public function __construct(&$called, &$cycleEnd) {
            parent::__construct('rzp_test_123', 'secret_123');
            $this->subscription = new class($called, $cycleEnd) {
                private $c;
                private $ce;
                public function __construct(&$c, &$ce) { $this->c = &$c; $this->ce = &$ce; }
                public function fetch($id) {
                    return new class($this->c, $this->ce) {
                        public $status = 'active';
                        private $c;
                        private $ce;
                        public function __construct(&$c, &$ce) { $this->c = &$c; $this->ce = &$ce; }
                        public function cancel($options) {
                            $this->c = true;
                            $this->ce = $options['cancel_at_cycle_end'] ?? null;
                        }
                    };
                }
            };
        }
    };

    $reflectionApi->setValue($adapter, $subApiMock);

    $adapter->cancelSubscription('sub_test999');
    assertCondition($cancelCalled === true && $cancelCycleEnd === 0, "cancelSubscription calls cancel with cancel_at_cycle_end = 0");

    $cancelCalled = false;
    $cancelCycleEnd = null;
    $adapter->cancelSubscriptionAtPeriodEnd('sub_test999');
    assertCondition($cancelCalled === true && $cancelCycleEnd === 1, "cancelSubscriptionAtPeriodEnd calls cancel with cancel_at_cycle_end = 1");

    // 7. Test Checkout POST Callback
    echo "\n7. Testing Checkout POST Callback (Subscription & One-time)...\n";
    $postSubId = 'sub_post123';
    $postPayId = 'pay_post123';
    $keySecret = 'secret_123';
    $subSig = hash_hmac('sha256', $postPayId . '|' . $postSubId, $keySecret);

    $checkoutPostApiMock = new class('rzp_test_123', 'secret_123') extends \Razorpay\Api\Api {
        public $payment;
        public function __construct($k, $s) {
            parent::__construct($k, $s);
            $this->payment = new class {
                public function fetch($id) {
                    return (object)[
                        'id' => $id,
                        'amount' => 4999,
                        'currency' => 'INR',
                        'status' => 'captured',
                    ];
                }
            };
        }
    };
    $reflectionApi->setValue($adapter, $checkoutPostApiMock);

    $txObject->status = 'received';
    $txObject->txn_id = null;
    $txObject->s_id = null;
    $invoice->status = 'unpaid';
    $fundsAdded = false;
    $invoicePaid = false;

    $subReturnData = [
        'post' => [
            'invoice_id' => '42',
            'razorpay_payment_id' => $postPayId,
            'razorpay_subscription_id' => $postSubId,
            'razorpay_signature' => $subSig,
        ],
    ];

    $adapter->processTransaction(null, 101, $subReturnData, 1);
    assertCondition($txObject->status === 'processed', "Checkout callback marked transaction processed");
    assertCondition($txObject->s_id === $postSubId, "Transaction s_id set to subscription ID");
    assertCondition($txObject->txn_id === $postPayId, "Transaction txn_id set to payment ID");
    assertCondition($fundsAdded === true, "Client funds credited on checkout return");
    assertCondition($invoicePaid === true, "Invoice paid on checkout return");

    // Test invalid signature
    $invalidSigData = [
        'post' => [
            'invoice_id' => '42',
            'razorpay_payment_id' => $postPayId,
            'razorpay_subscription_id' => $postSubId,
            'razorpay_signature' => 'invalid_signature_here',
        ],
    ];
    $sigThrew = false;
    try {
        $adapter->processTransaction(null, 101, $invalidSigData, 1);
    } catch (\Payment_Exception $e) {
        $sigThrew = true;
    }
    assertCondition($sigThrew === true, "Invalid checkout signature throws Payment_Exception");

    echo "\nSummary: $passCount passed, $failCount failed.\n";

    if ($failCount > 0) {
        exit(1);
    }
    echo "All tests passed successfully!\n";
}
