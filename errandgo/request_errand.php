<?php
session_start();
require_once 'db.php';
require_once 'config.php';
require_once 'fees.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'customer') {
    header('Location: login.php');
    exit;
}
$customerName = $_SESSION['user_name'];
$pricing = get_pricing($pdo);
$tiers = get_weight_tiers($pdo);
$extraRiderFee = get_extra_rider_fee($pdo);
$err = $_SESSION['request_error'] ?? '';
unset($_SESSION['request_error']);

$jsData = [
    'rates' => $pricing,
    'tiers' => array_map(fn($t) => ['label' => $t['label'], 'max' => (float) $t['max_kg'], 'fee' => (float) $t['fee']], $tiers),
    'dist' => distance_matrix(),
    'extraRiderFee' => $extraRiderFee,
    'maxErrands' => MAX_ERRANDS_PER_BOOKING,
    'maxRiders' => MAX_RIDERS,
    'maxItems' => MAX_ITEMS_PER_ERRAND,
    'maxKg' => MAX_WEIGHT_KG,
    'bulkKg' => BULK_WEIGHT_KG,
    'bulkItems' => BULK_ITEM_COUNT,
    'bulkServices' => BULK_SERVICES,
    'noDropoff' => NO_DROPOFF_SERVICES,
    'weightRequired' => WEIGHT_REQUIRED_SERVICES,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request an Errand | ErrandGo</title>
    <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/theme.css">
</head>
<body>
<?php include 'partials/header.php'; ?>
<div class="container narrow">
    <div class="panel">
        <h1 class="page-banner">Request an Errand</h1>
        <p class="subtitle">Hello, <?= e($customerName) ?>! Tell us what you need help with. You can add up to <?= (int) MAX_ERRANDS_PER_BOOKING ?> different errands in one request (for example, buy groceries and deliver a parcel).</p>
        <p class="required-note">Fields marked * are required. Service area: Mandaluyong City only.</p>
        <?php if ($err): ?><div class="error"><?= e($err) ?></div><?php endif; ?>

        <form action="submit_request.php" method="POST" id="request-form">
            <div class="form-row">
                <div>
                    <label for="preferred_date">Preferred Date *</label>
                    <input type="date" id="preferred_date" name="preferred_date" min="<?= date('Y-m-d') ?>" required>
                </div>
                <div>
                    <label for="preferred_time">Preferred Time *</label>
                    <input type="time" id="preferred_time" name="preferred_time" required>
                </div>
            </div>

            <label for="payment_method">Preferred Payment Method</label>
            <select id="payment_method" name="payment_method">
                <option value="">-- No preference --</option>
                <?php foreach (PAYMENT_METHODS as $p): ?>
                    <option value="<?= e($p) ?>"><?= e($p) ?></option>
                <?php endforeach; ?>
            </select>

            <div id="errand-list"></div>
            <button type="button" id="add-errand" class="secondary-btn">+ Add another errand</button>

            <div class="panel" id="grand-box" style="background:var(--navy-soft);margin-top:16px">
                <div class="live-row"><strong>Errands in this request</strong><strong id="grand-count">1</strong></div>
                <div class="live-row"><strong>Estimated total (all errands)</strong><strong id="grand-total">&mdash;</strong></div>
                <p class="small" style="margin-bottom:0">Fees = base rate + distance (per km) + weight + extra riders, calculated again by the server when you submit. Distance is estimated from the pickup and drop-off barangays. Item costs are paid at the actual price and are only an estimate. Your payment choice is recorded only; online payment is not available yet. Please don't request unsafe or unlawful tasks.</p>
            </div>

            <button type="submit" class="primary">Submit Errand Request</button>
            <a class="back-link" href="customer_dashboard.php">Back to Dashboard</a>
        </form>
    </div>
</div>

<template id="errand-template">
<fieldset class="errand-block" data-index="__i__">
    <div class="errand-head">
        <h3 class="errand-title">Errand</h3>
        <button type="button" class="remove-errand danger">Remove</button>
    </div>

    <label>Service Type *</label>
    <select name="errands[__i__][service_type]" class="f-service" required>
        <option value="">-- Select a service --</option>
        <?php foreach (SERVICES as $s): ?>
            <option value="<?= e($s) ?>"><?= e($s) ?></option>
        <?php endforeach; ?>
    </select>

    <label>Errand Specification *</label>
    <textarea name="errands[__i__][description]" placeholder="Describe what you need the worker to do..." maxlength="2000" required></textarea>

    <div class="form-row">
        <div>
            <label>Pickup Area *</label>
            <select name="errands[__i__][pickup_area]" class="f-pickup" required>
                <option value="">-- Select the pickup area --</option>
                <?php foreach (PICKUP_AREAS as $a): ?>
                    <option value="<?= e($a) ?>"><?= e($a) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Pickup Address / Landmark *</label>
            <input type="text" name="errands[__i__][pickup_location]" placeholder="Street, building, or landmark" maxlength="255" required>
        </div>
    </div>

    <div class="form-row">
        <div>
            <label>Drop-off Area (Mandaluyong only) <span class="f-drop-req">*</span></label>
            <select name="errands[__i__][dropoff_area]" class="f-drop">
                <option value="">-- Select the drop-off area --</option>
                <?php foreach (PICKUP_AREAS as $a): ?>
                    <option value="<?= e($a) ?>"><?= e($a) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Drop-off Address / Landmark <span class="f-drop-req">*</span></label>
            <input type="text" name="errands[__i__][dropoff_location]" class="f-drop-addr" placeholder="Street, building, or landmark" maxlength="255">
        </div>
    </div>

    <div class="sub-box">
        <strong>Should the worker buy item(s) for you?</strong>
        <label class="choice"><input type="radio" name="errands[__i__][needs_purchase]" value="0" class="f-buy" checked> No, there is nothing to buy</label>
        <label class="choice"><input type="radio" name="errands[__i__][needs_purchase]" value="1" class="f-buy"> Yes, please buy item(s) for me</label>
        <div class="items-box" hidden>
            <p class="small" style="margin:8px 0 4px">Item specification: list each item with its brand, size or variant.</p>
            <div class="item-rows"></div>
            <button type="button" class="add-item secondary-btn">+ Add item</button>
            <label>Estimated Item Cost (&#8369;)</label>
            <input type="number" name="errands[__i__][item_cost]" class="f-item-cost" min="0" max="100000" step="0.01" placeholder="Your best estimate of the total price of the items">
            <p class="small">Items are paid at their actual price. This is only an estimate for your total.</p>
        </div>
    </div>

    <div class="sub-box">
        <strong>Weight</strong> <span class="small f-weight-req"></span>
        <div class="form-row">
            <div>
                <label>Approx. weight (kg)</label>
                <input type="number" name="errands[__i__][weight_kg]" class="f-weight" min="0.1" max="<?= (int) MAX_WEIGHT_KG ?>" step="0.1" placeholder="e.g. 12.5">
            </div>
            <div>
                <label>Describe the item(s) or load</label>
                <input type="text" name="errands[__i__][weight_desc]" class="f-weight-desc" maxlength="255" placeholder="e.g. 2 boxes of books, 1 electric fan">
            </div>
        </div>
        <p class="small weight-info" style="margin-bottom:0"></p>
    </div>

    <div class="sub-box bulk-box">
        <label class="choice"><input type="checkbox" name="errands[__i__][bulk]" value="1" class="f-bulk"> This is a bulk errand (many, large, or heavy items)</label>
        <p class="small bulk-auto" hidden>Marked as bulk automatically (multiple errands, moving, heavy load, or a long item list).</p>
        <div class="riders-row" hidden>
            <label>Extra riders to help</label>
            <select name="errands[__i__][extra_riders]" class="f-riders">
                <?php for ($n = 0; $n < MAX_RIDERS; $n++): ?>
                    <option value="<?= $n ?>"><?= $n === 0 ? 'No extra rider' : $n . ' extra rider' . ($n > 1 ? 's' : '') ?></option>
                <?php endfor; ?>
            </select>
            <p class="small">Each extra rider adds a fee. You can also add a rider later from My Requests.</p>
        </div>
    </div>

    <div class="block-fee">
        <div class="live-row"><span>Base rate (service + delivery)</span><span class="b-base">&mdash;</span></div>
        <div class="live-row"><span>Distance <span class="b-km"></span></span><span class="b-dist">&mdash;</span></div>
        <div class="live-row b-weight-row"><span>Weight fee <span class="b-tier"></span></span><span class="b-weight">&mdash;</span></div>
        <div class="live-row b-riders-row"><span>Extra riders</span><span class="b-riders">&mdash;</span></div>
        <div class="live-row b-item-row"><span>Estimated item cost</span><span class="b-item">&mdash;</span></div>
        <div class="live-row"><strong>Errand total</strong><strong class="b-total">Select a service</strong></div>
    </div>
</fieldset>
</template>

<template id="item-template">
    <div class="item-row">
        <input type="text" name="errands[__i__][items][__j__][name]" class="i-name" placeholder="Item name (e.g. Rice)" maxlength="100">
        <input type="number" name="errands[__i__][items][__j__][qty]" class="i-qty" min="1" max="999" value="1" aria-label="Quantity">
        <input type="text" name="errands[__i__][items][__j__][spec]" placeholder="Brand / size / variant / notes" maxlength="255">
        <button type="button" class="rm-item" aria-label="Remove item">&times;</button>
    </div>
</template>

<?php include 'partials/footer.php'; ?>
<script>
(function () {
    var D = <?= json_encode($jsData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var list = document.getElementById('errand-list');
    var addBtn = document.getElementById('add-errand');
    var tplErrand = document.getElementById('errand-template').innerHTML;
    var tplItem = document.getElementById('item-template').innerHTML;
    var counter = 0;

    function peso(n) { return '\u20B1' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function q(el, sel) { return el.querySelector(sel); }
    function blocks() { return list.querySelectorAll('.errand-block'); }

    function addItemRow(block) {
        var rows = q(block, '.item-rows');
        if (rows.children.length >= D.maxItems) { return; }
        var i = block.getAttribute('data-index');
        var j = (parseInt(block.getAttribute('data-next-item') || '0', 10));
        block.setAttribute('data-next-item', j + 1);
        var wrap = document.createElement('div');
        wrap.innerHTML = tplItem.replace(/__i__/g, i).replace(/__j__/g, j);
        var row = wrap.firstElementChild;
        rows.appendChild(row);
        q(row, '.i-name').required = true;
    }

    function itemCount(block) {
        var n = 0;
        block.querySelectorAll('.i-name').forEach(function (el) { if (el.value.trim() !== '') { n++; } });
        return n;
    }

    function buying(block) {
        var r = block.querySelector('.f-buy:checked');
        return !!r && r.value === '1';
    }

    function autoBulk(block) {
        var svc = q(block, '.f-service').value;
        var kg = parseFloat(q(block, '.f-weight').value);
        return blocks().length > 1 || D.bulkServices.indexOf(svc) !== -1 || (!isNaN(kg) && kg > D.bulkKg) ||
            (buying(block) && itemCount(block) >= D.bulkItems);
    }

    function tierFor(kg) {
        for (var k = 0; k < D.tiers.length; k++) { if (kg <= D.tiers[k].max) { return D.tiers[k]; } }
        return null;
    }

    function updateBlock(block) {
        var svc = q(block, '.f-service').value;
        var r = D.rates[svc];
        var pa = q(block, '.f-pickup').value, da = q(block, '.f-drop').value;

        // Drop-off required unless the service says otherwise.
        var dropOptional = D.noDropoff.indexOf(svc) !== -1;
        q(block, '.f-drop').required = !dropOptional;
        q(block, '.f-drop-addr').required = !dropOptional || da !== '';
        block.querySelectorAll('.f-drop-req').forEach(function (s) { s.textContent = (dropOptional && da === '') ? '(optional)' : '*'; });

        // Weight: required for some services.
        var wReq = D.weightRequired.indexOf(svc) !== -1;
        q(block, '.f-weight').required = wReq;
        q(block, '.f-weight-desc').required = wReq;
        q(block, '.f-weight-req').textContent = wReq ? '(required for this service)' : '(optional: fill in if the items are heavy)';

        // Items only when buying.
        var buy = buying(block);
        var box = q(block, '.items-box');
        box.hidden = !buy;
        if (buy && q(block, '.item-rows').children.length === 0) { addItemRow(block); }
        block.querySelectorAll('.i-name').forEach(function (el) { el.required = buy; });
        q(block, '.f-item-cost').disabled = !buy;

        // Bulk and extra riders.
        var auto = autoBulk(block), chk = q(block, '.f-bulk');
        var bulk = auto || chk.checked;
        q(block, '.bulk-auto').hidden = !auto;
        q(block, '.bulk-box label.choice').hidden = auto;
        q(block, '.riders-row').hidden = !bulk;
        var riders = bulk ? parseInt(q(block, '.f-riders').value || '0', 10) : 0;

        // Weight tier fee.
        var kg = parseFloat(q(block, '.f-weight').value), wFee = 0, tier = null, info = q(block, '.weight-info');
        if (!isNaN(kg) && kg > 0) {
            tier = tierFor(kg);
            if (tier) { wFee = tier.fee; info.textContent = tier.label + ' load: weight fee ' + peso(tier.fee) + '.'; }
            else { info.textContent = 'The maximum is ' + D.maxKg + ' kg per errand.'; }
        } else { info.textContent = ''; }

        var out = { total: null };
        if (r) {
            var km = 0;
            if (da && pa && D.dist[pa] && D.dist[pa][da] !== undefined) { km = D.dist[pa][da]; }
            var item = parseFloat(q(block, '.f-item-cost').value);
            var itemOk = buy && !isNaN(item) && item >= 0;
            var base = r.service_fee + r.delivery_fee, dFee = Math.round(km * r.per_km_fee * 100) / 100;
            var xFee = Math.round(Math.max(0, riders) * D.extraRiderFee * 100) / 100;
            var total = base + dFee + wFee + xFee + (itemOk ? item : 0);
            q(block, '.b-base').textContent = peso(base);
            q(block, '.b-km').textContent = km > 0 ? '(about ' + km.toFixed(1) + ' km x ' + peso(r.per_km_fee) + ')' : '(no drop-off selected)';
            q(block, '.b-dist').textContent = peso(dFee);
            q(block, '.b-weight').textContent = peso(wFee);
            q(block, '.b-tier').textContent = tier ? '(' + tier.label + ')' : '';
            q(block, '.b-riders').textContent = peso(xFee);
            q(block, '.b-item').textContent = itemOk ? peso(item) : '\u2014';
            q(block, '.b-weight-row').hidden = !(wFee > 0 || tier);
            q(block, '.b-riders-row').hidden = riders < 1;
            q(block, '.b-item-row').hidden = !buy;
            q(block, '.b-total').textContent = peso(total);
            out.total = total;
        } else {
            q(block, '.b-total').textContent = 'Select a service';
            ['.b-base', '.b-dist', '.b-weight', '.b-riders', '.b-item'].forEach(function (s) { q(block, s).textContent = '\u2014'; });
            q(block, '.b-km').textContent = ''; q(block, '.b-tier').textContent = '';
        }
        return out.total;
    }

    function refresh() {
        var all = blocks(), sum = 0, any = false;
        all.forEach(function (b, idx) {
            q(b, '.errand-title').textContent = all.length > 1 ? 'Errand ' + (idx + 1) + ' of ' + all.length : 'Errand details';
            q(b, '.remove-errand').hidden = all.length < 2;
        });
        all.forEach(function (b) { var t = updateBlock(b); if (t !== null) { sum += t; any = true; } });
        document.getElementById('grand-count').textContent = all.length;
        document.getElementById('grand-total').textContent = any ? peso(sum) : '\u2014';
        addBtn.hidden = all.length >= D.maxErrands;
    }

    function addErrand() {
        if (blocks().length >= D.maxErrands) { return; }
        var wrap = document.createElement('div');
        wrap.innerHTML = tplErrand.replace(/__i__/g, counter++);
        list.appendChild(wrap.firstElementChild);
        refresh();
    }

    list.addEventListener('input', refresh);
    list.addEventListener('change', refresh);
    list.addEventListener('click', function (ev) {
        var t = ev.target, block = t.closest('.errand-block');
        if (!block) { return; }
        if (t.classList.contains('remove-errand')) { block.remove(); refresh(); }
        else if (t.classList.contains('add-item')) { addItemRow(block); refresh(); }
        else if (t.classList.contains('rm-item')) {
            var rows = q(block, '.item-rows');
            if (rows.children.length > 1) { t.closest('.item-row').remove(); refresh(); }
        }
    });
    addBtn.addEventListener('click', addErrand);
    addErrand();
})();
</script>
</body>
</html>
