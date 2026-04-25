<?php
/**
 * VodafoneCash WHMCS Gateway Module
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/**
 * Define module related metadata
 */
function vodafoncash_MetaData()
{
    return array(
        'DisplayName' => 'VodafoneCash',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    );
}

/**
 * Define gateway configuration options
 */
function vodafoncash_config()
{
    return array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'VodafoneCash',
        ),
        'systemUrl' => array(
            'FriendlyName' => 'VodafoneCash System URL',
            'Type' => 'text',
            'Size' => '50',
            'Default' => 'https://vodafoncash.com',
            'Description' => 'Enter the base URL of your VodafoneCash system (e.g. https://vodafoncash.com)',
        ),
        'storeId' => array(
            'FriendlyName' => 'Store ID',
            'Type' => 'text',
            'Size' => '20',
            'Default' => '',
            'Description' => 'Enter your VodafoneCash Store ID (Found in your stores dashboard)',
        ),
    );
}

/**
 * Payment link generation
 */
function vodafoncash_link($params)
{
    // Gateway Configuration Parameters
    $storeId = $params['storeId'];
    
    // Invoice Parameters
    $invoiceId = $params['invoiceid'];
    $amount = $params['amount'];
    
    // Client Parameters
    $clientId = $params['clientdetails']['id'];
    $lang = strtolower($params['clientdetails']['language']) == 'arabic' ? 'ar' : 'en';

    // System Parameters
    $moduleName = $params['paymentmethod'];
    
    // Generate the callback URL securely
    $callbackUrl = $params['systemurl'] . 'modules/gateways/callback/' . $moduleName . '.php';

    // Basic localization strings
    $langTrans = $lang == 'ar' ? [
        'wallet_phone' => 'رقم المحفظة (الرقم المُحوّل منه)',
        'pay' => 'تأكيد الدفع عبر فودافون كاش',
        'phone_placeholder' => '01000000000'
    ] : [
        'wallet_phone' => 'Your Wallet Phone Number',
        'pay' => 'Confirm VodafoneCash Payment',
        'phone_placeholder' => '01000000000'
    ];

    // The HTML output will be rendered on the actual WHMCS invoice page.
    $htmlOutput = '<form method="post" action="' . htmlspecialchars($callbackUrl) . '" style="max-width:320px; margin: 15px auto; padding: 15px; border: 1px solid #ddd; border-radius: 6px; background-color: #f9f9f9; text-align: left;" dir="' . ($lang == 'ar' ? 'rtl' : 'ltr') . '">';
    $htmlOutput .= '<div class="form-group" style="margin-bottom: 15px;">';
    $htmlOutput .= '<label for="wallet_phone" style="display: block; font-weight: bold; margin-bottom: 5px;">' . $langTrans['wallet_phone'] . '</label>';
    $htmlOutput .= '<input type="text" name="wallet_phone" id="wallet_phone" class="form-control" placeholder="' . $langTrans['phone_placeholder'] . '" required autofocus style="width: 100%; box-sizing: border-box;" />';
    $htmlOutput .= '</div>';
    
    // We pass the parameters silently so the user cannot manipulate the Store ID. 
    // Wait, the client CAN manipulate invoiceId and amount here, but the callback file MUST verify the invoice amount matches in DB.
    $htmlOutput .= '<input type="hidden" name="invoiceId" value="' . htmlspecialchars($invoiceId) . '" />';
    $htmlOutput .= '<input type="hidden" name="expectedAmount" value="' . htmlspecialchars($amount) . '" />';
    $htmlOutput .= '<input type="hidden" name="clientId" value="' . htmlspecialchars($clientId) . '" />';
    $htmlOutput .= '<input type="hidden" name="lang" value="' . htmlspecialchars($lang) . '" />';
    
    $htmlOutput .= '<button type="submit" class="btn btn-primary btn-block" style="width: 100%; padding: 10px; font-weight: bold; font-size: 16px;">' . $langTrans['pay'] . '</button>';
    $htmlOutput .= '</form>';

    return $htmlOutput;
}
