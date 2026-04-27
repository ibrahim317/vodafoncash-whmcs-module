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

// Ensure we have the system URL for redirection
$whmcsSystemUrl = isset($gatewayParams['systemurl']) ? $gatewayParams['systemurl'] : (isset($params['systemurl']) ? $params['systemurl'] : '');
if (!$whmcsSystemUrl && isset($_SERVER['HTTP_HOST'])) {
    $whmcsSystemUrl = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . '/';
}

// Retrieve data returned inside actual request
$invoiceId = isset($_POST["invoiceId"]) ? $_POST["invoiceId"] : '';
$clientId = isset($_POST["clientId"]) ? $_POST["clientId"] : '';
$phone = isset($_POST["wallet_phone"]) ? $_POST["wallet_phone"] : '';
$amount = isset($_POST["wallet_amount"]) ? $_POST["wallet_amount"] : '';
$lang = isset($_POST["lang"]) ? $_POST["lang"] : 'en';

if (!$invoiceId || !$clientId || !$amount || !$phone) {
    die("Missing required parameters.");
}

/**
 * Validate that the invoice belongs to the client
 */
$invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams['name']);
checkCbTransID($invoiceId); // Used later for logging

$invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->where('userid', $clientId)->first();
if (!$invoice) {
    die("Invoice not found or does not belong to this client.");
}

// Build remote API URL dynamically - Handle potential naming inconsistencies in gateway variables
$vfcapi_base = isset($gatewayParams['systemUrl']) ? $gatewayParams['systemUrl'] : (isset($gatewayParams['systemurl']) ? $gatewayParams['systemurl'] : 'https://vodafoncash.com');
$systemUrl = rtrim($vfcapi_base, '/');
$storeId = isset($gatewayParams['storeId']) ? $gatewayParams['storeId'] : (isset($gatewayParams['storeid']) ? $gatewayParams['storeid'] : '');

// The VodafoneCash backend checks if a transaction happened matching this phone and amount
$apiUrl = $systemUrl . "/api/payment_link_check?" . http_build_query([
    'phone' => trim($phone),
    'amount' => trim($amount),
    'user_name' => $invoice->userid,
    'store_id' => $storeId,
    'lang' => $lang
]);

// Call external API via cURL
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Safety for diverse server environments
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
if (curl_errno($ch)) {
    $curlError = curl_error($ch);
}
curl_close($ch);

$responseData = json_decode($response, true);
$transactionStatus = 'Error';
$logMessage = "HTTP Code: $httpCode | Response: $response | User Amount: $amount | Expected Total: {$invoice->total} | Client ID: {$invoice->userid}" . (isset($curlError) ? " | cURL Error: $curlError" : "");

if ($httpCode == 200 && $responseData && isset($responseData['status'])) {
    if ($responseData['status'] === true) {
        $transactionStatus = 'Success';
        
        // Use transaction ID from API if available, otherwise use a generic one
        $transId = isset($responseData['transaction_id']) ? $responseData['transaction_id'] : 'VFC-' . time();
        
        // Add payment to WHMCS invoice (automatically handles partial vs full payment)
        addInvoicePayment(
            $invoiceId,
            $transId,
            $amount,
            0, // Fee
            $gatewayModuleName
        );
        
        logTransaction($gatewayParams['name'], $_POST + ['apiResponse' => $logMessage], $transactionStatus);
        
        $msg = $lang == 'ar' 
            ? "تم المعالجة بنجاح. سيتم تحديث الفاتورة فوراً." 
            : "Processed safely. Your invoice will be updated momentarily.";
            
        // Render success page returning to invoice
        echo "<div style='max-width:500px; margin: 50px auto; padding: 30px; border: 1px solid #c6f6d5; background: #f0fff4; border-radius: 12px; text-align:center; font-family:sans-serif;'>";
        echo "<h3 style='color:#2f855a; margin-top:0;'>{$msg}</h3>";
        echo "<div style='margin-top:20px;'><a href='" . $whmcsSystemUrl . "viewinvoice.php?id=" . $invoiceId . "' style='padding: 12px 24px; background:#2f855a; color:white; text-decoration:none; border-radius:6px; font-weight:bold;'>Return to Invoice</a></div>";
        echo "</div>";
        exit;
        
    } else {
        $transactionStatus = 'Failure';
        logTransaction($gatewayParams['name'], $_POST + ['apiResponse' => $logMessage], $transactionStatus);
        
        $errorMsg = isset($responseData['message']) ? $responseData['message'] : "Payment missing or waiting for approval. Please check back later.";
        
        // Render error page returning to invoice
        echo "<div style='max-width:500px; margin: 50px auto; padding: 30px; border: 1px solid #feb2b2; background: #fff5f5; border-radius: 12px; text-align:center; font-family:sans-serif;'>";
        echo "<h3 style='color:#c53030; margin-top:0;'>" . htmlspecialchars($errorMsg) . "</h3>";
        echo "<div style='margin-top:20px;'><a href='" . $whmcsSystemUrl . "viewinvoice.php?id=" . $invoiceId . "' style='padding: 12px 24px; background:#c53030; color:white; text-decoration:none; border-radius:6px; font-weight:bold;'>Go Back</a></div>";
        echo "</div>";
        exit;
    }
} else {
    logTransaction($gatewayParams['name'], $_POST + ['apiResponse' => $logMessage], $transactionStatus);
    die("<div style='max-width:500px; margin: 50px auto; padding: 30px; border: 1px solid #feb2b2; background: #fff5f5; border-radius: 12px; text-align:center; font-family:sans-serif;'>
         <h3 style='color:#c53030; margin-top:0;'>Error communicating with the payment network. Please try again.</h3>
         <p style='color:#718096; font-size:0.9rem;'>Host: " . parse_url($apiUrl, PHP_URL_HOST) . "</p>
         <div style='margin-top:20px;'><a href='" . $whmcsSystemUrl . "viewinvoice.php?id=" . $invoiceId . "' style='padding: 12px 24px; background:#007bff; color:white; text-decoration:none; border-radius:6px; font-weight:bold;'>Go Back</a></div>
         </div>");
}
