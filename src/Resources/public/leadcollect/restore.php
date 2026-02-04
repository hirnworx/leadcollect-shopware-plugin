<?php
/**
 * LeadCollect Cart Restore
 * Adds products and applies coupon automatically
 */

function getDbConnection() {
    $rootDir = dirname(dirname(__DIR__));
    $envFile = file_exists($rootDir . '/.env.local') ? $rootDir . '/.env.local' : $rootDir . '/.env';
    $envContent = @file_get_contents($envFile);
    if (!$envContent) return null;
    if (preg_match('/DATABASE_URL=["\']?([^"\'\n]+)/', $envContent, $match)) {
        $dbUrl = $match[1];
        if (preg_match('/mysql:\/\/([^:]+):([^@]+)@([^\/]+)\/([^?]+)/', $dbUrl, $m)) {
            try { return new PDO('mysql:host='.$m[3].';dbname='.$m[4], $m[1], urldecode($m[2])); }
            catch (Exception $e) { return null; }
        }
    }
    return null;
}

function getProductUuids($pdo, $skus) {
    if (empty($skus)) return [];
    $placeholders = implode(',', array_fill(0, count($skus), '?'));
    $stmt = $pdo->prepare("SELECT LOWER(HEX(id)) as uuid, product_number as sku FROM product WHERE product_number IN ($placeholders)");
    $stmt->execute($skus);
    $result = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { $result[$row['sku']] = $row['uuid']; }
    return $result;
}

$skus = isset($_GET["sku"]) ? $_GET["sku"] : "";
$quantities = isset($_GET["q"]) ? $_GET["q"] : "";
$coupon = isset($_GET["c"]) ? $_GET["c"] : "";

$skuList = array_filter(array_map('trim', explode(",", $skus)));
$qtyList = array_filter(explode(",", $quantities));

if (empty($skuList)) { header("Location: /checkout/cart"); exit; }

$pdo = getDbConnection();
if (!$pdo) { die("Database connection error"); }

$uuidMap = getProductUuids($pdo, $skuList);
$formInputs = "";
$foundProducts = 0;

foreach ($skuList as $i => $sku) {
    if (!isset($uuidMap[$sku])) continue;
    $uuid = $uuidMap[$sku];
    $qty = isset($qtyList[$i]) ? max(1, (int)$qtyList[$i]) : 1;
    $formInputs .= '<input type="hidden" name="lineItems['.$uuid.'][id]" value="'.$uuid.'">';
    $formInputs .= '<input type="hidden" name="lineItems['.$uuid.'][type]" value="product">';
    $formInputs .= '<input type="hidden" name="lineItems['.$uuid.'][referencedId]" value="'.$uuid.'">';
    $formInputs .= '<input type="hidden" name="lineItems['.$uuid.'][quantity]" value="'.$qty.'">';
    $formInputs .= '<input type="hidden" name="lineItems['.$uuid.'][stackable]" value="1">';
    $formInputs .= '<input type="hidden" name="lineItems['.$uuid.'][removable]" value="1">';
    $foundProducts++;
}

