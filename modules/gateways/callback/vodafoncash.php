<?php
/**
 * VodafoneCash WHMCS Gateway Callback File
 */

// Require libraries needed for gateway module functions.
require_once __DIR__ . '/../../../init.php';
use WHMCS\Database\Capsule;
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

// Detect module name from filename.
$gatewayModuleName = basename(__FILE__, '.php');

// Fetch gateway configuration parameters.
$gatewayParams = getGatewayVariables($gatewayModuleName);

// Die if module is not active.
if (!$gatewayParams['type']) {
    die("Module Not Activated");
}

// Retrieve data returned inside actual request
$invoiceId = isset($_POST["invoiceId"]) ? $_POST["invoiceId"] : '';
$clientId = isset($_POST["clientId"]) ? $_POST["clientId"] : '';
$expectedAmount = isset($_POST["expectedAmount"]) ? $_POST["expectedAmount"] : '';
$phone = isset($_POST["wallet_phone"]) ? $_POST["wallet_phone"] : '';
$lang = isset($_POST["lang"]) ? $_POST["lang"] : 'en';

if (!$invoiceId || !$clientId || !$expectedAmount || !$phone) {
    die("Missing required paramaters.");
}

/**
 * Validate that the invoice belongs to the client and fetch the true amount
 */
$invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams['name']);
checkCbTransID($invoiceId); // Used later for logging

$invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->where('userid', $clientId)->first();
if (!$invoice) {
    die("Invoice not found or does not belong to this client.");
}

// Build remote API URL dynamically
$systemUrl = rtrim($gatewayParams['systemUrl'], '/');
$storeId = $gatewayParams['storeId'];

// The VodafoneCash backend checks if a transaction happened matching this phone and amount
$apiUrl = $systemUrl . "/api/payment_link_check?" . http_build_query([
    'phone' => trim($phone),
    'amount' => $invoice->total,
    'user_name' => $invoice->userid,
    'store_id' => $storeId,
    'lang' => $lang
]);

// Call external API via cURL
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
if (curl_errno($ch)) {
    $curlError = curl_error($ch);
}
curl_close($ch);

$responseData = json_decode($response, true);
$transactionStatus = 'Error';
$logMessage = "HTTP Code: $httpCode | Response: $response | Expected Amount: {$invoice->total} | Client ID: {$invoice->userid}";

if ($httpCode == 200 && $responseData && isset($responseData['status'])) {
    if ($responseData['status'] === true) {
        $transactionStatus = 'Success';
        logTransaction($gatewayParams['name'], $_POST + ['apiResponse' => $logMessage], $transactionStatus);
        
        $msg = $lang == 'ar' 
            ? "تم المعالجة بنجاح. سيتم إضافة الرصيد إلى حسابك وتحديث الفاتورة فوراً." 
            : "Processed safely. Credit will be added to your account momentarily.";
            
        // Render success page returning to invoice
        echo "<h3 style='color:green; text-align:center; margin-top:50px; font-family:sans-serif;'>{$msg}</h3>";
        echo "<div style='text-align:center;'><a href='" . $gatewayParams['systemurl'] . "viewinvoice.php?id=" . $invoiceId . "' style='padding: 10px 20px; background:#007bff; color:white; text-decoration:none; border-radius:4px;'>Return to Invoice</a></div>";
        exit;
        
    } else {
        $transactionStatus = 'Failure';
        logTransaction($gatewayParams['name'], $_POST + ['apiResponse' => $logMessage], $transactionStatus);
        
        $errorMsg = isset($responseData['message']) ? $responseData['message'] : "Payment missing or waiting for approval. Please check back later.";
        
        // Render error page returning to invoice
        echo "<h3 style='color:red; text-align:center; margin-top:50px; font-family:sans-serif;'>" . htmlspecialchars($errorMsg) . "</h3>";
        echo "<div style='text-align:center;'><a href='" . $gatewayParams['systemurl'] . "viewinvoice.php?id=" . $invoiceId . "' style='padding: 10px 20px; background:#007bff; color:white; text-decoration:none; border-radius:4px;'>Go Back</a></div>";
        exit;
    }
} else {
    logTransaction($gatewayParams['name'], $_POST + ['apiResponse' => $logMessage], $transactionStatus);
    die("<h3 style='color:red; text-align:center; margin-top:50px; font-family:sans-serif;'>Error communicating with the payment network. Please try again.</h3>
         <div style='text-align:center;'><a href='" . $gatewayParams['systemurl'] . "viewinvoice.php?id=" . $invoiceId . "' style='padding: 10px 20px; background:#007bff; color:white; text-decoration:none; border-radius:4px;'>Go Back</a></div>");
}
