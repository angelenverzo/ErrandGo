<?php
session_start();
require_once 'config.php';
$page_title = 'ErrandGo | One App, Every Errand';
$descriptions = [
    'Food Delivery'            => ['🍔', 'Get meals from restaurants and food providers delivered to you.'],
    'Grocery Shopping'         => ['🛒', 'A worker buys your groceries and brings them to your door.'],
    'Parcel/Document Delivery' => ['📦', 'Send packages, forms, or signed documents between locations.'],
    'Office-Supply Purchasing' => ['🖇️', 'Paper, pens, folders, printer supplies bought and delivered to your office.'],
    'Moving Assistance'        => ['🚚', 'Help moving boxes or small furniture using a suitable vehicle.'],
    'Other Errands'            => ['📝', 'Pick-ups, purchases, and other tasks within platform policy.'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($page_title) ?></title>
    <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/theme.css">
</head>
<body>
<?php include 'partials/header.php'; ?>
<main>
    <section class="hero">
        <div class="logo-card"><img class="hero-logo" src="<?= file_exists('assets/logo.png') ? 'assets/logo.png' : 'assets/logo.svg' ?>" alt="ErrandGo logo"></div>
        <h1>Welcome to ErrandGo</h1>
        <p class="tags">Request &bull; Match &bull; Track &bull; Done</p>
        <p>ErrandGo connects you with verified riders and service workers for deliveries, shopping, and personal or business errands, all in one request platform.</p>
        <a href="register.php" class="button primary">Request an Errand</a>
        <a href="worker_register.php" class="button secondary">Become a Worker</a>
    </section>

    <section class="section" id="services">
        <h2>How ErrandGo Can Help</h2>
        <p class="lead">Six services, one request form.</p>
        <div class="service-grid">
            <?php foreach (SERVICES as $s): ?>
                <div class="service-card">
                    <div class="ico" aria-hidden="true"><?= $descriptions[$s][0] ?></div>
                    <h3><?= e($s) ?></h3>
                    <p><?= e($descriptions[$s][1]) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="section tint">
        <h2>How It Works</h2>
        <div class="steps">
            <div class="step"><div class="num">1</div><h3>Submit</h3><p>Choose a service, describe the task, and set pickup, drop-off, date and time.</p></div>
            <div class="step"><div class="num">2</div><h3>Get Matched</h3><p>An eligible, verified worker accepts the errand that fits their skills and vehicle.</p></div>
            <div class="step"><div class="num">3</div><h3>Track</h3><p>Follow the status online: Pending, Accepted, In Progress, Completed.</p></div>
            <div class="step"><div class="num">4</div><h3>Rate</h3><p>Rate your worker so quality stays high for everyone.</p></div>
        </div>
    </section>

    <section class="section">
        <h2>Built Around What People Told Us</h2>
        <p class="small">From our market survey of 58 respondents (stated interest, not guaranteed demand).</p>
        <div class="stats">
            <div class="stat"><strong>84.5%</strong><span>would use one platform for several errands</span></div>
            <div class="stat"><strong>86.2%</strong><span>chose parcel/document delivery</span></div>
            <div class="stat"><strong>86.2%</strong><span>trust workers who are verified</span></div>
            <div class="stat"><strong>82.8%</strong><span>rank speed as a top priority</span></div>
        </div>
        <p style="margin-top:22px">Verified workers &middot; Online status tracking &middot; Ratings &middot; Clear, all-in pricing information</p>
    </section>
</main>
<?php include 'partials/footer.php'; ?>
</body>
</html>
