# VodafoneCash WHMCS Gateway Module

An official WHMCS payment gateway module for the VodafoneCash platform.

## Features
- Provides a simple "Wallet Phone Number" form directly in the WHMCS invoice view.
- Submits securely to your backend so your Store ID is never exposed to the client.
- Automatically handles both Arabic and English localizations.
- Supports Anti-Spam (waiting for approval) flows.

## Installation

1. Download the latest `.zip` from the [Releases page](https://github.com/ibrahim317/vodafoncash-whmcs-module/releases).
2. Extract the contents of the ZIP file directly into the **root** folder of your WHMCS installation. (This will place files into `modules/gateways/` and `modules/gateways/callback/`).
3. Log in to your WHMCS Admin Area.
4. Go to **Setup** (or the wrench icon) → **System Settings** → **Payment Gateways**.
5. Click on the **All Payment Gateways** tab.
6. Click **VodafoneCash** to activate it.
7. Configure the settings:
   - **System URL**: Your hosted tenant URL (e.g., `https://vodafoncash.com`).
   - **Store ID**: The ID of your store as found in your VodafoneCash dashboard.
8. Make sure to tick the checkbox to show the gateway on the order form if you want users to select it during checkout.

## How it works (Automated Credit)
When a user pays an invoice, they input their VodafoneCash wallet number on the invoice page. The module verifies the transaction via the `GET /api/payment_link_check` endpoint using your hidden Store ID.

Simultaneously, the main VodafoneCash server pushes an `AddCredit` REST API call securely back to your WHMCS installation. This credits the user's account and instantly pays their outstanding invoices automatically!
