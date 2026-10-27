<div align="center">
  <h1>Razorpay Integration for FOSSBilling</h1>
  <p>An open-source payment gateway to accept one-time payments and recurring subscriptions with Razorpay on your FOSSBilling installation.</p>
  
  <p>
    <a href="https://github.com/sahajananddigital/fossbilling-razorpay-payment-gateway/releases/latest"><img src="https://img.shields.io/github/v/release/sahajananddigital/fossbilling-razorpay-payment-gateway" alt="GitHub release (latest by date)"></a>
    <img src="https://img.shields.io/github/downloads/sahajananddigital/fossbilling-razorpay-payment-gateway/total" alt="GitHub all releases">
    <img src="https://img.shields.io/github/repo-size/sahajananddigital/fossbilling-razorpay-payment-gateway" alt="GitHub repo size">
    <a href="https://github.com/sahajananddigital/fossbilling-razorpay-payment-gateway/blob/main/LICENSE"><img alt="License" src="https://img.shields.io/github/license/sahajananddigital/fossbilling-razorpay-payment-gateway"></a>
  </p>
</div>

---

## Overview

This extension integrates the [Razorpay](https://razorpay.com) payment gateway with your [FOSSBilling](https://fossbilling.org) installation. It supports both **one-time invoice payments** and **automated recurring subscriptions** across India-centric and international payment modes including Credit/Debit cards, UPI Autopay, Netbanking, and Wallets.

### Features
- **One-time payments:** Seamless standard checkout for non-recurring products and invoices.
- **Subscriptions & Recurring Billing:** Automatically creates plans and subscriptions on Razorpay matching FOSSBilling billing cycles (Weekly, Monthly, Quarterly, Semi-Annually, Annually, etc.).
- **Automatic Renewal Processing:** Automatically records renewal payments, creates renewal invoices, and credits client accounts via Razorpay Webhooks (`subscription.charged`).
- **Subscription Lifecycle Synchronization:** Handles subscription activation, pauses, resumptions, and cancellations directly from the FOSSBilling admin panel and via Razorpay Webhooks.
- **Webhook Signature Verification:** HMAC-SHA256 signature verification for secure, tamper-proof webhook deliveries.

## Table of Contents

- [Installation](#installation)
- [Configuration](#configuration)
- [Setting Up Webhooks](#setting-up-webhooks)
- [Usage](#usage)
- [Contributing](#contributing)
- [License](#license)

## Installation

### Manual Installation

1. Download the latest release `.zip` from the [GitHub releases page](https://github.com/sahajananddigital/fossbilling-razorpay-payment-gateway/releases).
2. Create a folder named `Razorpay` inside `/library/Payment/Adapter` of your FOSSBilling installation (i.e. `/library/Payment/Adapter/Razorpay/`).
3. Extract the downloaded package into `/library/Payment/Adapter/Razorpay/`.
4. In your FOSSBilling admin panel, navigate to **System > Payment Gateways**.
5. Under the **New payment gateway** tab, find **Razorpay** and click the **cog icon** to install and configure it.

## Configuration

1. In the FOSSBilling admin panel, go to **System > Payment Gateways** and click **Razorpay**.
2. **API Credentials:**
   - **Live Key ID** & **Live Key Secret**: From your [Razorpay Dashboard](https://dashboard.razorpay.com/#/app/keys).
   - **Live Webhook Secret**: Enter a secret passphrase that you will also configure in your Razorpay Dashboard Webhook settings.
   - **Test Mode Keys**: If you want to test payments in Sandbox mode, provide your **Test Key ID**, **Test Key Secret**, and **Test Webhook Secret**, and check the **Test Mode** checkbox.
3. Save your changes.

## Setting Up Webhooks

To ensure automated recurring subscription charges and cancellations are synchronized in real-time, configure a webhook endpoint in your Razorpay Dashboard:

1. Log into your [Razorpay Dashboard](https://dashboard.razorpay.com/) and go to **Settings > Webhooks**.
2. Click **Add New Webhook**.
3. **Webhook URL**: Enter your FOSSBilling IPN callback URL:
   ```
   https://your-fossbilling-domain.com/ipn.php?gateway_id=<GATEWAY_ID>
   ```
   *(You can find your specific gateway callback URL listed in the Razorpay gateway settings in FOSSBilling admin).*
4. **Secret**: Enter the same passphrase you configured under **Webhook Secret** in FOSSBilling.
5. **Active Events**: Check the following events:
   - `subscription.authenticated`
   - `subscription.activated`
   - `subscription.charged`
   - `subscription.cancelled`
   - `subscription.paused`
   - `subscription.resumed`
   - `payment.captured`
   - `payment.failed`
6. Save the webhook.

## Usage

Once installed and enabled:
- **One-Time Invoices:** Clients are presented with a "Pay with Razorpay" button initiating a standard Razorpay checkout order.
- **Recurring Subscriptions:** When a client chooses Razorpay for a recurring order, Razorpay automatically sets up an authorized recurring mandate matching the billing cycle. Renewal charges billed by Razorpay automatically trigger renewal invoices and credit settlement in FOSSBilling.
- **Cancellations:** Cancelling an order or subscription in FOSSBilling cancels the mandate on Razorpay immediately or at the end of the current billing cycle.

## Contributing

We welcome contributions to enhance and improve this integration module:
1. Fork the repository.
2. Create your feature branch (`git checkout -b feature/my-feature`).
3. Commit your changes (`git commit -m 'Add subscription support'`).
4. Push to the branch (`git push origin feature/my-feature`).
5. Open a Pull Request.

## License

This FOSSBilling Razorpay Payment Gateway Integration module is open-source software licensed under the [Apache License 2.0](LICENSE).
