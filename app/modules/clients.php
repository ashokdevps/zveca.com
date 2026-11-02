<?php

crud([
    'route' => 'clients', 'table' => 'hrm_clients', 'title' => 'Clients', 'singular' => 'Client', 'icon' => 'ri-shake-hands-line',
    'can_view' => 'manage_accounts', 'can_manage' => 'manage_accounts', 'order' => 'name ASC',
    'fields' => [
        'name'           => ['label' => 'Company / client name', 'required' => true, 'list' => true, 'search' => true, 'col' => 'col-md-8'],
        'status'         => ['label' => 'Status', 'type' => 'select', 'options' => ['Prospect', 'Active', 'Inactive'], 'default' => 'Active', 'list' => true, 'filter' => true, 'badge' => true, 'col' => 'col-md-4'],
        'contact_person' => ['label' => 'Contact person', 'list' => true, 'search' => true, 'col' => 'col-md-4'],
        'email'          => ['label' => 'E-mail', 'type' => 'email', 'list' => true, 'search' => true, 'col' => 'col-md-4'],
        'phone'          => ['label' => 'Phone', 'type' => 'tel', 'list' => true, 'col' => 'col-md-4'],
        'gst_no'         => ['label' => 'GSTIN', 'col' => 'col-md-4', 'maxlength' => 15, 'placeholder' => '22AAAAA0000A1Z5'],
        'state'          => ['label' => 'State (place of supply)', 'type' => 'select', 'options' => INDIAN_STATES, 'col' => 'col-md-4', 'help' => 'Decides CGST + SGST or IGST on invoices.'],
        'address'        => ['label' => 'Address', 'type' => 'textarea', 'rows' => 2, 'col' => 'col-md-8'],
        'account_manager_id' => ['label' => 'Business development owner', 'type' => 'relation', 'table' => 'it_login', 'label_col' => 'username', 'where' => "status = 'active' AND client_id IS NULL AND vendor_id IS NULL AND LOWER(usertype) NOT IN ('client','vendor','candidate')", 'placeholder' => '— Not assigned —', 'col' => 'col-md-4', 'help' => 'Business development users see and manage only the documents of the clients assigned to them.'],
        'offer_letter_access' => ['label' => 'Offer letters in client portal', 'type' => 'select', 'options' => ['1' => 'Granted (HR + recruitment package)', '0' => 'Not granted'], 'placeholder' => 'Automatic: with an active recruitment engagement', 'col' => 'col-md-4', 'help' => 'Shows offer letters of candidates hired for this client in its Document Centre (read-only).'],
    ],
    'before_save' => function (array $d) {
        if ($d['gst_no']) {
            $d['gst_no'] = strtoupper(preg_replace('/\s+/', '', $d['gst_no']));
            if (!preg_match(GSTIN_PATTERN, $d['gst_no'])) throw new RuntimeException('GSTIN should be 15 characters, e.g. 09AAACA1234A1Z5.');
            $d['state'] = $d['state'] ?: gstin_state($d['gst_no']); // the first two digits are the state code
        }
        return $d;
    },
    'view' => function (array $c, array $maps) {
        $projects = all('SELECT * FROM hrm_projects WHERE client_id = ? ORDER BY created_at DESC', [$c['id']]);
        $inv = all('SELECT * FROM hrm_invoices WHERE client_id = ? ORDER BY issue_date DESC', [$c['id']]);
        $billed = array_sum(array_column($inv, 'total'));
        $paid = array_sum(array_column($inv, 'paid'));
        ?>
        <div class="grid grid-3 mb-3">
          <?= stat_card('Projects', count($projects), 'ri-folder-chart-line', 'primary') ?>
          <?= stat_card('Total billed', money($billed), 'ri-file-list-3-line', 'info') ?>
          <?= stat_card('Outstanding', money($billed - $paid), 'ri-time-line', $billed - $paid > 0 ? 'warning' : 'success', money($paid) . ' received') ?>
        </div>
        <div class="grid grid-3">
          <div class="card"><div class="card-header-lite">Contact</div><div class="card-body"><dl class="detail-grid" style="grid-template-columns:1fr">
            <div><dt>Contact person</dt><dd><?= e($c['contact_person'] ?: '—') ?></dd></div>
            <div><dt>E-mail</dt><dd><?= $c['email'] ? '<a href="mailto:' . e($c['email']) . '">' . e($c['email']) . '</a>' : '—' ?></dd></div>
            <div><dt>Phone</dt><dd><?= e($c['phone'] ?: '—') ?></dd></div>
            <div><dt>GSTIN</dt><dd><?= e($c['gst_no'] ?: '—') ?></dd></div>
            <div><dt>State</dt><dd><?= e($c['state'] ?? '' ?: '—') ?></dd></div>
            <div><dt>Address</dt><dd><?= nl2br(e($c['address'] ?: '—')) ?></dd></div>
          </dl></div></div>
          <div class="card"><div class="card-header-lite">Projects <a class="small fw-normal" href="<?= url('projects', ['a' => 'create']) ?>">+ New</a></div>
            <?php foreach ($projects as $p): ?><div class="list-row"><div class="grow"><a class="title" href="<?= url('projects', ['a' => 'view', 'id' => $p['id']]) ?>"><?= e($p['name']) ?></a><div class="sub">Due <?= fdate($p['deadline']) ?></div></div><?= badge($p['status']) ?></div><?php endforeach; ?>
            <?php if (!$projects): ?><div class="card-body text-muted small">No projects.</div><?php endif; ?>
          </div>
          <div class="card"><div class="card-header-lite">Invoices <a class="small fw-normal" href="<?= url('invoices', ['a' => 'create', 'client_id' => $c['id']]) ?>">+ New</a></div>
            <?php foreach ($inv as $i): ?><div class="list-row"><div class="grow"><a class="title" href="<?= url('invoices', ['a' => 'view', 'id' => $i['id']]) ?>"><?= e($i['invoice_no']) ?></a><div class="sub"><?= fdate($i['issue_date']) ?> · <?= money($i['total']) ?></div></div><?= badge($i['status']) ?></div><?php endforeach; ?>
            <?php if (!$inv): ?><div class="card-body text-muted small">No invoices.</div><?php endif; ?>
          </div>
        </div>
        <?php $logins = all('SELECT id, username, email, status, last_login, permissions, usertype, client_scope, is_signatory FROM it_login WHERE client_id = ? ORDER BY username', [$c['id']]);
        $engs = plan_allows('manage_engagements') ? all('SELECT * FROM hrm_engagements WHERE client_id = ? AND status <> "Draft" ORDER BY id DESC', [$c['id']]) : []; ?>
        <?php if (can_any('manage_engagements', 'bd_documents') && table_exists('hrm_document_events')): $dc = one("SELECT COUNT(*) n, COALESCE(SUM(status IN ('Awaiting Signature','Partially Signed')), 0) open_n, COALESCE(SUM(status = 'Signed'), 0) signed_n FROM hrm_client_documents WHERE client_id = ? AND is_current = 1 AND kind NOT IN ('offer_letter') AND status <> 'Void'", [$c['id']]); ?>
        <div class="card mt-3"><div class="card-header-lite"><span><i class="ri-file-shield-2-line text-primary me-1"></i>Agreements &amp; documents</span><a class="btn btn-sm btn-light border" href="<?= url('documents', ['client_id' => $c['id']]) ?>">Open</a></div>
          <div class="card-body small"><?= (int) $dc['n'] ?> document(s) · <?= (int) $dc['open_n'] ?> out for signature · <?= (int) $dc['signed_n'] ?> signed · offer letters in portal: <strong><?= client_offer_access((int) $c['id']) ? 'yes' : 'no' ?></strong><?= $c['offer_letter_access'] === null ? ' (automatic)' : '' ?></div></div>
        <?php endif; ?>
        <?php if ($engs): ?>
        <div class="card mt-3"><div class="card-header-lite"><span><i class="ri-service-line text-primary me-1"></i>Service requests</span></div>
          <?php foreach ($engs as $en): $en['form_data'] = json_decode((string) $en['form'], true) ?: []; ?><a class="list-row text-body" href="<?= url('engagements', ['a' => 'view', 'id' => $en['id']]) ?>"><div class="grow"><div class="title"><?= e(eng_title($en)) ?></div><div class="sub"><?= e($en['ref']) ?> · <?= e(ENG_SERVICES[$en['service']]['label']) ?></div></div><?= badge($en['status']) ?></a><?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="card mt-3"><div class="card-header-lite"><span><i class="ri-shield-keyhole-line text-primary me-1"></i>Client portal logins</span>
          <?php if (can('manage_users')): ?><a class="btn btn-sm btn-primary" href="<?= url('users', ['a' => 'create', 'role' => 'client', 'client_id' => $c['id']]) ?>"><i class="ri-user-add-line me-1"></i>Create portal login</a><?php endif; ?></div>
          <?php foreach ($logins as $l): ?>
            <div class="list-row"><?= avatar($l['username'], null, 'sm') ?><div class="grow"><div class="title"><?= e($l['username']) ?></div>
              <div class="sub"><?= e($l['email'] ?? '') ?> · last sign-in <?= $l['last_login'] ? time_ago($l['last_login']) : 'never' ?></div>
              <div class="mt-1"><?= !empty($l['is_signatory']) ? '<span class="pill pill-success">Signatory</span> ' : '' ?><span class="tag"><?= e(['hr' => 'HR only', 'it' => 'IT only'][$l['client_scope'] ?? ''] ?? 'HR & IT') ?></span><?php foreach (permissions_for($l) as $p): ?><span class="tag"><?= e(PERMISSIONS[$p][0]) ?></span><?php endforeach; ?></div></div>
              <?= badge($l['status']) ?>
              <?php if (can('manage_users')): ?><a class="btn btn-sm btn-light border" href="<?= url('users', ['a' => 'edit', 'id' => $l['id']]) ?>#access">Manage access</a><?php endif; ?></div>
          <?php endforeach; ?>
          <?php if (!$logins): ?><div class="card-body text-muted small">No portal logins yet. <?= can('manage_users') ? 'Create one so this client can track projects, invoices and hiring online.' : 'Ask the Super Admin to create one.' ?></div><?php endif; ?>
        </div>
        <?php
    },
]);
