<?php
require_once __DIR__ . '/auth.php';
$current_user = wp_get_current_user();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ADESZ - Administration</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: #F8F7F4;
            color: #2D3436;
            line-height: 1.5;
            min-height: 100vh;
        }

        /* Header */
        .header {
            background: #1B5E27;
            color: #fff;
            padding: 14px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header h1 {
            font-size: 18px;
            font-weight: 600;
            letter-spacing: 0.5px;
        }
        .header .user-info {
            font-size: 13px;
            opacity: 0.85;
        }

        /* Container */
        .container {
            max-width: 900px;
            margin: 0 auto;
            padding: 24px 16px;
        }

        /* Tabs */
        .tabs {
            display: flex;
            gap: 0;
            border-bottom: 2px solid #ddd;
            margin-bottom: 24px;
        }
        .tab-btn {
            padding: 10px 24px;
            border: none;
            background: none;
            font-size: 15px;
            font-weight: 500;
            color: #666;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
            transition: color 0.2s, border-color 0.2s;
        }
        .tab-btn:hover { color: #2D7A3A; }
        .tab-btn.active {
            color: #2D7A3A;
            border-bottom-color: #2D7A3A;
        }

        /* Cards */
        .card {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.08);
            padding: 28px;
            margin-bottom: 20px;
        }
        .card h2 {
            font-size: 17px;
            margin-bottom: 20px;
            color: #1B5E27;
        }

        /* Form */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        .form-group {
            display: flex;
            flex-direction: column;
        }
        .form-group.full-width {
            grid-column: 1 / -1;
        }
        .form-group label {
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 4px;
            color: #555;
        }
        .form-group input,
        .form-group select {
            padding: 8px 10px;
            border: 1px solid #ccc;
            border-radius: 5px;
            font-size: 14px;
            font-family: inherit;
            transition: border-color 0.2s;
        }
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #2D7A3A;
            box-shadow: 0 0 0 2px rgba(45,122,58,0.15);
        }

        /* Search / autocomplete */
        .search-wrapper {
            position: relative;
            margin-bottom: 20px;
        }
        .search-wrapper input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ccc;
            border-radius: 5px;
            font-size: 14px;
            font-family: inherit;
        }
        .search-wrapper input:focus {
            outline: none;
            border-color: #2D7A3A;
            box-shadow: 0 0 0 2px rgba(45,122,58,0.15);
        }
        .autocomplete-list {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: #fff;
            border: 1px solid #ccc;
            border-top: none;
            border-radius: 0 0 5px 5px;
            max-height: 220px;
            overflow-y: auto;
            z-index: 100;
            display: none;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .autocomplete-list.open { display: block; }
        .autocomplete-item {
            padding: 10px 12px;
            cursor: pointer;
            font-size: 14px;
            border-bottom: 1px solid #f0f0f0;
        }
        .autocomplete-item:last-child { border-bottom: none; }
        .autocomplete-item:hover,
        .autocomplete-item.highlighted {
            background: #e8f5e9;
        }
        .autocomplete-item .donor-email {
            font-size: 12px;
            color: #888;
        }

        /* Buttons */
        .btn {
            display: inline-block;
            padding: 10px 22px;
            border: none;
            border-radius: 5px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            font-family: inherit;
            transition: opacity 0.2s, transform 0.1s;
        }
        .btn:hover { opacity: 0.9; }
        .btn:active { transform: scale(0.98); }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .btn-primary { background: #2D7A3A; color: #fff; }
        .btn-outline {
            background: #fff;
            color: #2D7A3A;
            border: 2px solid #2D7A3A;
        }
        .btn-yellow { background: #F5C518; color: #2D3436; }
        .btn-danger { background: #c0392b; color: #fff; }

        /* Messages */
        .msg {
            padding: 10px 14px;
            border-radius: 5px;
            font-size: 14px;
            margin-top: 16px;
            display: none;
        }
        .msg.show { display: block; }
        .msg-success { background: #e8f5e9; color: #1B5E27; border: 1px solid #a5d6a7; }
        .msg-error { background: #fdecea; color: #c0392b; border: 1px solid #f5c6cb; }
        .msg-info { background: #e3f2fd; color: #1565c0; border: 1px solid #90caf9; }

        /* Tab content */
        .tab-content { display: none; }
        .tab-content.active { display: block; }

        /* Annual receipts */
        .year-row {
            display: flex;
            gap: 12px;
            align-items: center;
            margin-bottom: 20px;
        }
        .year-row select {
            padding: 8px 12px;
            border: 1px solid #ccc;
            border-radius: 5px;
            font-size: 14px;
            font-family: inherit;
        }

        .stats-bar {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            padding: 12px 16px;
            background: #e8f5e9;
            border-radius: 5px;
            margin-bottom: 16px;
            font-size: 14px;
            font-weight: 500;
            color: #1B5E27;
        }

        .donors-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        .donors-table th {
            text-align: left;
            padding: 8px 10px;
            border-bottom: 2px solid #ddd;
            font-weight: 600;
            font-size: 13px;
            color: #555;
        }
        .donors-table td {
            padding: 8px 10px;
            border-bottom: 1px solid #eee;
        }
        .donors-table tr.no-email {
            background: #fff8e1;
        }

        .actions-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 20px;
        }

        #preview-results { display: none; }

        /* Responsive */
        @media (max-width: 600px) {
            .form-grid { grid-template-columns: 1fr; }
            .header { flex-direction: column; gap: 4px; }
            .stats-bar { flex-direction: column; gap: 6px; }
            .actions-row { flex-direction: column; }
            .actions-row .btn { width: 100%; text-align: center; }
        }
    </style>
</head>
<body>

<div class="header">
    <h1>ADESZ &mdash; Administration</h1>
    <span class="user-info"><?php echo esc_html($current_user->display_name); ?></span>
</div>

<div class="container">
    <div class="tabs">
        <button class="tab-btn active" data-tab="tab-donation">Saisir un don</button>
        <button class="tab-btn" data-tab="tab-donations-list">Voir les dons</button>
        <button class="tab-btn" data-tab="tab-receipts">Re&ccedil;us annuels</button>
        <button class="tab-btn" data-tab="tab-export">Export</button>
    </div>

    <!-- Tab 1: Saisir un don -->
    <div id="tab-donation" class="tab-content active">
        <div class="card">
            <h2>Nouveau don / adh&eacute;sion</h2>

            <div class="search-wrapper">
                <input type="text" id="donor-search" placeholder="Rechercher un donateur (nom, pr&eacute;nom, email)&hellip;" autocomplete="off">
                <div class="autocomplete-list" id="autocomplete-list"></div>
            </div>

            <form id="donation-form">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="f-prenom">Pr&eacute;nom *</label>
                        <input type="text" id="f-prenom" name="prenom" required>
                    </div>
                    <div class="form-group">
                        <label for="f-nom">Nom *</label>
                        <input type="text" id="f-nom" name="nom" required>
                    </div>
                    <div class="form-group">
                        <label for="f-email">Email</label>
                        <input type="email" id="f-email" name="email">
                    </div>
                    <div class="form-group">
                        <label for="f-telephone">T&eacute;l&eacute;phone</label>
                        <input type="text" id="f-telephone" name="telephone">
                    </div>
                    <div class="form-group full-width">
                        <label for="f-adresse">Adresse</label>
                        <input type="text" id="f-adresse" name="adresse">
                    </div>
                    <div class="form-group">
                        <label for="f-code_postal">Code postal</label>
                        <input type="text" id="f-code_postal" name="code_postal">
                    </div>
                    <div class="form-group">
                        <label for="f-commune">Commune</label>
                        <input type="text" id="f-commune" name="commune">
                    </div>
                    <div class="form-group">
                        <label for="f-amount">Montant &euro; *</label>
                        <input type="number" id="f-amount" name="amount" step="0.01" min="0.01" required>
                    </div>
                    <div class="form-group">
                        <label for="f-date">Date *</label>
                        <input type="date" id="f-date" name="date_don" required>
                    </div>
                    <div class="form-group">
                        <label for="f-type">Type *</label>
                        <select id="f-type" name="type" required>
                            <option value="don">Don</option>
                            <option value="adhesion">Adh&eacute;sion</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="f-mode">Mode de paiement *</label>
                        <select id="f-mode" name="mode_paiement" required>
                            <option value="cheque">Ch&egrave;que</option>
                            <option value="especes">Esp&egrave;ces</option>
                            <option value="virement">Virement</option>
                            <option value="helloasso">HelloAsso</option>
                        </select>
                    </div>
                </div>

                <div style="margin-top: 20px;">
                    <button type="submit" class="btn btn-primary" id="btn-save">Enregistrer</button>
                </div>

                <div class="msg msg-success" id="msg-success"></div>
                <div class="msg msg-error" id="msg-error"></div>
            </form>

            <!-- Confirmation panel (hidden by default) -->
            <div id="confirm-panel" style="display:none;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                    <h2 style="color:#1B5E27; font-size:17px; margin:0;">Don enregistr&eacute;</h2>
                    <button class="btn btn-outline" id="btn-new-donation" style="padding:6px 16px;">Nouveau don</button>
                </div>

                <div id="confirm-summary" style="background:#e8f5e9; padding:14px 18px; border-radius:5px; margin-bottom:16px; font-size:14px;"></div>

                <!-- Receipt HTML preview -->
                <div id="receipt-preview" style="border:1px solid #ddd; border-radius:5px; overflow:hidden; margin-bottom:16px;"></div>

                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                    <button class="btn btn-yellow" id="btn-edit-donation">Modifier</button>
                    <button class="btn btn-primary" id="btn-send-receipt" style="display:none;">Envoyer le re&ccedil;u par email</button>
                    <button class="btn btn-outline" id="btn-download-receipt">T&eacute;l&eacute;charger PDF</button>
                </div>

                <div class="msg msg-success" id="receipt-success"></div>
                <div class="msg msg-error" id="receipt-error"></div>
                <div class="msg msg-info" id="receipt-loading" style="display:none;">Envoi en cours&hellip;</div>
            </div>
        </div>
    </div>

    <!-- Tab 2: Voir les dons -->
    <div id="tab-donations-list" class="tab-content">
        <div class="card">
            <h2>Liste des dons</h2>

            <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap; margin-bottom:16px;">
                <div>
                    <label for="dl-year" style="font-size:13px; font-weight:500; color:#555;">Ann&eacute;e :</label>
                    <select id="dl-year" style="padding:6px 10px; border:1px solid #ccc; border-radius:5px; font-size:14px; font-family:inherit;"></select>
                </div>
                <div>
                    <label for="dl-type" style="font-size:13px; font-weight:500; color:#555;">Type :</label>
                    <select id="dl-type" style="padding:6px 10px; border:1px solid #ccc; border-radius:5px; font-size:14px; font-family:inherit;">
                        <option value="">Tous</option>
                        <option value="don">Don</option>
                        <option value="adhesion">Adh&eacute;sion</option>
                        <option value="combo">Combo</option>
                    </select>
                </div>
                <div style="flex:1; min-width:180px;">
                    <input type="text" id="dl-search" placeholder="Rechercher (nom, email)&hellip;" style="width:100%; padding:6px 10px; border:1px solid #ccc; border-radius:5px; font-size:14px; font-family:inherit;">
                </div>
                <button class="btn btn-primary" id="btn-dl-search" style="padding:7px 18px;">Filtrer</button>
            </div>

            <div class="stats-bar" id="dl-stats" style="display:none;"></div>
            <div class="msg msg-info" id="dl-loading" style="display:none;">Chargement&hellip;</div>
            <div class="msg msg-error" id="dl-error"></div>

            <div style="overflow-x:auto;">
                <table class="donors-table" id="dl-table" style="display:none;">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Nom</th>
                            <th>Pr&eacute;nom</th>
                            <th>Email</th>
                            <th>Montant</th>
                            <th>Type</th>
                            <th>Mode</th>
                            <th>Source</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="dl-tbody"></tbody>
                </table>
            </div>

            <div id="dl-pagination" style="display:none; margin-top:16px; gap:8px; align-items:center; justify-content:center;">
                <button class="btn btn-outline" id="dl-prev" style="padding:6px 14px;">&larr; Pr&eacute;c&eacute;dent</button>
                <span id="dl-page-info" style="font-size:14px; color:#555;"></span>
                <button class="btn btn-outline" id="dl-next" style="padding:6px 14px;">Suivant &rarr;</button>
            </div>
        </div>
    </div>

    <!-- Tab 3: Recus annuels -->
    <div id="tab-receipts" class="tab-content">
        <div class="card">
            <h2>G&eacute;n&eacute;ration des re&ccedil;us fiscaux annuels</h2>

            <div class="year-row">
                <label for="receipt-year" style="font-weight:500;">Ann&eacute;e :</label>
                <select id="receipt-year"></select>
                <button class="btn btn-primary" id="btn-preview">Pr&eacute;visualiser</button>
            </div>

            <div class="msg msg-error" id="preview-error"></div>
            <div class="msg msg-info" id="preview-loading" style="display:none;">Chargement en cours&hellip;</div>

            <div id="preview-results">
                <div class="stats-bar" id="stats-bar"></div>

                <div style="overflow-x:auto;">
                    <table class="donors-table">
                        <thead>
                            <tr>
                                <th>Nom</th>
                                <th>Pr&eacute;nom</th>
                                <th>Email</th>
                                <th>Nb dons</th>
                                <th>Total &euro;</th>
                            </tr>
                        </thead>
                        <tbody id="donors-tbody"></tbody>
                    </table>
                </div>

                <div class="actions-row" id="actions-row">
                    <button class="btn btn-outline" id="btn-pdf">Aper&ccedil;u PDF</button>
                    <button class="btn btn-yellow" id="btn-test-send">Envoi test</button>
                    <button class="btn btn-danger" id="btn-send">Envoyer les re&ccedil;us</button>
                </div>

                <div class="msg msg-success" id="send-success"></div>
                <div class="msg msg-error" id="send-error"></div>
                <div class="msg msg-info" id="send-loading" style="display:none;">Envoi en cours, veuillez patienter&hellip;</div>
            </div>
        </div>
    </div>

    <!-- Tab 4: Export -->
    <div id="tab-export" class="tab-content">
        <div class="card">
            <h2>Export de la base de donn&eacute;es</h2>

            <div style="display:flex; flex-direction:column; gap:20px;">
                <div style="padding:16px; border:1px solid #e0e0e0; border-radius:8px;">
                    <h3 style="font-size:15px; color:#1B5E27; margin-bottom:12px;">Dons</h3>
                    <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                        <label for="export-year" style="font-weight:500;">Ann&eacute;e :</label>
                        <select id="export-year" style="padding:6px 10px; border:1px solid #ccc; border-radius:6px;">
                            <option value="">Toutes</option>
                        </select>
                        <a id="btn-export-donations" class="btn btn-primary" style="text-decoration:none;" href="#">T&eacute;l&eacute;charger (.xlsx)</a>
                    </div>
                </div>

                <div style="padding:16px; border:1px solid #e0e0e0; border-radius:8px;">
                    <h3 style="font-size:15px; color:#1B5E27; margin-bottom:12px;">Contacts</h3>
                    <div style="display:flex; align-items:center; gap:12px;">
                        <a class="btn btn-primary" style="text-decoration:none;" href="api-export.php?table=contacts">T&eacute;l&eacute;charger (.xlsx)</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';


    // ── Helpers ──

    function esc(str) {
        if (!str) return '';
        var d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    function showMsg(el, text) {
        el.textContent = text;
        el.classList.add('show');
    }

    function hideMsg(el) {
        el.classList.remove('show');
        el.textContent = '';
    }

    function setLoading(btn, loading) {
        btn.disabled = loading;
    }

    // ── Tabs ──

    var tabBtns = document.querySelectorAll('.tab-btn');
    tabBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            tabBtns.forEach(function(b) { b.classList.remove('active'); });
            btn.classList.add('active');
            document.querySelectorAll('.tab-content').forEach(function(c) {
                c.classList.remove('active');
            });
            document.getElementById(btn.dataset.tab).classList.add('active');
        });
    });

    // ── Tab 1: Donation form ──

    // Set default date to today
    var dateField = document.getElementById('f-date');
    dateField.value = new Date().toISOString().slice(0, 10);

    // Autocomplete
    var searchInput = document.getElementById('donor-search');
    var acList = document.getElementById('autocomplete-list');
    var debounceTimer = null;
    var highlightedIdx = -1;
    var currentResults = [];

    searchInput.addEventListener('input', function() {
        var q = searchInput.value.trim();
        clearTimeout(debounceTimer);
        if (q.length < 2) {
            closeAutocomplete();
            return;
        }
        debounceTimer = setTimeout(function() {
            fetch('api-search.php?q=' + encodeURIComponent(q))
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    currentResults = data;
                    renderAutocomplete(data);
                })
                .catch(function() {
                    closeAutocomplete();
                });
        }, 300);
    });

    searchInput.addEventListener('keydown', function(e) {
        var items = acList.querySelectorAll('.autocomplete-item');
        if (!items.length) return;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            highlightedIdx = Math.min(highlightedIdx + 1, items.length - 1);
            updateHighlight(items);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            highlightedIdx = Math.max(highlightedIdx - 1, 0);
            updateHighlight(items);
        } else if (e.key === 'Enter' && highlightedIdx >= 0) {
            e.preventDefault();
            selectDonor(currentResults[highlightedIdx]);
        } else if (e.key === 'Escape') {
            closeAutocomplete();
        }
    });

    function renderAutocomplete(donors) {
        acList.innerHTML = '';
        highlightedIdx = -1;
        if (!donors.length) {
            closeAutocomplete();
            return;
        }
        donors.forEach(function(d, i) {
            var div = document.createElement('div');
            div.className = 'autocomplete-item';
            var nameSpan = document.createElement('span');
            nameSpan.textContent = (d.nom || '') + ' ' + (d.prenom || '');
            div.appendChild(nameSpan);
            if (d.email) {
                var emailSpan = document.createElement('span');
                emailSpan.className = 'donor-email';
                emailSpan.textContent = ' — ' + d.email;
                div.appendChild(emailSpan);
            }
            div.addEventListener('click', function() {
                selectDonor(d);
            });
            acList.appendChild(div);
        });
        acList.classList.add('open');
    }

    function updateHighlight(items) {
        items.forEach(function(it, i) {
            it.classList.toggle('highlighted', i === highlightedIdx);
        });
    }

    function closeAutocomplete() {
        acList.classList.remove('open');
        acList.innerHTML = '';
        highlightedIdx = -1;
        currentResults = [];
    }

    function selectDonor(d) {
        document.getElementById('f-prenom').value = d.prenom || '';
        document.getElementById('f-nom').value = d.nom || '';
        document.getElementById('f-email').value = d.email || '';
        document.getElementById('f-telephone').value = d.telephone || '';
        document.getElementById('f-adresse').value = d.adresse || '';
        document.getElementById('f-code_postal').value = d.cp || '';
        document.getElementById('f-commune').value = d.commune || '';
        searchInput.value = '';
        closeAutocomplete();
    }

    // Close autocomplete on outside click
    document.addEventListener('click', function(e) {
        if (!searchInput.contains(e.target) && !acList.contains(e.target)) {
            closeAutocomplete();
        }
    });

    // Form submit + confirmation panel
    var form = document.getElementById('donation-form');
    var msgSuccess = document.getElementById('msg-success');
    var msgError = document.getElementById('msg-error');
    var btnSave = document.getElementById('btn-save');
    var confirmPanel = document.getElementById('confirm-panel');
    var confirmSummary = document.getElementById('confirm-summary');
    var receiptPreview = document.getElementById('receipt-preview');
    var btnSendReceipt = document.getElementById('btn-send-receipt');
    var btnDownloadReceipt = document.getElementById('btn-download-receipt');
    var btnEditDonation = document.getElementById('btn-edit-donation');
    var btnNewDonation = document.getElementById('btn-new-donation');
    var receiptSuccess = document.getElementById('receipt-success');
    var receiptError = document.getElementById('receipt-error');
    var receiptLoading = document.getElementById('receipt-loading');
    var editDonationId = 0; // 0 = insert mode, >0 = update mode
    var currentDonation = null;

    function showForm() {
        form.style.display = '';
        document.querySelector('.search-wrapper').style.display = '';
        confirmPanel.style.display = 'none';
    }

    function showConfirmation(donation) {
        currentDonation = donation;
        form.style.display = 'none';
        document.querySelector('.search-wrapper').style.display = 'none';
        confirmPanel.style.display = 'block';
        hideMsg(receiptSuccess);
        hideMsg(receiptError);
        receiptLoading.style.display = 'none';

        // Summary
        var amount = parseFloat(donation.amount).toFixed(2).replace('.', ',');
        var typeLabel = donation.type === 'adhesion' ? 'Adh\u00e9sion' : (donation.type === 'combo' ? 'Combo' : 'Don');
        var dateFmt = donation.date_don || '';
        if (dateFmt && dateFmt.indexOf('-') > 0) {
            var p = dateFmt.split('-');
            dateFmt = p[2] + '/' + p[1] + '/' + p[0];
        }
        confirmSummary.innerHTML = '';
        var summaryEl = document.createElement('div');
        summaryEl.innerHTML = '<strong>' + esc(donation.prenom) + ' ' + esc(donation.nom) + '</strong>'
            + (donation.email ? ' &mdash; ' + esc(donation.email) : '')
            + '<br>' + esc(typeLabel) + ' de <strong>' + esc(amount) + ' &euro;</strong>'
            + ' le ' + esc(dateFmt)
            + ' (' + esc(donation.mode_paiement) + ')';
        confirmSummary.appendChild(summaryEl);

        // Receipt preview HTML
        renderReceiptPreview(donation);

        // Show/hide email button
        btnSendReceipt.style.display = donation.email ? 'inline-block' : 'none';

        // Show/hide edit button (only if no receipt generated yet)
        btnEditDonation.style.display = donation.receipt_number ? 'none' : 'inline-block';
    }

    function renderReceiptPreview(d) {
        var amount = parseFloat(d.amount);
        var amountFmt = amount.toFixed(2).replace('.', ',');
        var deduction66 = (amount * 0.66).toFixed(2).replace('.', ',');
        var typeUpper = d.type === 'adhesion' ? 'COTISATION' : 'DON';
        var dateFmt = d.date_don || '';
        if (dateFmt && dateFmt.indexOf('-') > 0) {
            var p = dateFmt.split('-');
            dateFmt = p[2] + '/' + p[1] + '/' + p[0];
        }
        var donorName = ((d.prenom || '') + ' ' + (d.nom || '')).trim();
        var donorAddr = d.adresse || '';
        var donorCity = ((d.cp || '') + ' ' + (d.commune || '')).trim();

        receiptPreview.innerHTML = ''
            + '<div style="background:#2D7A3A;padding:10px 16px 8px;text-align:center;">'
            + '<div style="color:#fff;font-size:16px;font-weight:700;letter-spacing:0.5px;">ADESZ</div>'
            + '<div style="color:#F5C518;font-size:10px;margin-top:2px;">Association pour le D\u00e9veloppement, l\'Entraide et la Solidarit\u00e9</div>'
            + '</div>'
            + '<div style="background:#fff;padding:16px 20px;">'
            + '<div style="text-align:center;font-weight:600;font-size:14px;color:#1B5E27;margin-bottom:12px;">RE\u00c7U FISCAL \u2014 ' + esc(typeUpper) + '</div>'
            + '<div style="display:flex;gap:16px;margin-bottom:12px;">'
            + '<div style="flex:1;background:#F8F7F4;border:1px solid #e0e0e0;border-radius:4px;padding:10px;">'
            + '<div style="font-size:10px;color:#2D7A3A;font-weight:600;margin-bottom:4px;">ORGANISME</div>'
            + '<div style="font-size:11px;"><strong>ADESZ</strong> &mdash; Loi 1901<br>491 Bd Pierre Delmas, 06600 Antibes</div>'
            + '</div>'
            + '<div style="flex:1;background:#F8F7F4;border:1px solid #e0e0e0;border-radius:4px;padding:10px;">'
            + '<div style="font-size:10px;color:#2D7A3A;font-weight:600;margin-bottom:4px;">DONATEUR</div>'
            + '<div style="font-size:11px;"><strong>' + esc(donorName || 'Non renseign\u00e9') + '</strong>'
            + (donorAddr ? '<br>' + esc(donorAddr) : '')
            + (donorCity ? '<br>' + esc(donorCity) : '')
            + '</div></div></div>'
            + '<div style="background:#2D7A3A;color:#fff;border-radius:4px;padding:12px 16px;text-align:center;margin-bottom:12px;">'
            + '<div style="font-size:11px;opacity:0.9;">Montant du ' + esc(d.type === 'adhesion' ? 'cotisation' : 'don') + '</div>'
            + '<div style="font-size:22px;font-weight:700;">' + esc(amountFmt) + ' EUR</div>'
            + '</div>'
            + '<div style="font-size:11px;color:#555;margin-bottom:8px;">Date : ' + esc(dateFmt) + ' &mdash; Mode : ' + esc(d.mode_paiement || '') + '</div>'
            + '<div style="background:#FFFBEB;border-left:3px solid #F5C518;padding:8px 12px;border-radius:3px;font-size:11px;">'
            + '<strong>Avantage fiscal</strong> : r\u00e9duction de 66% = <strong>' + esc(deduction66) + ' EUR</strong> (art. 200 CGI)'
            + '</div>'
            + '</div>';
    }

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        hideMsg(msgSuccess);
        hideMsg(msgError);
        setLoading(btnSave, true);

        var data = {};
        var fields = ['prenom', 'nom', 'email', 'telephone', 'adresse', 'code_postal', 'commune', 'amount', 'date_don', 'type', 'mode_paiement'];
        fields.forEach(function(f) {
            var el = form.elements[f];
            if (el) data[f] = el.value.trim();
        });
        data.amount = parseFloat(data.amount) || 0;

        // Include id if in edit mode
        if (editDonationId > 0) {
            data.id = editDonationId;
        }

        fetch('api-save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        })
        .then(function(r) { return r.json().then(function(j) { return { ok: r.ok, body: j }; }); })
        .then(function(res) {
            setLoading(btnSave, false);
            if (res.ok && res.body.success) {
                editDonationId = 0;
                showConfirmation(res.body.donation);
            } else {
                showMsg(msgError, res.body.error || 'Erreur inconnue.');
            }
        })
        .catch(function() {
            setLoading(btnSave, false);
            showMsg(msgError, 'Erreur de connexion au serveur.');
        });
    });

    // "Nouveau don" button
    btnNewDonation.addEventListener('click', function() {
        editDonationId = 0;
        currentDonation = null;
        form.reset();
        dateField.value = new Date().toISOString().slice(0, 10);
        showForm();
    });

    // "Modifier" button
    btnEditDonation.addEventListener('click', function() {
        if (!currentDonation) return;
        editDonationId = parseInt(currentDonation.id, 10);
        // Pre-fill form
        document.getElementById('f-prenom').value = currentDonation.prenom || '';
        document.getElementById('f-nom').value = currentDonation.nom || '';
        document.getElementById('f-email').value = currentDonation.email || '';
        document.getElementById('f-telephone').value = currentDonation.telephone || '';
        document.getElementById('f-adresse').value = currentDonation.adresse || '';
        document.getElementById('f-code_postal').value = currentDonation.cp || '';
        document.getElementById('f-commune').value = currentDonation.commune || '';
        document.getElementById('f-amount').value = currentDonation.amount || '';
        document.getElementById('f-date').value = currentDonation.date_don || '';
        document.getElementById('f-type').value = currentDonation.type || 'don';
        document.getElementById('f-mode').value = currentDonation.mode_paiement || 'cheque';
        showForm();
    });

    // "Envoyer reçu par email" button
    btnSendReceipt.addEventListener('click', function() {
        if (!currentDonation || !currentDonation.email) return;
        if (!confirm('Envoyer le re\u00e7u fiscal \u00e0 ' + currentDonation.email + ' ?')) return;
        hideMsg(receiptSuccess);
        hideMsg(receiptError);
        receiptLoading.style.display = 'block';
        setLoading(btnSendReceipt, true);

        fetch('api-receipt.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ donation_id: parseInt(currentDonation.id, 10), action: 'send' })
        })
        .then(function(r) { return r.json().then(function(j) { return { ok: r.ok, body: j }; }); })
        .then(function(res) {
            receiptLoading.style.display = 'none';
            setLoading(btnSendReceipt, false);
            if (res.ok && res.body.success) {
                showMsg(receiptSuccess, 'Re\u00e7u ' + (res.body.receipt_number || '') + ' envoy\u00e9 \u00e0 ' + (res.body.email || currentDonation.email));
                currentDonation.receipt_number = res.body.receipt_number;
                btnEditDonation.style.display = 'none';
            } else {
                showMsg(receiptError, res.body.error || 'Erreur lors de l\'envoi.');
            }
        })
        .catch(function() {
            receiptLoading.style.display = 'none';
            setLoading(btnSendReceipt, false);
            showMsg(receiptError, 'Erreur de connexion au serveur.');
        });
    });

    // "Télécharger PDF" button
    btnDownloadReceipt.addEventListener('click', function() {
        if (!currentDonation) return;
        window.open('api-receipt.php?action=download&donation_id=' + currentDonation.id, '_blank');
    });

    // ── Tab 2: Annual receipts ──

    // Populate year select
    var yearSelect = document.getElementById('receipt-year');
    var currentYear = new Date().getFullYear();
    for (var y = currentYear; y >= 2022; y--) {
        var opt = document.createElement('option');
        opt.value = y;
        opt.textContent = y;
        if (y === currentYear - 1) opt.selected = true;
        yearSelect.appendChild(opt);
    }

    var previewResults = document.getElementById('preview-results');
    var statsBar = document.getElementById('stats-bar');
    var donorsTbody = document.getElementById('donors-tbody');
    var previewError = document.getElementById('preview-error');
    var previewLoading = document.getElementById('preview-loading');
    var btnPreview = document.getElementById('btn-preview');
    var sendSuccess = document.getElementById('send-success');
    var sendError = document.getElementById('send-error');
    var sendLoading = document.getElementById('send-loading');

    btnPreview.addEventListener('click', function() {
        var year = yearSelect.value;
        hideMsg(previewError);
        hideMsg(sendSuccess);
        hideMsg(sendError);
        previewResults.style.display = 'none';
        previewLoading.style.display = 'block';
        setLoading(btnPreview, true);

        fetch('api-annual.php?action=preview&year=' + encodeURIComponent(year))
            .then(function(r) { return r.json().then(function(j) { return { ok: r.ok, body: j }; }); })
            .then(function(res) {
                previewLoading.style.display = 'none';
                setLoading(btnPreview, false);
                if (!res.ok || res.body.error) {
                    showMsg(previewError, res.body.error || 'Erreur lors de la pr\u00e9visualisation.');
                    return;
                }
                renderPreview(res.body, year);
            })
            .catch(function() {
                previewLoading.style.display = 'none';
                setLoading(btnPreview, false);
                showMsg(previewError, 'Erreur de connexion au serveur.');
            });
    });

    function renderPreview(data, year) {
        var donors = data.donors || [];
        var totalDonations = 0;
        var totalAmount = 0;
        var noEmail = 0;

        donors.forEach(function(d) {
            totalDonations += (d.nb_dons || 0);
            totalAmount += (d.total || 0);
            if (!d.email) noEmail++;
        });

        statsBar.textContent = '';
        var parts = [
            donors.length + ' donateur' + (donors.length > 1 ? 's' : ''),
            totalDonations + ' don' + (totalDonations > 1 ? 's' : ''),
            totalAmount.toFixed(2).replace('.', ',') + ' \u20ac total',
        ];
        if (noEmail > 0) {
            parts.push(noEmail + ' sans email');
        }
        statsBar.textContent = parts.join(' \u00b7 ');

        donorsTbody.innerHTML = '';
        donors.forEach(function(d) {
            var tr = document.createElement('tr');
            if (!d.email) tr.className = 'no-email';

            var tdNom = document.createElement('td');
            tdNom.textContent = d.nom || '';
            tr.appendChild(tdNom);

            var tdPrenom = document.createElement('td');
            tdPrenom.textContent = d.prenom || '';
            tr.appendChild(tdPrenom);

            var tdEmail = document.createElement('td');
            tdEmail.textContent = d.email || '\u2014';
            tr.appendChild(tdEmail);

            var tdNb = document.createElement('td');
            tdNb.textContent = d.nb_dons || 0;
            tr.appendChild(tdNb);

            var tdTotal = document.createElement('td');
            tdTotal.textContent = (d.total || 0).toFixed(2).replace('.', ',') + ' \u20ac';
            tr.appendChild(tdTotal);

            donorsTbody.appendChild(tr);
        });

        previewResults.style.display = 'block';

        // Wire action buttons for this year
        wireActionButtons(year);
    }

    function wireActionButtons(year) {
        var btnPdf = document.getElementById('btn-pdf');
        var btnTestSend = document.getElementById('btn-test-send');
        var btnSendAll = document.getElementById('btn-send');

        // Remove old listeners by cloning
        var newPdf = btnPdf.cloneNode(true);
        btnPdf.parentNode.replaceChild(newPdf, btnPdf);
        var newTest = btnTestSend.cloneNode(true);
        btnTestSend.parentNode.replaceChild(newTest, btnTestSend);
        var newSend = btnSendAll.cloneNode(true);
        btnSendAll.parentNode.replaceChild(newSend, btnSendAll);

        newPdf.addEventListener('click', function() {
            window.open('api-annual.php?action=preview_pdf&year=' + encodeURIComponent(year), '_blank');
        });

        newTest.addEventListener('click', function() {
            if (!confirm('Envoyer un re\u00e7u test (1er donateur) sur l\u2019adresse admin. Continuer ?')) return;
            doSend('test_send', year, newTest);
        });

        newSend.addEventListener('click', function() {
            if (!confirm('ATTENTION : Cela va envoyer les re\u00e7us \u00e0 tous les donateurs. Continuer ?')) return;
            doSend('send', year, newSend);
        });
    }

    function doSend(action, year, btn) {
        hideMsg(sendSuccess);
        hideMsg(sendError);
        sendLoading.style.display = 'block';
        setLoading(btn, true);

        fetch('api-annual.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: action, year: parseInt(year, 10) })
        })
        .then(function(r) { return r.json().then(function(j) { return { ok: r.ok, body: j }; }); })
        .then(function(res) {
            sendLoading.style.display = 'none';
            setLoading(btn, false);
            if (res.ok && res.body.success) {
                var msg = res.body.sent + ' re\u00e7u' + (res.body.sent > 1 ? 's' : '') + ' envoy\u00e9' + (res.body.sent > 1 ? 's' : '') + '.';
                if (res.body.errors && res.body.errors.length > 0) {
                    msg += ' ' + res.body.errors.length + ' erreur(s).';
                }
                if (res.body.sans_email && res.body.sans_email.length > 0) {
                    msg += ' ' + res.body.sans_email.length + ' sans email.';
                }
                showMsg(sendSuccess, msg);
            } else {
                showMsg(sendError, res.body.error || 'Erreur lors de l\u2019envoi.');
            }
        })
        .catch(function() {
            sendLoading.style.display = 'none';
            setLoading(btn, false);
            showMsg(sendError, 'Erreur de connexion au serveur.');
        });
    }

    // Edit a donation from the donations list (Tab 2 → Tab 1)
    function editFromList(donation) {
        editDonationId = parseInt(donation.id, 10);
        document.getElementById('f-prenom').value = donation.prenom || '';
        document.getElementById('f-nom').value = donation.nom || '';
        document.getElementById('f-email').value = donation.email || '';
        document.getElementById('f-telephone').value = donation.telephone || '';
        document.getElementById('f-adresse').value = donation.adresse || '';
        document.getElementById('f-code_postal').value = donation.cp || '';
        document.getElementById('f-commune').value = donation.commune || '';
        document.getElementById('f-amount').value = donation.amount || '';
        document.getElementById('f-date').value = donation.date_don || '';
        document.getElementById('f-type').value = donation.type || 'don';
        document.getElementById('f-mode').value = donation.mode_paiement || 'cheque';
        // Switch to Tab 1
        document.querySelectorAll('.tab-btn').forEach(function(b) { b.classList.remove('active'); });
        document.querySelectorAll('.tab-content').forEach(function(c) { c.classList.remove('active'); });
        document.querySelector('[data-tab="tab-entry"]').classList.add('active');
        document.getElementById('tab-entry').classList.add('active');
        showForm();
    }

    // Send receipt directly from the donations list
    function sendReceiptFromList(donation, btn) {
        if (!confirm('Envoyer le re\u00e7u fiscal \u00e0 ' + donation.email + ' ?')) return;
        var origText = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Envoi\u2026';

        fetch('api-receipt.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ donation_id: parseInt(donation.id, 10), action: 'send' })
        })
        .then(function(r) { return r.json().then(function(j) { return { ok: r.ok, body: j }; }); })
        .then(function(res) {
            if (res.ok && res.body.success) {
                btn.textContent = 'Envoy\u00e9 \u2713';
                btn.className = 'btn btn-outline';
                btn.style.cssText = 'padding:4px 10px; font-size:12px; color:#2D7A3A;';
                // Reload list to update buttons
                setTimeout(function() { loadDonations(dlCurrentPage); }, 1500);
            } else {
                btn.disabled = false;
                btn.textContent = origText;
                alert(res.body.error || 'Erreur lors de l\u2019envoi.');
            }
        })
        .catch(function() {
            btn.disabled = false;
            btn.textContent = origText;
            alert('Erreur de connexion au serveur.');
        });
    }

    // ── Tab 2: Donations list ──

    var dlYear = document.getElementById('dl-year');
    var dlType = document.getElementById('dl-type');
    var dlSearch = document.getElementById('dl-search');
    var btnDlSearch = document.getElementById('btn-dl-search');
    var dlStats = document.getElementById('dl-stats');
    var dlLoading = document.getElementById('dl-loading');
    var dlError = document.getElementById('dl-error');
    var dlTable = document.getElementById('dl-table');
    var dlTbody = document.getElementById('dl-tbody');
    var dlPagination = document.getElementById('dl-pagination');
    var dlPageInfo = document.getElementById('dl-page-info');
    var dlPrev = document.getElementById('dl-prev');
    var dlNext = document.getElementById('dl-next');
    var dlCurrentPage = 1;

    // Populate year select
    for (var y2 = currentYear; y2 >= 2022; y2--) {
        var opt2 = document.createElement('option');
        opt2.value = y2;
        opt2.textContent = y2;
        if (y2 === currentYear) opt2.selected = true;
        dlYear.appendChild(opt2);
    }

    function loadDonations(page) {
        dlCurrentPage = page || 1;
        hideMsg(dlError);
        dlLoading.style.display = 'block';
        dlTable.style.display = 'none';
        dlPagination.style.display = 'none';
        dlStats.style.display = 'none';

        var params = 'year=' + encodeURIComponent(dlYear.value)
            + '&page=' + dlCurrentPage;
        if (dlType.value) params += '&type=' + encodeURIComponent(dlType.value);
        if (dlSearch.value.trim()) params += '&q=' + encodeURIComponent(dlSearch.value.trim());

        fetch('api-donations.php?' + params)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                dlLoading.style.display = 'none';
                if (data.error) {
                    showMsg(dlError, data.error);
                    return;
                }
                renderDonationsList(data);
            })
            .catch(function() {
                dlLoading.style.display = 'none';
                showMsg(dlError, 'Erreur de connexion au serveur.');
            });
    }

    function renderDonationsList(data) {
        var donations = data.donations || [];

        // Stats
        dlStats.textContent = data.stats.nb + ' don' + (data.stats.nb > 1 ? 's' : '')
            + ' \u00b7 ' + data.stats.total.toFixed(2).replace('.', ',') + ' \u20ac total'
            + ' \u00b7 Page ' + data.page + '/' + data.pages
            + ' (' + data.total + ' r\u00e9sultat' + (data.total > 1 ? 's' : '') + ')';
        dlStats.style.display = 'flex';

        // Table
        dlTbody.innerHTML = '';
        if (donations.length === 0) {
            var tr = document.createElement('tr');
            var td = document.createElement('td');
            td.colSpan = 9;
            td.textContent = 'Aucun don trouv\u00e9.';
            td.style.textAlign = 'center';
            td.style.padding = '20px';
            td.style.color = '#888';
            tr.appendChild(td);
            dlTbody.appendChild(tr);
        } else {
            donations.forEach(function(d) {
                var tr = document.createElement('tr');

                var fields = [
                    { val: d.date_don || '' },
                    { val: d.nom || '' },
                    { val: d.prenom || '' },
                    { val: d.email || '\u2014', style: 'font-size:12px;color:#666;' },
                    { val: parseFloat(d.amount).toFixed(2).replace('.', ',') + ' \u20ac', style: 'font-weight:500;' },
                    { val: d.type || '' },
                    { val: d.mode_paiement || '' },
                    { val: d.source || '' },
                ];

                fields.forEach(function(f) {
                    var td = document.createElement('td');
                    td.textContent = f.val;
                    if (f.style) td.style.cssText = f.style;
                    tr.appendChild(td);
                });

                // Actions column
                var tdAction = document.createElement('td');
                tdAction.style.cssText = 'white-space:nowrap;';
                if (d.receipt_number) {
                    var btnView = document.createElement('a');
                    btnView.className = 'btn btn-outline';
                    btnView.style.cssText = 'padding:4px 10px; font-size:12px; text-decoration:none;';
                    btnView.textContent = 'Voir le re\u00e7u';
                    btnView.href = 'api-receipt.php?action=download&donation_id=' + d.id;
                    btnView.target = '_blank';
                    tdAction.appendChild(btnView);
                } else {
                    // Send receipt button (only if email)
                    if (d.email) {
                        var btnSend = document.createElement('button');
                        btnSend.className = 'btn btn-primary';
                        btnSend.style.cssText = 'padding:4px 10px; font-size:12px; margin-right:4px;';
                        btnSend.textContent = 'Envoyer le re\u00e7u';
                        btnSend.addEventListener('click', (function(donation) {
                            return function(e) { sendReceiptFromList(donation, e.target); };
                        })(d));
                        tdAction.appendChild(btnSend);
                    }
                    // Edit icon button
                    var btnEdit = document.createElement('button');
                    btnEdit.className = 'btn btn-yellow';
                    btnEdit.style.cssText = 'padding:4px 8px; font-size:14px; line-height:1;';
                    btnEdit.title = 'Modifier';
                    btnEdit.innerHTML = '&#9998;';
                    btnEdit.addEventListener('click', (function(donation) {
                        return function() { editFromList(donation); };
                    })(d));
                    tdAction.appendChild(btnEdit);
                }
                tr.appendChild(tdAction);

                dlTbody.appendChild(tr);
            });
        }
        dlTable.style.display = 'table';

        // Pagination
        dlPagination.style.display = (data.pages > 1) ? 'flex' : 'none';
        dlPageInfo.textContent = 'Page ' + data.page + ' / ' + data.pages;
        dlPrev.disabled = data.page <= 1;
        dlNext.disabled = data.page >= data.pages;
    }

    btnDlSearch.addEventListener('click', function() { loadDonations(1); });
    dlYear.addEventListener('change', function() { loadDonations(1); });
    dlType.addEventListener('change', function() { loadDonations(1); });
    dlSearch.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') loadDonations(1);
    });
    dlPrev.addEventListener('click', function() {
        if (dlCurrentPage > 1) loadDonations(dlCurrentPage - 1);
    });
    dlNext.addEventListener('click', function() {
        loadDonations(dlCurrentPage + 1);
    });

    // Reload donations list every time the tab is clicked
    document.querySelector('[data-tab="tab-donations-list"]').addEventListener('click', function() {
        loadDonations(1);
    });

    // ── Tab 4: Export ──

    var exportYear = document.getElementById('export-year');
    var btnExportDonations = document.getElementById('btn-export-donations');

    // Populate year select
    for (var y3 = currentYear; y3 >= 2022; y3--) {
        var opt3 = document.createElement('option');
        opt3.value = y3;
        opt3.textContent = y3;
        exportYear.appendChild(opt3);
    }

    function updateExportLink() {
        var url = 'api-export.php?table=donations';
        if (exportYear.value) url += '&year=' + encodeURIComponent(exportYear.value);
        btnExportDonations.href = url;
    }

    exportYear.addEventListener('change', updateExportLink);
    updateExportLink();

})();
</script>

</body>
</html>
