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
        <button class="tab-btn" data-tab="tab-receipts">Re&ccedil;us annuels</button>
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
        </div>
    </div>

    <!-- Tab 2: Recus annuels -->
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
        document.getElementById('f-code_postal').value = d.code_postal || '';
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

    // Form submit
    var form = document.getElementById('donation-form');
    var msgSuccess = document.getElementById('msg-success');
    var msgError = document.getElementById('msg-error');
    var btnSave = document.getElementById('btn-save');

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

        fetch('api-save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        })
        .then(function(r) { return r.json().then(function(j) { return { ok: r.ok, body: j }; }); })
        .then(function(res) {
            setLoading(btnSave, false);
            if (res.ok && res.body.success) {
                showMsg(msgSuccess, 'Don enregistr\u00e9 avec succ\u00e8s.');
                form.reset();
                dateField.value = new Date().toISOString().slice(0, 10);
            } else {
                showMsg(msgError, res.body.error || 'Erreur inconnue.');
            }
        })
        .catch(function() {
            setLoading(btnSave, false);
            showMsg(msgError, 'Erreur de connexion au serveur.');
        });
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
            if (!confirm('Envoyer tous les re\u00e7us sur l\u2019adresse admin ?')) return;
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
                showMsg(sendSuccess, res.body.message || 'Envoi termin\u00e9.');
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

})();
</script>

</body>
</html>
