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
        'wallet_amount' => 'اكتب المبلغ الذي قمت بتحويله ( بالجنيه المصري )',
        'wallet_amount_hint' => 'يجب إدخال المبلغ الذي قمت بتحويله بالضبط',
        'pay' => 'تأكيد الدفع عبر فودافون كاش',
        'phone_placeholder' => '01000000000'
    ] : [
        'wallet_phone' => 'Your Wallet Phone Number',
        'wallet_amount' => 'Amount you transferred (EGP)',
        'wallet_amount_hint' => 'Input exactly the amount you transferred',
        'pay' => 'Confirm VodafoneCash Payment',
        'phone_placeholder' => '01000000000'
    ];

    // Issue #1: If the user is on the checkout completion page (cart.php), WHMCS will auto-submit the first form it finds.
    // To prevent it from submitting an empty payment form, we redirect them to the invoice page.
    $isInvoicePage = strpos($_SERVER['SCRIPT_NAME'], 'viewinvoice.php') !== false;
    
    if (!$isInvoicePage) {
        return '<form action="viewinvoice.php" method="get">
                    <input type="hidden" name="id" value="' . htmlspecialchars($invoiceId) . '" />
                    <button type="submit" class="btn btn-primary" style="padding: 12px; font-weight: bold; font-size: 16px; background: #ff9c00; border: none; border-radius: 8px; color: #fff; cursor: pointer;">' . $langTrans['pay'] . '</button>
                </form>
                <script>
                    setTimeout(function() {
                        window.location.href = "viewinvoice.php?id=' . urlencode($invoiceId) . '";
                    }, 0);
                </script>';
    }

    $vfcUrl = rtrim($params['systemUrl'], '/');
    $vfcStoreId = $params['storeId'];

    $htmlOutput = '<div class="vfc-wrapper" style="max-width:400px; margin: 20px auto; padding: 25px; border: 1px solid #e2e8f0; border-radius: 12px; background-color: #ffffff; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); font-family: sans-serif; text-align: center;" dir="' . ($lang == 'ar' ? 'rtl' : 'ltr') . '">';
    
    // Logo
    $htmlOutput .= '<div style="margin-bottom: 20px;"><img src="https://storage.perfectcdn.com/zz3bz8/e5e5u46af2ete7z4.png" style="width: 180px; max-width: 100%;" /></div>';
    
    // Rate Display Block
    $htmlOutput .= '<div id="vfc-rate-container" style="display:none; margin-bottom: 20px; padding: 10px; background: #fffaf0; border: 1px solid #feebc8; border-radius: 8px;">';
    $htmlOutput .= '<div style="font-size: 0.85rem; color: #718096; margin-bottom: 4px;">' . ($lang == 'ar' ? 'سعر الشحن المباشر' : 'Live Exchange Rate') . '</div>';
    $htmlOutput .= '<div style="font-weight: bold; color: #ff9c00; font-size: 1.2rem;"><span id="vfc-rate-unit">1USDT</span> = <span id="vfc-rate-value">...</span> EGP</div>';
    $htmlOutput .= '</div>';

    $htmlOutput .= '<form method="post" action="' . htmlspecialchars($callbackUrl) . '">';
    
    // Phone Field
    $htmlOutput .= '<div class="form-group" style="margin-bottom: 15px; text-align: ' . ($lang == 'ar' ? 'right' : 'left') . ';">';
    $htmlOutput .= '<label for="wallet_phone" style="display: block; font-weight: bold; margin-bottom: 8px; color: #4a5568;">' . $langTrans['wallet_phone'] . '</label>';
    $htmlOutput .= '<input type="text" name="wallet_phone" id="wallet_phone" class="form-control" placeholder="' . $langTrans['phone_placeholder'] . '" required autofocus style="width: 100%; box-sizing: border-box; padding: 10px; border: 1px solid #cbd5e0; border-radius: 6px;" />';
    $htmlOutput .= '</div>';

    // Amount Field
    $htmlOutput .= '<div class="form-group" style="margin-bottom: 15px; text-align: ' . ($lang == 'ar' ? 'right' : 'left') . ';">';
    $htmlOutput .= '<label for="wallet_amount" style="display: block; font-weight: bold; margin-bottom: 8px; color: #4a5568;">' . $langTrans['wallet_amount'] . '</label>';
    $htmlOutput .= '<input type="text" name="wallet_amount" id="wallet_amount" class="form-control" value="' . htmlspecialchars($amount) . '" required style="width: 100%; box-sizing: border-box; padding: 10px; border: 1px solid #cbd5e0; border-radius: 6px;" />';
    $htmlOutput .= '<small style="display: block; color: #718096; margin-top: 5px;">' . $langTrans['wallet_amount_hint'] . '</small>';
    $htmlOutput .= '</div>';
    
    $htmlOutput .= '<input type="hidden" name="invoiceId" value="' . htmlspecialchars($invoiceId) . '" />';
    $htmlOutput .= '<input type="hidden" name="clientId" value="' . htmlspecialchars($clientId) . '" />';
    $htmlOutput .= '<input type="hidden" name="lang" value="' . htmlspecialchars($lang) . '" />';
    
    $htmlOutput .= '<button type="submit" class="btn btn-primary btn-block" style="width: 100%; padding: 12px; font-weight: bold; font-size: 16px; background: #ff9c00; border: none; border-radius: 8px; color: #fff; cursor: pointer;">' . $langTrans['pay'] . '</button>';
    $htmlOutput .= '</form>';

    // Inline JS for Rate Fetching
    $htmlOutput .= '<script>
        (function() {
            var vfcHost = "' . $vfcUrl . '";
            var vfcStore = "' . $vfcStoreId . '";
            var invoiceAmount = ' . (float)$amount . ';
            function updateVfcRate() {
                if (!vfcHost || !vfcStore) return;
                fetch(vfcHost + "/api/public/store/" + vfcStore + "/rate", { mode: "cors", credentials: "omit" })
                    .then(function(r) { return r.ok ? r.json() : null; })
                    .then(function(d) {
                        if (d && d.status && d.rate != null) {
                            var v = document.getElementById("vfc-rate-value");
                            var u = document.getElementById("vfc-rate-unit");
                            var c = document.getElementById("vfc-rate-container");
                            var n = parseFloat(d.rate);
                            if (v) {
                                v.textContent = isFinite(n) ? n.toFixed(2) : d.rate;
                            }
                            if (u && d.currency_label) {
                                var allowed = { USDT: true, USD: true, "عملة": true };
                                if (allowed[d.currency_label]) {
                                    u.textContent = d.currency_label === "عملة" ? "1 " + d.currency_label : "1" + d.currency_label;
                                }
                            }
                            if (c) c.style.display = "block";
                            
                            // Multiply invoice amount by rate and update the input
                            if (isFinite(n) && invoiceAmount > 0) {
                                var expectedEgp = Math.ceil(invoiceAmount * n); // Round up to nearest EGP
                                var amtInput = document.getElementById("wallet_amount");
                                // Only update if the input still has the original USD amount (so we don\'t overwrite user changes)
                                if (amtInput && parseFloat(amtInput.value) === invoiceAmount) {
                                    amtInput.value = expectedEgp;
                                }
                                
                                // Show a hint about the total
                                var hintEl = document.getElementById("wallet_amount_calc_hint");
                                if (!hintEl) {
                                    hintEl = document.createElement("div");
                                    hintEl.id = "wallet_amount_calc_hint";
                                    hintEl.style.color = "#009688";
                                    hintEl.style.fontSize = "0.85rem";
                                    hintEl.style.marginTop = "4px";
                                    amtInput.parentNode.insertBefore(hintEl, amtInput.nextSibling);
                                }
                                hintEl.textContent = "المبلغ المطلوب: " + invoiceAmount + " × " + n.toFixed(2) + " = " + expectedEgp + " جنيه";
                            }
                        }
                    })
                    .catch(function() {});
            }
            updateVfcRate();
        })();
    </script>';
    $htmlOutput .= '</div>';

    return $htmlOutput;
}
