<?php
/**
 * Cancel return page - customer cancelled the payment
 */

$config = require __DIR__ . '/config.php';
$invoiceId = isset($_GET['invoice_id']) ? (int) $_GET['invoice_id'] : 0;
$communityUrl = rtrim($config['ips']['base_url'], '/');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Cancelled</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; background: #f5f5f5; }
        .card { background: white; border-radius: 12px; padding: 40px; text-align: center; box-shadow: 0 2px 10px rgba(0,0,0,0.1); max-width: 400px; }
        .icon { font-size: 64px; margin-bottom: 16px; }
        h1 { color: #f59e0b; margin: 0 0 12px; font-size: 24px; }
        p { color: #666; margin: 0 0 24px; }
        a { display: inline-block; background: #3b82f6; color: white; padding: 12px 32px; border-radius: 8px; text-decoration: none; font-weight: 600; }
        a:hover { background: #2563eb; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">&#8635;</div>
        <h1>Payment Cancelled</h1>
        <p>You cancelled the payment. No charges were made. You can try again anytime.</p>
        <?php if ($communityUrl): ?>
            <a href="<?php echo htmlspecialchars($communityUrl); ?>">Return to Community</a>
        <?php endif; ?>
    </div>
</body>
</html>
