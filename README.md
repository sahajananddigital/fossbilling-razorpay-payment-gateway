<div align="center">
  <img src="" alt="Razorpay for FOSSBilling">
  <h1>Razorpay Integration for FOSSBilling</h1>
  <p>An open-source payment gateway to accept payments with Razorpay on your FOSSBilling installation.</p>
  
  <p>
    <a href="https://github.com/sahajananddigital/fossbilling-razorpay-payment-gateway/releases/latest"><img src="https://img.shields.io/github/v/release/sahajananddigital/fossbilling-razorpay-payment-gateway" alt="GitHub release (latest by date)"></a>
    <img src="https://img.shields.io/github/downloads/sahajananddigital/fossbilling-razorpay-payment-gateway/total" alt="GitHub all releases">
    <img src="https://img.shields.io/github/repo-size/sahajananddigital/fossbilling-razorpay-payment-gateway" alt="GitHub repo size">
    <a href="https://github.com/sahajananddigital/fossbilling-razorpay-payment-gateway/blob/main/LICENSE"><img alt="License" src=""></a>
  </p>
</div>

---

## Overview

This extension allows you to integrate the popular [Razorpay](https://razorpay.com) payment gateway with your [FOSSBilling](https://fossbilling.org) installation. Provide your customers with a variety of payment options, including Credit/Debit cards, Netbanking, UPI, Wallets, and more.

> **Warning**
> This extension, like FOSSBilling itself, is under active development and should be considered beta software. It may have stability or security issues and is not yet recommended for use in active production environments.

## Table of Contents

- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
- [Contributing](#contributing)
- [License](#license)

## Installation

### 1. FOSSBilling Extension Directory

> **Note:** This installation method is not yet implemented. Please follow the manual installation steps below.

### 2. Manual Installation

1.  Download the latest release from the [GitHub releases page].
2.  Create a new folder named `Razorpay` inside the `/library/Payment/Adapter` directory of your FOSSBilling installation.
3.  Extract the downloaded archive into the newly created `Razorpay` folder. The folder structure should look like `.../library/Payment/Adapter/Razorpay/Razorpay.php`.
4.  Navigate to the **Payment gateways** page in your FOSSBilling admin panel (under the "System" menu in the navigation bar).
5.  Find "Razorpay" in the **New payment gateway** tab and click the **cog icon** next to it to install and configure the gateway.

## Configuration

1.  **Access Settings:** In your FOSSBilling admin panel, navigate to the **Payment gateways** section and click on **Razorpay**.
2.  **Enter API Credentials:** Provide your Razorpay `API Key Id` and `API Secret`. You can obtain these from your [Razorpay Dashboard](https://dashboard.razorpay.com/#/app/keys).
3.  **Configure Preferences:** Customize settings such as currency and enabled payment methods as needed.
4.  **Save Changes:** Click "Update" or "Save" to apply your configuration.
5.  **Test Transactions (Optional):** Before going live, we highly recommend performing test transactions to ensure the integration is working correctly.
6.  **Go Live:** Switch the gateway to live mode to begin accepting real payments from your customers.

## Usage

Once you have successfully installed and configured the module, Razorpay will appear as an available payment option for your customers during the checkout process. The options displayed will be based on the currency and payment methods you have configured in the settings.

## Contributing

We welcome contributions to enhance and improve this integration module. If you'd like to contribute, please follow these steps:

1.  Fork the repository.
2.  Create a new branch for your feature or bug fix: `git checkout -b feature-name`.
3.  Make your changes and commit them with a clear and concise commit message.
4.  Push your branch to your fork: `git push origin feature-name`.
5.  Create a [pull request](https://github.com/sahajananddigital/fossbilling-razorpay-payment-gateway/pulls).

## License

This FOSSBilling Razorpay Payment Gateway Integration module is open-source software licensed under the [Apache License 2.0](LICENSE).

> **Note:** This module is not officially affiliated with [FOSSBilling](https://fossbilling.org) or [Razorpay](https://razorpay.com). Please refer to their respective documentation for detailed information.

For support or inquiries, feel free to contact Us.

## Credit:
https://github.com/albinvar/Razorpay-FOSSBilling