if ($foundProducts === 0) { header("Location: /checkout/cart"); exit; }
$couponEsc = htmlspecialchars($coupon);
$hasCoupon = !empty($coupon);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Warenkorb wird geladen...</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #0a0a0f; min-height: 100vh; display: flex; align-items: center; justify-content: center; position: relative; overflow: hidden; }
        body::before { content: ''; position: absolute; top: -50%; left: -50%; width: 200%; height: 200%; background: radial-gradient(circle at 20% 80%, rgba(99, 102, 241, 0.15) 0%, transparent 50%), radial-gradient(circle at 80% 20%, rgba(168, 85, 247, 0.15) 0%, transparent 50%); animation: pulse 8s ease-in-out infinite; }
        @keyframes pulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.1); opacity: 0.8; } }
        .container { position: relative; z-index: 1; background: rgba(255, 255, 255, 0.03); backdrop-filter: blur(20px); border: 1px solid rgba(255, 255, 255, 0.1); padding: 48px 56px; border-radius: 24px; text-align: center; max-width: 440px; box-shadow: 0 20px 50px rgba(0,0,0,0.5); }
        .logo { width: 220px; height: auto; margin-bottom: 40px; }
        h2 { color: #fff; font-size: 22px; font-weight: 600; margin-bottom: 12px; }
        p { color: rgba(255, 255, 255, 0.6); font-size: 15px; margin-bottom: 32px; line-height: 1.6; }
        .coupon-badge { display: inline-block; background: linear-gradient(135deg, #6366f1, #a855f7); color: white; padding: 8px 20px; border-radius: 30px; font-size: 14px; font-weight: 600; margin-bottom: 24px; letter-spacing: 1px; }
        .loader-container { display: flex; justify-content: center; gap: 8px; margin-bottom: 16px; }
        .loader-dot { width: 12px; height: 12px; border-radius: 50%; background: linear-gradient(135deg, #6366f1, #a855f7); animation: bounce 1.4s ease-in-out infinite; }
        .loader-dot:nth-child(1) { animation-delay: 0s; }
        .loader-dot:nth-child(2) { animation-delay: 0.2s; }
        .loader-dot:nth-child(3) { animation-delay: 0.4s; }
        @keyframes bounce { 0%, 80%, 100% { transform: scale(0.6); opacity: 0.5; } 40% { transform: scale(1); opacity: 1; } }
        .progress-bar { width: 100%; height: 4px; background: rgba(255, 255, 255, 0.1); border-radius: 2px; overflow: hidden; margin-top: 24px; }
        .progress-fill { height: 100%; background: linear-gradient(90deg, #6366f1, #a855f7, #3b82f6); border-radius: 2px; animation: progress 2s ease-out forwards; }
        @keyframes progress { 0% { width: 0%; } 100% { width: 100%; } }
        .step { font-size: 13px; color: rgba(255,255,255,0.4); margin-top: 16px; }
        iframe { display: none; }
    </style>
</head>
<body>
    <div class="container">
        <svg class="logo" viewBox="0 0 666.73 187.96" xmlns="http://www.w3.org/2000/svg">
            <path fill="#fff" d="M4.95,42.92c-.33-.32-.64-1.68-.23-1.68h11.94c1.05,1.57,1.93,3.09,3.56,3.71,3.69,1.41,3.11-2.96,5.88-4.04,4.02-1.56,6.66,2.59,8.25,5.89,1.07,2.21,3.95,2.92,6.21,2.12-2.08-2.53-1.93-5.63.47-7.45,3.26-2.47,7.87-2.76,11.29-.27,2.54,1.86.69,7.76,5.14,7.92,2.19.08,4.58.19,6.8-.07,2.52-.29,5.12-1.29,7.5-.19,6.39,2.97,1.56,9.19,7.97,9.49,1.94.09,4.24-1.57,4.04-4.03s-7.77-5.67-2.72-12.1c1.85-2.36,5.5-2.6,7.9-1.21,2.82,1.63,3.94,4.8,2.93,7.93-.17.54.96,1.58,1.53,1.6,5.02.22,6.98.68,9.19-4.06.66-1.41,1.8-2.38,3.38-2.39l5.03-.03c.2-1.31.21-2.72.67-3.84,1.41-3.48,5.29-5.88,8.85-5.37,4.18.59,6.76,4.19,6.02,8.11.61.8,1.95,1.54,2.8,1.48,1.06-.07,2.18-.82,2.91-1.66l1.4-1.6h15.87c.23,0,.79.23.96.35.22.16-.03.9-.23,1.12l-2.57,2.84-11.62,11.94-10.73,11.06-10.35,10.71-10.9,11.26-16.34,16.64c-1.52,1.55-2.27,8.25-2.56,10.55l-.83,6.51-.63,4.04-.82,8.67c-.2,2.13-3.05,3.18-4.71,3.31s-5.11-.87-5.21-3.03c-.13-3.08.18-6.31-.12-9.45l-.8-8.32c-.37-3.83-.49-8.03-1.67-11.68l-11.34-11.31c-2.34-2.33-4.77-4.39-6.89-6.84-1.57-1.83-3.22-3.25-4.98-4.98L13.57,51.3l-8.62-8.38Z"/>
            <path fill="#fff" d="M214.43,180.26c-.32.82-1.09,1.54-1.98,1.77l-22.81-.18-18.91-.3-.06-61.79c0-3.8-.56-6.83.21-10.67l11.15-.24c1.05-.02,2.32.63,2.32,1.88v59.94s29.87.09,29.87.09l.23,9.5Z"/>
            <path fill="#fff" d="M665.98,172.32l.12,8.79c-5.96,1.32-12.07,2.4-17.7-.36-4.28-2.1-6.9-7.06-6.93-11.81l-.16-28.21-7.52-.19.02-8.66,7.49-.19c.82-2.75-.04-11.96.92-12.19l2.16-.52c2.98-.71,5.51-1.2,8.73-1.18l.11,13.92,12.96.07c.3,2.19,1.66,8.84-1.4,8.85l-11.55.03-.14,19.96-.05,4.53c-.03,2.5.38,4.83,1.87,6.66,3.55,2.09,6.29,2.56,11.08.5Z"/>
            <path fill="#fff" d="M418.91,172.39c1.17-.28,2.64-.73,3.39-.48l1.18,7.38c-3.53,2.57-8.91,3.17-13.19,3.33-7.12.26-14.08-1.28-19.5-5.95s-7.99-10.8-8.15-17.79c-.33-15.13,8.31-26.04,23.59-27.83,6.14-.72,11.94-.29,17.59,2.74l-2.28,8.14c-4.92-1.53-8.87-2.95-13.42-2.04-3.98.8-7.57,2.39-10,5.7-3.49,4.77-4.05,10.79-2.8,16.47s5.27,10.22,11.44,11.32c2.3.41,5.45.65,7.77.09l4.38-1.05Z"/>
            <path fill="#fff" d="M603.06,166.21c4.04,8.81,16.67,9.06,24.96,5.24.77,2.77,1.28,5.49,1.51,8.38-11.62,4.67-27.39,4.17-35.67-6.56-6.78-8.8-6.96-22.19-.58-31.45,1.43-2.07,3.39-3.81,5.19-5.35,7.72-6.61,23.03-7.45,32.08-2.65l-2.49,6.99c-1.03,2.91-5.44-3.15-15.78-.68-2.75.66-5.28,1.77-7.09,4.17-4.66,6.19-5.39,14.8-2.13,21.92Z"/>
            <rect fill="#fff" x="514.26" y="108.78" width="12.04" height="72.77"/>
            <path fill="#fff" d="M501.21,141.42v3.82s-.06,36.26-.06,36.26l-11.28.03v-72.6s10.88-.17,10.88-.17c1.58,1.52.53,3.76.52,5.51l-.06,27.15Z"/>
            <circle fill="#fff" cx="76.17" cy="11.55" r="6.88"/>
            <path fill="#fff" d="M373.73,109.75c0-.92-1.06-2-1.79-1.99l-10.96.15-.12,28.01c-4.09-4.07-8.41-5.24-13.53-5.29-9.84-.09-18.33,5.26-21.98,14.55-3,7.64-3.19,16.3.09,23.97,6.17,14.42,24.04,16.88,32.65,9.72,1.07-.89,1.93-1.51,3.15-2.34l.27,4.96,9.72.26c1.08.03,2.49.02,2.49-1.64v-70.35ZM358.2,168.98c-2.47,3.56-7.08,4.98-11.44,4.29-10.77-1.73-12.48-16.65-9.01-25.65,2.62-6.8,9.03-9.4,15.97-6.75,3.67,1.4,6.88,5.6,7.11,9.72.38,6.89.89,13.32-2.63,18.39Z"/>
            <path fill="#fff" d="M314.57,147.89c-.04-6.21-3.46-11.18-8.51-14.44-7.5-4.85-22.97-2.78-31.04,1.81.19,1.57,1.82,7.89,2.52,8.31,6.44-3.45,17.17-6.5,22.29-1.12,1.9,1.99,2.48,4.77,2.58,7.85l-7.56.41c-4.67.25-12.04,1.44-16.3,3.92-5.38,3.13-8.69,8.96-7.94,14.76,1.09,8.41,7.69,13.1,15.81,13.29,6.33.15,12.22-1.33,16.4-6.07,0,2.33.04,4.21,1.06,5.32,2.97.05,5.6-.07,8.44-.23l1.75-.1c.33-.02.67-1.17.67-1.71l-.18-32.02ZM295.45,173.72c-4.45,1.38-10.23.34-11.85-4.06-1.92-5.18,1.73-9.36,6.48-10.47,4.12-.96,7.99-1.22,12.34-1.13.31,6.2.35,13.39-6.97,15.65Z"/>
            <path fill="#fff" d="M265.02,149.39c-.85-5.75-3.43-10.75-7.91-14.47-8.52-7.07-25.98-5.39-33.68,4.72-5.62,7.38-7.12,16.55-4.9,25.58,2.41,9.81,10.26,15.71,19.98,17.07,6.41.9,18.37.17,23.86-3.38l-1.49-8.6c-6.78,2.92-12.88,3.93-19.3,2.85-7-1.18-11.83-6.63-11.45-13.6h33.26c3.01.01,1.92-8.2,1.62-10.17ZM253.31,150.83l-6.09.23c-5.55.21-11,.19-16.78.12.03-4.98,3.03-9.03,6.65-10.85,4.5-2.26,9.43-1.8,13.21,1.42,2.4,2.04,3.35,5.82,3.01,9.08Z"/>
            <path fill="#fff" d="M479.77,150.16c-.81-4.22-2.83-7.84-5.41-11.16-7.51-9.67-25.26-10.62-35.31-3.94-1.92,1.27-4.04,3.56-5.31,5.36-4.01,5.69-5.37,12.34-4.71,19.03.62,6.2,2.26,12.15,7.01,16.45,7.06,6.38,16.92,8.1,26.51,5.61,14.38-3.73,19.68-18.55,17.21-31.35ZM467.69,161.38c-.73,3.08-1.24,6.19-3.7,8.85-4.05,4.38-10.86,5.18-15.95,2.17-9.5-5.61-8.97-24.14-1.82-30.35,4.96-4.31,13.17-3.68,17.58.94,3.97,4.16,5.23,12.66,3.88,18.4Z"/>
            <path fill="#fff" d="M548.21,159.36c.31-.03.93-.02,1.5-.03l4.1-.11,28.69-.15c1.24-7.84-1.84-19.04-7.8-23.83-6.12-4.91-14.27-5.44-21.8-3.18-16.38,4.92-21.52,25.9-13.34,39.85,7.48,12.75,27.93,12.47,40.22,7.29l-1.21-8.03c-1.07.18-2.21.39-3.1.76-6.33,2.64-19.55,3.32-24.54-2.9-3.16-3.94-4.16-9.54-2.72-9.67ZM551.8,142.2c2.16-2.4,7.61-4.27,11.78-3.03,5.83,1.73,7.58,6.08,7.73,12.02l-16.82.16c-2.33.02-4.33.72-6.78.08.48-3.5,1.72-6.6,4.09-9.23Z"/>
            <path fill="#fff" d="M139.53,117.09c-.02-4.55-5.62-9.04-9.81-9.06l-28.48-.14c-2.15-.01-3.54,2.15-3.88,3.9-.33,1.69.7,4.53,2.92,4.66,4.55.26,9.13.25,13.71,0,2.39-.13,4.49-.2,7.1.07,1.09.12,2.08-.41,3.02.17-5.55,3.61-10.25,7.23-15.37,10.96l-15.14,11.01-14.25,10.07c-.37.26-1.35.62-1.73.48-.32-.12-.76-.55-1.16-.83l-7.23-5.1-11.46-8.54-24.29-17.71,22.86-.12c2.06-.01,3.35-1.88,3.26-4-.33-1.87-1.6-4.67-3.71-5.22l-27.88-.03c-.99,0-2.64.75-3.61,1.1-4.82,1.72-6.95,6.17-6.93,11.17l.14,56.88c0,2.74,1.54,5.22,3.25,7.29,1.92,2.33,5.35,3.87,8.73,3.87h98.79c4.04,0,7.77-2.64,9.45-5.72,1.06-1.94,1.94-3.69,1.93-5.86l-.21-59.28ZM26.18,173.53l-.04-50.31c2.41,1.04,4.33,2.61,6.4,4.04l9.01,6.23,7.26,5.07,8.1,5.87,4.77,3.37-18.67,13.91c-5.49,4.09-10.8,7.79-16.82,11.81ZM107.48,157.28l-8.68-5.95-4.98,2.11c1.72,1.82,3.66,3.83,5.76,5.51l8.65,6.91c1.17.94,2.26,1.84,3.42,2.81l6.68,5.61c2.12,1.78,4.61,3.06,6.41,5.47l-86.05-.1c-1.84,0-3.27-.22-5.07-.41l5.53-4.77,16.24-13.44,3.28-2.9,3.08-2.74c1.66-1.48,4.41-4.14,6.03-2.85,4.35,3.45,10.33,7,15.7,3.47,3.47-2.28,6.69-4.84,10.02-7.29l3.39-2.5,20.66-14.82,13.87-9.74.04,52.55-23.96-16.92Z"/>
        </svg>
        
        <h2>Willkommen zurück!</h2>
        <p>Wir stellen Ihren Warenkorb wieder her<?php if($hasCoupon): ?><br>und lösen Ihren Gutschein ein<?php endif; ?>...</p>
        
        <?php if ($hasCoupon): ?>
        <div class="coupon-badge"><?= $couponEsc ?></div>
        <?php endif; ?>
        
        <div class="loader-container">
            <div class="loader-dot"></div>
            <div class="loader-dot"></div>
            <div class="loader-dot"></div>
        </div>
        
        <div class="progress-bar"><div class="progress-fill"></div></div>
        <div class="step" id="stepText">Produkte werden geladen...</div>
        
        <!-- Hidden iframe for product form -->
        <iframe name="productFrame" id="productFrame"></iframe>
        
        <!-- Product form -->
        <form id="productForm" action="/checkout/line-item/add" method="POST" target="productFrame">
            <?= $formInputs ?>
            <input type="hidden" name="redirectTo" value="frontend.checkout.cart.page">
        </form>
        
        <?php if ($hasCoupon): ?>
        <!-- Coupon form -->
        <form id="couponForm" action="/checkout/promotion/add" method="POST">
            <input type="hidden" name="code" value="<?= $couponEsc ?>">
            <input type="hidden" name="redirectTo" value="frontend.checkout.cart.page">
        </form>
        <?php endif; ?>
    </div>
    
    <script>
        var hasCoupon = <?= $hasCoupon ? 'true' : 'false' ?>;
        
        // Step 1: Submit product form
        setTimeout(function() {
            document.getElementById('productForm').submit();
        }, 800);
        
        // Step 2: After products loaded, submit coupon or redirect
        document.getElementById('productFrame').onload = function() {
            if (hasCoupon) {
                document.getElementById('stepText').textContent = 'Gutschein wird eingelöst...';
                setTimeout(function() {
                    document.getElementById('couponForm').submit();
                }, 500);
            } else {
                document.getElementById('stepText').textContent = 'Weiterleitung...';
                setTimeout(function() {
                    window.location.href = '/checkout/cart';
                }, 500);
            }
        };
    </script>
</body>
</html>
