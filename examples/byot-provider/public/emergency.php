<?php
declare(strict_types=1);

use Domaintains\Byot\EmergencyApplication;

require dirname(__DIR__) . '/src/bootstrap.php';

EmergencyApplication::headers();
try {
    EmergencyApplication::startSession();
    $application = EmergencyApplication::load();
    if ($application->authentication->current() === null) {
        http_response_code(401);
        echo 'Customer authentication required.';
        exit;
    }
    $_SESSION['emergency_csrf'] ??= bin2hex(random_bytes(32));
    $csrf = $_SESSION['emergency_csrf'];
} catch (Throwable $error) {
    http_response_code(503);
    echo 'Emergency management is unavailable.';
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
    <title>Emergency Management | DOMAINTAINS</title>
    <link rel="stylesheet" href="emergency.css">
    <script src="emergency.js" defer></script>
</head>
<body>
<main>
    <header><span>DOMAINTAINS</span><h1>Emergency Management</h1></header>
    <p id="notice" role="status" aria-live="polite"></p>
    <form id="selection">
        <div class="grid">
            <label>Service<select id="service" name="service" required></select></label>
            <label>PBX<select id="pbx" name="pbx" required></select></label>
        </div>
        <button type="submit">Load authorised numbers</button>
    </form>
    <section id="number-section" hidden>
        <label>Telephone number<select id="number"></select></label>
        <div class="actions"><button type="button" id="retrieve">Retrieve details</button><button type="button" id="check-status">Check status</button></div>
        <p id="registration-status" role="status" aria-live="polite"></p>
    </section>
    <form id="registration" hidden>
        <fieldset id="address-fields">
            <legend>Emergency registration</legend>
            <div class="types" role="group" aria-label="Registration type">
                <label><input type="radio" name="type" value="individual">Individual</label>
                <label><input type="radio" name="type" value="business" checked>Business</label>
            </div>
            <div class="grid">
                <label data-individual hidden>Title<input name="title" maxlength="200" autocomplete="honorific-prefix"></label>
                <label data-individual hidden>Forename<input name="forename" maxlength="200" autocomplete="given-name"></label>
                <label data-individual hidden>Honours<input name="honours" maxlength="200" list="honours"></label>
                <label><span id="name-label">Business name</span><input name="name" maxlength="200" required></label>
                <label data-business>Business Suffix<input name="business_suffix" maxlength="200" list="suffixes"></label>
            </div>
            <datalist id="honours"><option value="BEM"><option value="MBE"><option value="OBE"><option value="CBE"><option value="PhD"></datalist>
            <datalist id="suffixes"><option value="Ltd"><option value="Limited"><option value="LLP"><option value="PLC"><option value="Inc"><option value="LLC"></datalist>
            <div class="grid">
                <label class="wide">Address line 1<input name="address_line_1" maxlength="200" required autocomplete="address-line1"></label>
                <label class="wide">Address line 2<input name="address_line_2" maxlength="200" autocomplete="address-line2"></label>
                <label>Town / locality<input name="locality" maxlength="200" required autocomplete="address-level2"></label>
                <label>Region<input name="region" maxlength="200" autocomplete="address-level1"></label>
                <label>Postcode<input name="postcode" maxlength="200" required autocomplete="postal-code"></label>
                <label>Country code<input name="country" maxlength="2" pattern="[A-Z]{2}" required autocomplete="country"></label>
            </div>
            <label class="confirmation"><input type="checkbox" id="confirmed">I confirm that this emergency address and registration information are accurate.</label>
            <button id="submit" type="submit" disabled>Submit emergency information</button>
        </fieldset>
    </form>
</main>
</body>
</html>