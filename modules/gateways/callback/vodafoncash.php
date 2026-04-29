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

// Normalize amount: remove any currency symbols, commas, spaces - keep digits and decimal point only
$normalizedAmount = preg_replace('/[^0-9.]/', '', trim($amount));
// Cast to float to safely remove trailing decimal zeros, then back to string (e.g. "100.00" -> "100", "20" -> "20")
$normalizedAmount = (string)(float)$normalizedAmount;

// Fetch client details for identifiable username
$client = Capsule::table('tblclients')->where('id', $clientId)->first();
$clientNameStr = $client ? trim($client->firstname . ' ' . $client->lastname . ' (' . $clientId . ')') : (string)$clientId;

// The VodafoneCash backend checks if a transaction happened matching this phone and amount
$apiUrl = $systemUrl . "/api/payment_link_check?" . http_build_query([
    'phone'     => trim($phone),
    'amount'    => $normalizedAmount,
    'user_name' => $clientNameStr,
    'store_id'  => (string)$storeId,
    'lang'      => $lang,
    'client_id' => (string)$clientId
]);

// Call external API via cURL
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Safety for diverse server environments
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);  // Follow redirects (e.g. http->https)
curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
if (curl_errno($ch)) {
    $curlError = curl_error($ch);
}
curl_close($ch);

$responseData = json_decode($response, true);
$transactionStatus = 'Error';
$logMessage = "HTTP Code: $httpCode | Final URL: $finalUrl | Normalized Amount: $normalizedAmount | Raw Amount: $amount | Response: $response | Expected Total: {$invoice->total} | Client ID: {$invoice->userid} | Store ID: $storeId" . (isset($curlError) ? " | cURL Error: $curlError" : "");

if ($httpCode == 200 && $responseData && isset($responseData['status'])) {
    if ($responseData['status'] === true) {
        $transactionStatus = 'Success';
        
        // Use transaction ID from API if available, otherwise use a generic one
        $transId = isset($responseData['transaction_id']) ? $responseData['transaction_id'] : 'VFC-' . time();
        
        // Track all results for logging
        $debugResults = [];
        
        // Step 1: Try to apply existing credit balance to the invoice.
        // The VodafoneCash backend has already called WHMCS AddCredit to top up
        // the client's credit balance, so ApplyCredit should use that.
        $applyCreditResult = localAPI('ApplyCredit', [
            'invoiceid' => $invoiceId,
            'amount' => 'full',
        ]);
        $debugResults['applyCredit'] = $applyCreditResult;
        
        // Step 2: Check if the invoice is now Paid after applying credit.
        $updatedInvoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
        $debugResults['invoiceStatusAfterCredit'] = $updatedInvoice ? $updatedInvoice->status : 'NOT_FOUND';
        
        // Step 3: If invoice is still not Paid, fall back to addInvoicePayment.
        // This covers cases where ApplyCredit silently fails (timing, insufficient credit, etc.)
        if (!$updatedInvoice || $updatedInvoice->status !== 'Paid') {
            // Determine the payment amount — use the invoice total so it gets fully paid
            $payAmount = $updatedInvoice ? $updatedInvoice->total : $normalizedAmount;
            
            $addPaymentResult = localAPI('AddInvoicePayment', [
                'invoiceid' => $invoiceId,
                'transid' => $transId,
                'amount' => $payAmount,
                'gateway' => $gatewayModuleName,
            ]);
            $debugResults['addInvoicePayment'] = $addPaymentResult;
            
            // Re-check invoice status after addInvoicePayment
            $updatedInvoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
            $debugResults['invoiceStatusAfterPayment'] = $updatedInvoice ? $updatedInvoice->status : 'NOT_FOUND';
        }
        
        // Step 4: Auto-complete order if invoice is now fully paid
        if ($updatedInvoice && $updatedInvoice->status === 'Paid') {
            $order = Capsule::table('tblorders')->where('invoiceid', $invoiceId)->first();
            $debugResults['orderFound'] = $order ? ['id' => $order->id, 'status' => $order->status] : 'NO_ORDER';
            if ($order && $order->status === 'Pending') {
                $acceptOrderResult = localAPI('AcceptOrder', [
                    'orderid' => $order->id,
                    'autosetup' => true,
                    'sendemail' => true,
                ]);
                $debugResults['acceptOrder'] = $acceptOrderResult;
            }
        } else {
            $debugResults['warning'] = 'Invoice still not Paid after all attempts. Status: ' . ($updatedInvoice ? $updatedInvoice->status : 'NOT_FOUND');
        }
        
        logTransaction($gatewayParams['name'], $_POST + ['apiResponse' => $logMessage, 'debugResults' => $debugResults], $transactionStatus);
        
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

    // Extract a meaningful error message from the API response if available
    $apiErrorMsg = '';
    if ($responseData && isset($responseData['message'])) {
        $apiErrorMsg = htmlspecialchars($responseData['message']);
    } elseif (isset($curlError)) {
        $apiErrorMsg = 'Network error: ' . htmlspecialchars($curlError);
    } elseif ($httpCode > 0) {
        $apiErrorMsg = 'Server returned HTTP ' . $httpCode . '.';
    } else {
        $apiErrorMsg = 'Could not reach the payment server.';
    }

    die("<div style='max-width:500px; margin: 50px auto; padding: 30px; border: 1px solid #feb2b2; background: #fff5f5; border-radius: 12px; text-align:center; font-family:sans-serif;'>
         <h3 style='color:#c53030; margin-top:0;'>Payment verification failed</h3>
         <p style='color:#4a5568;'>" . $apiErrorMsg . "</p>
         <p style='color:#718096; font-size:0.85rem;'>Host: " . parse_url($apiUrl, PHP_URL_HOST) . "</p>
         <div style='margin-top:20px;'><a href='" . $whmcsSystemUrl . "viewinvoice.php?id=" . $invoiceId . "' style='padding: 12px 24px; background:#007bff; color:white; text-decoration:none; border-radius:6px; font-weight:bold;'>Go Back</a></div>
         </div>");
}
