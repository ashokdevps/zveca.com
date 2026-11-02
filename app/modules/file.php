<?php
/** Streams an uploaded file after checking the user may see it. */

$path = (string) ($_GET['f'] ?? '');
if (!preg_match('#^(resumes|photos|documents|receipts|logos|attachments|policies|faces|onboarding|signatures|clientdocs|agreements|site)/[A-Za-z0-9 _().\-]+$#', $path)) {
    not_found();
}
[$dir] = explode('/', $path, 2);
$file = STORAGE_DIR . '/uploads/' . $path;
if (!is_file($file)) {
    not_found();
}

$me = my_employee_id();
$allowed = match ($dir) {
    'photos', 'logos', 'site' => true, // public images (website images are published on the home page)
    'resumes'   => can('manage_recruitment')
        // Clients: resumes of candidates in their own jobs. Profiles they sourced from Resdex only after a paid CV download;
        // Resdex CVs themselves are served by HireFlow through a signed, short-lived link (never by path).
        || (is_client() && can('client_hiring') && (bool) val("SELECT 1 FROM hrm_candidates c JOIN hrm_jobs j ON j.id = c.job_id WHERE c.resume = ? AND j.client_id = ? AND c.stage IN " . sql_list(STAGES_SHORTLISTED)
            . " AND (COALESCE(c.source, '') <> 'Resdex' OR EXISTS (SELECT 1 FROM hrm_rdx_cv_downloads d WHERE d.client_id = j.client_id AND d.candidate_id = COALESCE(c.master_id, c.id)))", [$path, my_client_id()]))
        || (is_client() && can('client_pipeline') && (bool) val("SELECT 1 FROM hrm_candidates c JOIN hrm_jobs j ON j.id = c.job_id WHERE c.resume = ? AND j.client_id = ?
            AND (COALESCE(c.source, '') <> 'Resdex' OR EXISTS (SELECT 1 FROM hrm_rdx_cv_downloads d WHERE d.client_id = j.client_id AND d.candidate_id = COALESCE(c.master_id, c.id)))", [$path, my_client_id()]))
        || (is_vendor() && (bool) val('SELECT 1 FROM hrm_candidates WHERE resume = ? AND vendor_id = ?', [$path, my_vendor_id()]))
        || (is_candidate() && (bool) val('SELECT 1 FROM hrm_talent WHERE resume = ? AND id = ?', [$path, my_talent_id()])),
    'attachments' => (bool) val('SELECT 1 FROM hrm_ticket_messages m JOIN hrm_tickets t ON t.id = m.ticket_id WHERE m.attachment = ? AND (m.internal = 0 OR ?) AND (t.requester_id = ? OR ?)',
        [$path, is_agent() ? 1 : 0, user()['id'], is_agent() ? 1 : 0]),
    'documents' => can('view_employees') || (bool) val('SELECT 1 FROM hrm_employee_documents WHERE file = ? AND employee_id = ?', [$path, $me ?? 0]),
    'receipts'  => can('approve_expenses') || (bool) val('SELECT 1 FROM hrm_expenses WHERE receipt = ? AND employee_id = ?', [$path, $me ?? 0]),
    'policies'  => can_any('view_policies', 'manage_policies') && (bool) val("SELECT 1 FROM hrm_policies WHERE attachment = ? AND (status = 'Published' OR ?)", [$path, can('manage_policies') ? 1 : 0]),
    // Face snapshots: the employee themselves and attendance managers (enrolment photo + photo taken at each face punch).
    'faces'     => can('manage_attendance') || ($me && (str_starts_with(basename($path), "e{$me}_"))),
    // Onboarding uploads / e-signatures: onboarding staff, the candidate (their own case), and the employee file once activated.
    // Client documents, request attachments and client e-signatures: client-services staff and accounts, or a login of that client (service scope respected).
    // Word agreements: only through the document permission model (same checks as the document pages).
    'agreements' => ($ad = one('SELECT * FROM hrm_client_documents WHERE file = ? ORDER BY id DESC LIMIT 1', [$path])) && doc_can(doc_find((int) $ad['id']), 'download'),
    'clientdocs' => can_any('manage_engagements', 'approve_engagements', 'manage_accounts') || (is_client() && client_file_allowed($path)),
    'onboarding', 'signatures' => can_any('manage_onboarding', 'approve_offers')
        || (bool) val($dir === 'onboarding' ? 'SELECT 1 FROM hrm_onboarding_documents d JOIN hrm_onboardings o ON o.id = d.onboarding_id WHERE d.file = ? AND o.login_id = ?'
            : 'SELECT 1 FROM hrm_onboarding_signatures s JOIN hrm_onboardings o ON o.id = s.onboarding_id WHERE s.signature_file = ? AND o.login_id = ?', [$path, user()['id']])
        || ($dir === 'onboarding' && (can('view_employees') || (bool) val('SELECT 1 FROM hrm_employee_documents WHERE file = ? AND employee_id = ?', [$path, $me ?? 0]))),
};
if (!$allowed) {
    authorize('__never__');
}

$types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif', 'svg' => 'image/svg+xml'];
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$inline = isset($types[$ext]) && $ext !== 'svg';

// Send the file byte-for-byte: drop anything already buffered (a stray byte would corrupt an image) and switch off
// PHP-level compression, which would otherwise contradict Content-Length on hosts that enable it.
while (ob_get_level() > 0) ob_end_clean();
if (ini_get('zlib.output_compression')) ini_set('zlib.output_compression', '0');
session_write_close(); // don't hold the session lock while a large file downloads

// Unchanged files are answered with 304, so photos are not downloaded again on every page.
$mtime = filemtime($file);
$etag = '"' . md5($path . $mtime . filesize($file)) . '"';
header_remove('Pragma');
header_remove('Expires');
header('Cache-Control: private, max-age=86400');
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($file));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . basename($file) . '"');
header('X-Content-Type-Options: nosniff');
readfile($file);
exit;
