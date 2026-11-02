<?php
/**
 * Agreement & Document Management (extends hrm_client_documents / hrm_client_signatures from client onboarding).
 *
 *  - 12 document types × 4 service categories, linked to a client OR a vendor, an engagement, a candidate / offer.
 *  - Word upload (.docx editable in the browser; .doc kept as-is and converted to .docx on request, never in place),
 *    browser editing, versions (a sent or signed version is never changed — edits create a new version), previews,
 *    downloads (original file or a .docx built from the current text), private storage.
 *  - Lifecycle: Draft → In Review → Approved Internally → Awaiting Signature (shown as "Sent for signature" / "Viewed")
 *    → Partially Signed → Signed; Declined, Change Requested, Expired, Superseded, Cancelled.
 *  - Signing: the recipient (client signatory or vendor login) verifies an e-mail OTP and signs on the exact text whose
 *    SHA-256 is stored; methods are recorded distinctly (OTP + drawn mark, internal drawn signature, external DSC / Aadhaar
 *    eSign upload). Nothing here is presented as a certificate-based digital signature.
 *  - Access is checked on the server for every action: Super Admin / Admin (client services), Business Development
 *    (assigned clients only), vendors (their own shared documents), clients (read-only shared documents).
 *  - Offer letters: each offer sent from HR Onboarding is recorded once per revision as an immutable snapshot for the
 *    candidate's client (job → client), visible only with the HR / recruitment entitlement.
 */

const DOC_TYPES = [
    'msa' => 'Master Service Agreement (MSA)', 'nda' => 'Non-Disclosure Agreement (NDA)', 'recruitment' => 'Recruitment & Staffing Agreement',
    'payroll' => 'Payroll & HR Outsourcing Agreement', 'it_dev' => 'IT Development Agreement', 'digital_marketing' => 'Digital Marketing Services Agreement',
    'dpa' => 'Data Processing Agreement (DPA)', 'commercial' => 'Commercial Terms & Payment Annexure', 'sla' => 'SLA & Support Terms',
    'ip' => 'IP Ownership & Licensing Terms', 'sow' => 'Service-specific SOW / Work Order', 'termination' => 'Termination, Exit & Handover Terms',
];
const DOC_CATEGORIES = ['recruitment' => 'Recruitment', 'hr_payroll' => 'HR Payroll', 'it_dev' => 'IT Software Design and Development', 'digital_marketing' => 'Digital Marketing'];
/** Documents issued by the client-onboarding workflow (before v22) → type / category. */
const DOC_LEGACY_TYPES = ['nda' => 'nda', 'hr_agreement' => 'recruitment', 'it_agreement' => 'msa', 'sop' => 'sow', 'proposal' => 'commercial'];
/** Template kinds (Client onboarding → Templates) a new document can start from. */
const DOC_TYPE_TEMPLATES = ['nda' => 'client_nda', 'recruitment' => 'client_hr_agreement', 'msa' => 'client_it_agreement', 'sow' => 'client_sop', 'commercial' => 'client_proposal'];
const DOC_INTERNAL = ['Draft', 'In Review', 'Approved Internally'];            // never visible to clients / vendors (unless vendor-owned)
const DOC_EDITABLE = ['Draft', 'In Review', 'Approved Internally', 'Change Requested', 'Declined'];
const DOC_OPEN_SIGN = ['Awaiting Signature', 'Partially Signed'];
const DOC_METHOD_LABELS = ['otp_draw' => 'Electronic signature (e-mail OTP + drawn mark)', 'internal_draw' => 'Internal authorised signature (drawn mark)',
    'external' => 'Executed outside the portal (DSC / Aadhaar eSign / wet ink, uploaded)', 'company_auto' => 'Company countersignature'];

/* ───────────────────────── Labels ───────────────────────── */

function doc_type_label(array $d): string
{
    if (($d['kind'] ?? '') === 'offer_letter') return 'Offer Letter';
    $t = $d['doc_type'] ?: (DOC_LEGACY_TYPES[$d['kind']] ?? null);
    return $t ? (DOC_TYPES[$t] ?? $t) : (ENG_DOC_KINDS[$d['kind']]['label'] ?? 'Document');
}

function doc_category(array $d): ?string
{
    if ($d['service_category']) return $d['service_category'];
    $svc = $d['eng_service'] ?? $d['service'] ?? null;
    return $svc === 'hr' ? 'recruitment' : ($svc === 'it' ? 'it_dev' : null);
}

/** What the status means to people ("Awaiting Signature" is shown as Sent for signature / Viewed). */
function doc_status_label(array $d): string
{
    if ($d['status'] === 'Awaiting Signature') return $d['viewed_at'] ? 'Viewed' : 'Sent for Signature';
    return $d['status'] === 'Void' ? 'Cancelled' : $d['status'];
}

function doc_status_pill(array $d): string
{
    $l = doc_status_label($d);
    $tone = ['Signed' => 'success', 'Shared' => 'success', 'Sent for Signature' => 'info', 'Viewed' => 'info', 'Partially Signed' => 'warning', 'Approved Internally' => 'success',
        'In Review' => 'warning', 'Change Requested' => 'warning', 'Declined' => 'danger', 'Expired' => 'danger', 'Cancelled' => 'neutral', 'Superseded' => 'neutral', 'Draft' => 'neutral'][$l] ?? 'neutral';
    return '<span class="pill pill-' . $tone . '">' . e($l) . '</span>';
}

/* ───────────────────────── Roles & access ───────────────────────── */

/** admin (Super Admin) · staff (client services / Admin) · bd (Business Development) · vendor · client · null */
function doc_role(): ?string
{
    if (!user()) return null;
    if (is_super_admin()) return 'admin';
    if (is_client()) return can('client_services') ? 'client' : null;
    if (is_vendor()) return can('vendor_documents') ? 'vendor' : null;
    if (can('manage_engagements')) return 'staff';
    if (can('bd_documents')) return 'bd';
    return null;
}

/** SQL condition limiting documents to what the internal user may see (BD: assigned clients / engagements / own). */
function doc_staff_scope(string $alias = 'd'): array
{
    $role = doc_role();
    if (in_array($role, ['admin', 'staff'], true)) return ['1=1', []];
    if ($role === 'bd') {
        $u = (int) user()['id'];
        return ["($alias.created_by = ? OR $alias.client_id IN (SELECT id FROM hrm_clients WHERE account_manager_id = ?) OR $alias.engagement_id IN (SELECT id FROM hrm_engagements WHERE owner_id = ?))", [$u, $u, $u]];
    }
    return ['0=1', []];
}

function doc_find(int $id): ?array
{
    return one('SELECT d.*, c.name client_name, v.name vendor_name, e.ref, e.title eng_title, e.service eng_service, e.owner_id eng_owner, c.account_manager_id,
                       u.username creator, m.username modifier FROM hrm_client_documents d LEFT JOIN hrm_clients c ON c.id = d.client_id LEFT JOIN hrm_vendors v ON v.id = d.vendor_id
                LEFT JOIN hrm_engagements e ON e.id = d.engagement_id LEFT JOIN it_login u ON u.id = d.created_by LEFT JOIN it_login m ON m.id = d.last_modified_by WHERE d.id = ?', [$id]);
}

/** Access was revoked for this organisation (explicit grants exist and none is active). */
function doc_revoked(array $d, ?int $clientId, ?int $vendorId): bool
{
    $col = $clientId ? 'client_id' : 'vendor_id';
    $id = $clientId ?: $vendorId;
    $rows = all("SELECT revoked_at FROM hrm_document_access WHERE document_id = ? AND $col = ? AND permission = 'view'", [$d['id'], $id]);
    return $rows && !array_filter($rows, fn($r) => $r['revoked_at'] === null);
}

/** Was this vendor document created by the vendor (a login of that agency)? */
function doc_vendor_owned(array $d): bool
{
    return $d['vendor_id'] && (int) val('SELECT vendor_id FROM it_login WHERE id = ?', [(int) $d['created_by']]) === (int) $d['vendor_id'];
}

/**
 * May the current user do $act on the document? view · edit · meta · version · send · sign_internal · approve · review · cancel ·
 * revoke · download · history · sign (as recipient) · respond (decline / request changes).
 */
function doc_can(array $d, string $act): bool
{
    $role = doc_role();
    if (!$role) return false;
    $status = $d['status'];
    $isOffer = $d['kind'] === 'offer_letter';
    if (in_array($role, ['admin', 'staff', 'bd'], true)) {
        if ($role === 'bd') {
            [$w, $p] = doc_staff_scope('d');
            if (!val("SELECT 1 FROM hrm_client_documents d WHERE d.id = ? AND $w", [$d['id'], ...$p])) return false;
        }
        if ($isOffer) return in_array($act, ['view', 'download', 'history'], true); // offer letters are managed in HR Onboarding
        return match ($act) {
            'view', 'download', 'history' => true,
            'edit' => in_array($status, DOC_EDITABLE, true) || in_array($status, ['Awaiting Signature', 'Partially Signed', 'Expired'], true), // saving a sent text creates a new version
            'meta' => in_array($status, DOC_EDITABLE, true),
            'version' => !in_array($status, ['Cancelled', 'Void', 'Superseded'], true),
            'send' => in_array($status, DOC_EDITABLE, true) && $d['content'] !== null && (setting('doc_require_approval', '0') !== '1' || $status === 'Approved Internally'),
            'review' => $status === 'Draft',
            'approve' => in_array($status, ['Draft', 'In Review'], true) && ($role === 'admin' || can('approve_engagements')),
            'sign_internal' => !in_array($status, ['Signed', 'Cancelled', 'Void', 'Superseded', 'Expired'], true) && $role !== 'bd' || ($role === 'bd' && in_array($status, DOC_EDITABLE, true)),
            'cancel', 'revoke' => $role !== 'bd' && !in_array($status, ['Signed', 'Cancelled', 'Void', 'Superseded'], true),
            default => false,
        };
    }
    if ($role === 'vendor') {
        if (!$d['vendor_id'] || (int) $d['vendor_id'] !== my_vendor_id() || doc_revoked($d, null, my_vendor_id())) return false;
        $own = doc_vendor_owned($d);
        if (in_array($status, DOC_INTERNAL, true) && !$own) return false;
        return match ($act) {
            'view' => !in_array($status, ['Cancelled', 'Void'], true) || $own,
            'download' => (bool) $d['allow_download'] || $own,
            'edit', 'version' => ($own || $d['vendor_editable']) && in_array($status, ['Draft', 'Change Requested', 'Declined'], true),
            'review' => $own && $status === 'Draft',
            'sign' => in_array($status, DOC_OPEN_SIGN, true) && doc_open($d) && (!$d['recipient_login_id'] || (int) $d['recipient_login_id'] === (int) user()['id']),
            'respond' => $status === 'Awaiting Signature' && doc_open($d) && (bool) $d['allow_changes'] && !$own,
            default => false,
        };
    }
    // client: strictly read-only, shared documents of its own company within its service scope.
    if (!$d['client_id'] || (int) $d['client_id'] !== my_client_id() || !eng_doc_in_scope($d) || doc_revoked($d, my_client_id(), null)) return false;
    if (in_array($status, array_merge(DOC_INTERNAL, ['Cancelled', 'Void']), true)) return false;
    if ($status === 'Superseded' && !$d['sent_at'] && !$isOffer && $d['source'] !== null) return false; // never-shared internal versions
    if ($isOffer && !client_offer_access((int) $d['client_id'])) return false;
    return match ($act) {
        'view' => true,
        'download' => !$isOffer && ((bool) $d['allow_download'] || $status === 'Signed'), // offer letters: view only
        'sign' => !$isOffer && in_array($status, DOC_OPEN_SIGN, true) && doc_open($d) && is_client_signatory() && (!$d['recipient_login_id'] || (int) $d['recipient_login_id'] === (int) user()['id'])
            && !val("SELECT 1 FROM hrm_client_signatures WHERE document_id = ? AND party = 'client'", [$d['id']]),
        'respond' => !$isOffer && $status === 'Awaiting Signature' && doc_open($d) && (bool) $d['allow_changes'] && is_client_signatory(),
        default => false,
    };
}

/** The signing request has not expired (the expiry tick may not have run yet) and the text is intact. */
function doc_open(array $d): bool
{
    if ($d['expires_at'] && strtotime($d['expires_at']) <= time()) return false;
    return $d['content'] === null || !$d['content_hash'] || hash_equals((string) $d['content_hash'], hash('sha256', (string) $d['content']));
}

function doc_require(array $d, string $act): void
{
    if (!doc_can($d, $act)) authorize('__never__');
}

/* ───────────────────────── Audit & notifications ───────────────────────── */

const DOC_EVENT_LABELS = ['created' => 'Created', 'uploaded' => 'Word document uploaded', 'edited' => 'Content edited', 'meta' => 'Details changed', 'version' => 'New version created',
    'review' => 'Submitted for internal review', 'approved' => 'Approved internally', 'signed_internal' => 'Signed by authorised signatory (internal)', 'sent' => 'Sent for signature',
    'shared' => 'Shared', 'viewed' => 'Opened by recipient', 'signed' => 'Signed by recipient', 'declined' => 'Declined by recipient', 'changes' => 'Changes requested by recipient',
    'expired' => 'Signing request expired', 'superseded' => 'Superseded by a newer version', 'cancelled' => 'Cancelled', 'revoked' => 'Access revoked', 'granted' => 'Access granted',
    'downloaded' => 'Downloaded', 'converted' => 'Converted to .docx', 'external' => 'Executed copy uploaded', 'offer_shared' => 'Offer letter shared with client'];

function doc_event(int $docId, string $event, string $detail = '', ?string $party = null): void
{
    $party ??= is_client() ? 'client' : (is_vendor() ? 'vendor' : (user() ? 'staff' : 'system'));
    insert('hrm_document_events', ['document_id' => $docId, 'event' => $event, 'detail' => $detail !== '' ? mb_substr($detail, 0, 500) : null,
        'login_id' => user()['id'] ?? null, 'party' => $party, 'ip' => PHP_SAPI === 'cli' ? null : client_ip()]);
}

/** Sends a notification once per key (retries and repeated events never send twice). */
function doc_notify_once(string $key, array $to, string $subject, string $body): bool
{
    $to = array_values(array_unique(array_filter(array_map(fn($x) => strtolower(trim((string) $x)), $to), fn($x) => filter_var($x, FILTER_VALIDATE_EMAIL))));
    if (!$to) return false;
    try { insert('hrm_notification_log', ['k' => mb_substr($key, 0, 190)]); } catch (PDOException $e) { return false; }
    foreach ($to as $addr) {
        try { send_mail($addr, $subject, "Hello,\n\n$body\n\n— " . setting('company_name', 'Adorable IT Services')); } catch (Throwable $ex) { error_log('Document mail: ' . $ex->getMessage()); }
    }
    return true;
}

/** Internal people to tell about a document (engagement owner, document creator, client-services mailbox). */
function doc_staff_emails(array $d): array
{
    return array_filter([(string) val('SELECT email FROM it_login WHERE id = ?', [(int) ($d['eng_owner'] ?? 0)]), (string) val('SELECT email FROM it_login WHERE id = ?', [(int) $d['created_by']]),
        setting('eng_email') ?: setting('company_email')]);
}

function doc_portal_link(array $d): string
{
    return site_origin() . ($d['vendor_id'] && !$d['client_id'] ? url('vendor', ['a' => 'document', 'id' => $d['id']]) : url('services', ['a' => 'doc', 'id' => $d['id']]));
}

/* ───────────────────────── Word documents ───────────────────────── */

/** Server-side Word validation beyond the extension: a real DOCX package without macros, or an OLE (.doc) file. */
function doc_word_error(string $path, string $ext): ?string
{
    $head = (string) file_get_contents($path, false, null, 0, 8);
    if ($ext === 'doc') return str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") ? null : 'This is not a genuine Word 97–2003 (.doc) file.';
    if (!str_starts_with($head, "PK\x03\x04") || !class_exists('ZipArchive')) return 'This is not a genuine Word (.docx) file.';
    $z = new ZipArchive();
    if ($z->open($path) !== true) return 'The Word file is damaged and could not be opened.';
    $hasDoc = $z->locateName('word/document.xml') !== false;
    $macros = $z->locateName('word/vbaProject.bin') !== false;
    $size = 0;
    for ($i = 0; $i < $z->numFiles; $i++) $size += (int) ($z->statIndex($i)['size'] ?? 0);
    $z->close();
    if (!$hasDoc) return 'This file is not a Word document (word/document.xml is missing).';
    if ($macros) return 'Word files with macros are not accepted. Save it as a normal .docx and upload again.';
    if ($size > 200 * 1024 * 1024) return 'The Word file expands to an unsafe size and was rejected.';
    return null;
}

/** DOCX → safe HTML keeping headings, bold / italic / underline, lists, line breaks and tables. */
function docx_to_html(string $path): string
{
    $z = new ZipArchive();
    if ($z->open($path) !== true) return '';
    $xml = $z->getFromName('word/document.xml');
    $z->close();
    if ($xml === false) return '';
    $dom = new DOMDocument();
    if (!@$dom->loadXML($xml, LIBXML_NONET)) return '';
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
    $runs = function (DOMNode $p) use ($xp): string {
        $out = '';
        foreach ($xp->query('.//w:r', $p) as $r) {
            $t = '';
            foreach ($r->childNodes as $c) {
                if ($c->localName === 't') $t .= e($c->textContent);
                elseif ($c->localName === 'tab') $t .= ' &nbsp; ';
                elseif ($c->localName === 'br') $t .= '<br>';
            }
            if ($t === '') continue;
            $on = fn(string $tag) => ($n = $xp->query("w:rPr/w:$tag", $r)->item(0)) && !in_array($n->getAttribute('w:val'), ['0', 'false', 'none'], true);
            if ($on('u')) $t = "<u>$t</u>";
            if ($on('i')) $t = "<em>$t</em>";
            if ($on('b')) $t = "<strong>$t</strong>";
            $out .= $t;
        }
        return $out;
    };
    $html = '';
    $inList = false;
    $body = $xp->query('/w:document/w:body')->item(0);
    foreach ($body ? $body->childNodes : [] as $n) {
        if ($n->localName === 'tbl') {
            if ($inList) { $html .= '</ul>'; $inList = false; }
            $html .= '<table>';
            foreach ($xp->query('w:tr', $n) as $tr) {
                $html .= '<tr>';
                foreach ($xp->query('w:tc', $tr) as $tc) {
                    $cell = [];
                    foreach ($xp->query('w:p', $tc) as $cp) $cell[] = $runs($cp);
                    $html .= '<td>' . implode('<br>', array_filter($cell, fn($x) => $x !== '')) . '</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</table>';
            continue;
        }
        if ($n->localName !== 'p') continue;
        $text = $runs($n);
        $style = ($s = $xp->query('w:pPr/w:pStyle', $n)->item(0)) ? strtolower($s->getAttribute('w:val')) : '';
        $isList = $xp->query('w:pPr/w:numPr', $n)->length > 0 || str_contains($style, 'list');
        if ($isList) {
            if (!$inList) { $html .= '<ul>'; $inList = true; }
            $html .= '<li>' . $text . '</li>';
            continue;
        }
        if ($inList) { $html .= '</ul>'; $inList = false; }
        if ($text === '') continue;
        $tag = match (true) { $style === 'title' => 'h2', str_starts_with($style, 'heading1') => 'h2', str_starts_with($style, 'heading2') => 'h3', str_starts_with($style, 'heading') => 'h4', default => 'p' };
        $html .= "<$tag>$text</$tag>";
    }
    if ($inList) $html .= '</ul>';
    return doc_clean_html($html);
}

/** Allow-list sanitiser for document HTML (from Word or the editor). Unknown tags are unwrapped, attributes dropped. */
function doc_clean_html(string $html): string
{
    $allowed = ['p', 'h2', 'h3', 'h4', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li', 'br', 'table', 'thead', 'tbody', 'tr', 'td', 'th', 'blockquote', 'hr'];
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?><div id="root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
    $root = $dom->getElementById('root');
    if (!$root) return '';
    $walk = function (DOMNode $node) use (&$walk, $allowed, $dom) {
        foreach (iterator_to_array($node->childNodes) as $c) {
            if ($c instanceof DOMElement) {
                $tag = strtolower($c->tagName);
                if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'textarea', 'select', 'button', 'link', 'meta', 'svg', 'img', 'video', 'audio'], true)) { $node->removeChild($c); continue; }
                $walk($c);
                if (!in_array($tag, $allowed, true)) {
                    while ($c->firstChild) $node->insertBefore($c->firstChild, $c);
                    $node->removeChild($c);
                    continue;
                }
                foreach (iterator_to_array($c->attributes) as $at) if (!in_array($at->name, ['colspan', 'rowspan'], true) || !ctype_digit($at->value)) $c->removeAttribute($at->name);
            } elseif ($c instanceof DOMComment) {
                $node->removeChild($c);
            }
        }
    };
    $walk($root);
    $out = '';
    foreach ($root->childNodes as $c) $out .= $dom->saveHTML($c);
    return trim(preg_replace('/<p>\s*(<br>)?\s*<\/p>/', '', $out));
}

/** Plain-text preview of a legacy .doc (read-only until converted to .docx). */
function doc_binary_preview(string $path): string
{
    require_once APP_DIR . '/lib/resume.php';
    $t = trim(doc_text($path));
    return $t === '' ? '' : '<p>' . implode('</p><p>', array_map('e', array_filter(array_map('trim', preg_split('/\n{1,}/', $t))))) . '</p>';
}

/** HTML (document content) → a .docx file (headings, paragraphs, bold / italic / underline, lists, tables). */
function html_to_docx(string $html, string $title = ''): string
{
    $esc = fn(string $s) => htmlspecialchars(html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?><div id="root">' . doc_clean_html($html) . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
    $runs = function (DOMNode $n, array $fmt = []) use (&$runs, $esc): string {
        $out = '';
        foreach ($n->childNodes as $c) {
            if ($c instanceof DOMText) {
                if ($c->textContent === '') continue;
                $pr = (in_array('b', $fmt, true) ? '<w:b/>' : '') . (in_array('i', $fmt, true) ? '<w:i/>' : '') . (in_array('u', $fmt, true) ? '<w:u w:val="single"/>' : '');
                $out .= '<w:r>' . ($pr ? "<w:rPr>$pr</w:rPr>" : '') . '<w:t xml:space="preserve">' . $esc($c->textContent) . '</w:t></w:r>';
            } elseif ($c instanceof DOMElement) {
                $t = strtolower($c->tagName);
                if ($t === 'br') { $out .= '<w:r><w:br/></w:r>'; continue; }
                $f = $fmt;
                if (in_array($t, ['strong', 'b'], true)) $f[] = 'b';
                if (in_array($t, ['em', 'i'], true)) $f[] = 'i';
                if ($t === 'u') $f[] = 'u';
                $out .= $runs($c, $f);
            }
        }
        return $out;
    };
    $para = fn(string $inner, string $style = '') => '<w:p>' . ($style ? '<w:pPr><w:pStyle w:val="' . $style . '"/></w:pPr>' : '') . $inner . '</w:p>';
    $body = $title !== '' ? $para('<w:r><w:t xml:space="preserve">' . $esc($title) . '</w:t></w:r>', 'Title') : '';
    $root = $dom->getElementById('root');
    foreach ($root ? $root->childNodes : [] as $n) {
        if (!$n instanceof DOMElement) { if (trim($n->textContent) !== '') $body .= $para('<w:r><w:t xml:space="preserve">' . $esc($n->textContent) . '</w:t></w:r>'); continue; }
        $t = strtolower($n->tagName);
        if (in_array($t, ['ul', 'ol'], true)) {
            $i = 0;
            foreach ($n->childNodes as $li) if ($li instanceof DOMElement) $body .= $para('<w:r><w:t xml:space="preserve">' . ($t === 'ol' ? (++$i) . '. ' : '• ') . '</w:t></w:r>' . $runs($li), 'ListParagraph');
        } elseif ($t === 'table') {
            $rows = '';
            foreach ($n->getElementsByTagName('tr') as $tr) {
                $cells = '';
                foreach ($tr->childNodes as $td) if ($td instanceof DOMElement) $cells .= '<w:tc><w:tcPr><w:tcW w:w="0" w:type="auto"/></w:tcPr>' . $para($runs($td)) . '</w:tc>';
                $rows .= "<w:tr>$cells</w:tr>";
            }
            $body .= '<w:tbl><w:tblPr><w:tblStyle w:val="TableGrid"/><w:tblW w:w="0" w:type="auto"/><w:tblBorders><w:top w:val="single" w:sz="4"/><w:left w:val="single" w:sz="4"/><w:bottom w:val="single" w:sz="4"/><w:right w:val="single" w:sz="4"/><w:insideH w:val="single" w:sz="4"/><w:insideV w:val="single" w:sz="4"/></w:tblBorders></w:tblPr>' . $rows . '</w:tbl>';
        } elseif ($t === 'hr') {
            $body .= $para('');
        } else {
            $body .= $para($runs($n), ['h2' => 'Heading1', 'h3' => 'Heading2', 'h4' => 'Heading3', 'blockquote' => 'Quote'][$t] ?? '');
        }
    }
    $tmp = tempnam(sys_get_temp_dir(), 'docx');
    $z = new ZipArchive();
    $z->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/></Types>');
    $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
    $z->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $st = fn(string $id, string $name, string $sz, bool $bold = true) => '<w:style w:type="paragraph" w:styleId="' . $id . '"><w:name w:val="' . $name . '"/><w:basedOn w:val="Normal"/><w:pPr><w:spacing w:before="240" w:after="120"/></w:pPr><w:rPr>' . ($bold ? '<w:b/>' : '') . '<w:sz w:val="' . $sz . '"/></w:rPr></w:style>';
    $z->addFromString('word/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:sz w:val="22"/></w:rPr></w:rPrDefault><w:pPrDefault><w:pPr><w:spacing w:after="120"/></w:pPr></w:pPrDefault></w:docDefaults><w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style>'
        . $st('Title', 'Title', '36') . $st('Heading1', 'heading 1', '30') . $st('Heading2', 'heading 2', '26') . $st('Heading3', 'heading 3', '24') . $st('ListParagraph', 'List Paragraph', '22', false) . $st('Quote', 'Quote', '22', false)
        . '<w:style w:type="table" w:styleId="TableGrid"><w:name w:val="Table Grid"/></w:style></w:styles>');
    $z->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . $body
        . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134" w:header="708" w:footer="708" w:gutter="0"/></w:sectPr></w:body></w:document>');
    $z->close();
    $bin = (string) file_get_contents($tmp);
    @unlink($tmp);
    return $bin;
}

/** Stores an uploaded Word file (private storage) after all checks. Returns [path, ext, html, original name, sha256]. */
function doc_store_word(string $field): array
{
    $f = $_FILES[$field] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) throw new RuntimeException('Choose a Word document (.docx or .doc).');
    $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['docx', 'doc'], true)) throw new RuntimeException('Only Word documents (.docx or .doc) can be uploaded here.');
    if ($f['error'] === UPLOAD_ERR_OK && ($err = doc_word_error($f['tmp_name'], $ext))) throw new RuntimeException($err);
    $path = store_upload($field, 'agreements', max(1, (int) setting('doc_max_mb', 10)));
    $abs = STORAGE_DIR . '/uploads/' . $path;
    $html = $ext === 'docx' ? docx_to_html($abs) : doc_binary_preview($abs);
    return [$path, $ext, $html, mb_substr(basename(str_replace('\\', '/', (string) $f['name'])), 0, 190), hash_file('sha256', $abs)];
}

/* ───────────────────────── Lifecycle ───────────────────────── */

/** Creates a document (upload, template or blank). $d: title, doc_type, service_category, client_id | vendor_id, engagement_id, content, file … */
function doc_create(array $d, string $event = 'created', string $detail = ''): int
{
    if (!isset(DOC_TYPES[$d['doc_type'] ?? ''])) throw new RuntimeException('Choose a document type.');
    if (!isset(DOC_CATEGORIES[$d['service_category'] ?? ''])) throw new RuntimeException('Choose a service category.');
    if (empty($d['client_id']) && empty($d['vendor_id'])) throw new RuntimeException('Choose the client or vendor this document is for.');
    $content = $d['content'] ?? null;
    $id = insert('hrm_client_documents', $d + ['kind' => 'managed', 'version' => 1, 'status' => 'Draft', 'requires_signature' => 1, 'content_hash' => $content !== null ? hash('sha256', $content) : null,
        'is_current' => 1, 'created_by' => user()['id'] ?? null, 'last_modified_by' => user()['id'] ?? null, 'updated_at' => date('Y-m-d H:i:s')]);
    doc_event($id, $event, $detail);
    log_activity('document_' . $event, 'document', $id, mb_substr($d['title'], 0, 150));
    return $id;
}

/**
 * Saves edited content. Draft-like documents change in place; a document that was sent (or is being signed) gets a new
 * version and the open signing request is invalidated. Signed documents are never changed. Returns the document id that holds the text.
 */
function doc_save_content(array $d, string $html, string $note = ''): int
{
    $html = doc_clean_html($html);
    if ($html === '') throw new RuntimeException('The document is empty.');
    if (in_array($d['status'], ['Signed', 'Cancelled', 'Void', 'Superseded'], true)) throw new RuntimeException('This version is locked. Create a new version to make changes.');
    // Anything the recipient has seen stays as it was: edits after sending (also after a decline / change request) become a new version.
    if (in_array($d['status'], ['Awaiting Signature', 'Partially Signed', 'Expired'], true) || ($d['sent_at'] && in_array($d['status'], ['Declined', 'Change Requested'], true))
        || val('SELECT 1 FROM hrm_client_signatures WHERE document_id = ?', [$d['id']])) { // a signed text is never changed in place
        if ($html === $d['content']) throw new RuntimeException('Nothing changed.');
        return doc_new_version($d, ['content' => $html], $note ?: 'Edited after it was sent');
    }
    $status = $d['status'] === 'Approved Internally' && setting('doc_require_approval', '0') === '1' ? 'Draft' : ($d['status'] === 'Approved Internally' ? 'Approved Internally' : (in_array($d['status'], ['Change Requested', 'Declined'], true) ? 'Draft' : $d['status']));
    update('hrm_client_documents', ['content' => $html, 'content_hash' => hash('sha256', $html), 'last_modified_by' => user()['id'] ?? null, 'updated_at' => date('Y-m-d H:i:s'), 'status' => $status,
        'approved_by' => $status === 'Draft' ? null : $d['approved_by'], 'approved_at' => $status === 'Draft' ? null : $d['approved_at']], (int) $d['id']);
    doc_event((int) $d['id'], 'edited', $note !== '' ? $note : 'Version ' . $d['version']);
    return (int) $d['id'];
}

/** New version (v+1) as a Draft; the previous one is superseded unless it was executed (signed copies stay as they are). */
function doc_new_version(array $d, array $changes, string $note = ''): int
{
    $copy = array_intersect_key($d, array_flip(['client_id', 'vendor_id', 'engagement_id', 'candidate_id', 'kind', 'service', 'doc_type', 'service_category', 'title', 'content', 'file', 'original_name',
        'file_format', 'file_hash', 'source', 'requires_signature', 'allow_download', 'allow_changes', 'countersign', 'vendor_editable', 'template_version']));
    $copy = array_merge($copy, $changes, ['version' => (int) $d['version'] + 1,
        'status' => 'Draft', 'supersedes_id' => $d['id'], 'is_current' => 1, 'created_by' => user()['id'] ?? null, 'last_modified_by' => user()['id'] ?? null, 'updated_at' => date('Y-m-d H:i:s')]);
    $copy['content_hash'] = isset($copy['content']) ? hash('sha256', (string) $copy['content']) : null;
    $id = insert('hrm_client_documents', $copy);
    $old = in_array($d['status'], ['Signed', 'Partially Signed'], true) ? $d['status'] : 'Superseded';
    q('UPDATE hrm_client_documents SET superseded_by = ?, is_current = 0, status = ? WHERE id = ?', [$id, $old, $d['id']]);
    if ($old === 'Superseded') q('UPDATE hrm_document_access SET revoked_at = NOW(), revoked_by = ? WHERE document_id = ? AND revoked_at IS NULL AND permission = \'sign\'', [user()['id'] ?? null, $d['id']]);
    doc_event((int) $d['id'], 'superseded', 'Replaced by version ' . $copy['version'] . ($note !== '' ? ": $note" : ''));
    doc_event($id, 'version', 'Version ' . $copy['version'] . ' created from version ' . $d['version'] . ($note !== '' ? ": $note" : ''));
    return $id;
}

/** Verified recipients for a document: client signatories / active vendor logins of that organisation. */
function doc_recipients(array $d): array
{
    if ($d['client_id']) return all("SELECT id, username, email, designation FROM it_login WHERE client_id = ? AND LOWER(usertype) = 'client' AND status = 'active' AND is_signatory = 1 AND email IS NOT NULL ORDER BY username", [$d['client_id']]);
    if ($d['vendor_id']) return all("SELECT id, username, email, designation FROM it_login WHERE vendor_id = ? AND LOWER(usertype) = 'vendor' AND status = 'active' AND email IS NOT NULL ORDER BY username", [$d['vendor_id']]);
    return [];
}

/** Sends the current text for signature to a verified recipient of the right organisation. */
function doc_send(array $d, int $recipientLogin, int $days, bool $download, bool $changes, bool $countersign): void
{
    $rec = null;
    foreach (doc_recipients($d) as $r) if ((int) $r['id'] === $recipientLogin) $rec = $r;
    if (!$rec) throw new RuntimeException($d['client_id'] ? 'Choose an authorised signatory of this client with a verified e-mail.' : 'Choose an active login of this vendor.');
    if ($d['content'] === null || trim(strip_tags((string) $d['content'])) === '') throw new RuntimeException('The document has no readable content to sign. Convert or edit it first.');
    $hash = hash('sha256', (string) $d['content']);
    $days = max(1, min(90, $days));
    update('hrm_client_documents', ['status' => 'Awaiting Signature', 'content_hash' => $hash, 'sent_by' => user()['id'], 'sent_at' => date('Y-m-d H:i:s'), 'viewed_at' => null,
        'expires_at' => date('Y-m-d H:i:s', strtotime("+$days days")), 'recipient_login_id' => $rec['id'], 'recipient_email' => $rec['email'], 'allow_download' => $download ? 1 : 0,
        'allow_changes' => $changes ? 1 : 0, 'countersign' => $countersign ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')], (int) $d['id']);
    foreach (['view', 'sign'] as $perm) insert('hrm_document_access', ['document_id' => $d['id'], 'client_id' => $d['client_id'] ?: null, 'vendor_id' => $d['client_id'] ? null : $d['vendor_id'], 'login_id' => $rec['id'], 'permission' => $perm, 'granted_by' => user()['id']]);
    doc_event((int) $d['id'], 'sent', 'To ' . $rec['email'] . " · expires in $days days · SHA-256 " . substr($hash, 0, 16) . '…');
    $d = doc_find((int) $d['id']);
    $revised = $d['supersedes_id'] ? ' This is version ' . $d['version'] . '; the earlier signing request is no longer valid.' : '';
    doc_notify_once("docsent:{$d['id']}:{$d['version']}:" . date('YmdHi'), [$rec['email']], 'Signature requested: ' . $d['title'], 'Please review and sign "' . $d['title'] . '" (version ' . $d['version'] . ').' . $revised
        . "\n\nOpen it securely (sign in first): " . doc_portal_link($d) . "\n\nThe request expires on " . fdate($d['expires_at'], 'd M Y') . '.');
}

/** Records a party's signature result on the document status (client / vendor signed → Signed or Partially Signed). */
function doc_after_party_sign(array $d): void
{
    $sigs = array_column(all('SELECT party FROM hrm_client_signatures WHERE document_id = ?', [$d['id']]), 'party');
    $external = array_intersect($sigs, ['client', 'vendor']);
    $needsCompany = (int) ($d['countersign'] ?? 0) === 1 && !in_array('company', $sigs, true);
    $status = $external && !$needsCompany ? 'Signed' : ($external || in_array('company', $sigs, true) ? ($external ? 'Partially Signed' : $d['status']) : $d['status']);
    update('hrm_client_documents', ['status' => $status, 'signed_at' => $status === 'Signed' ? date('Y-m-d H:i:s') : $d['signed_at'], 'updated_at' => date('Y-m-d H:i:s')], (int) $d['id']);
    if ($status === 'Signed' && $d['kind'] === 'managed') {
        notify(array_merge([(int) $d['created_by']], logins_with('manage_engagements', false)), 'agreement', 'Signed: ' . $d['title'], 'Signed by all parties (version ' . $d['version'] . ').', url('documents', ['a' => 'view', 'id' => $d['id']]), 'ri-quill-pen-line', 'success', "docsigned:{$d['id']}");
        doc_notify_once("docsigned:{$d['id']}", doc_staff_emails(doc_find((int) $d['id'])), 'Signed: ' . $d['title'], '"' . $d['title'] . '" (version ' . $d['version'] . ') has been signed by all parties. The executed copy is locked in the document workspace.');
    }
}

function doc_store_sig_record(array $d, string $party, string $name, string $desig, string $email, string $method, ?string $sigFile, ?string $otpAt = null): void
{
    insert('hrm_client_signatures', ['document_id' => $d['id'], 'party' => $party, 'login_id' => user()['id'] ?? null, 'signer_name' => mb_substr($name, 0, 150), 'signer_designation' => mb_substr($desig, 0, 120) ?: null,
        'signer_email' => $email ?: null, 'method' => $method, 'otp_verified_at' => $otpAt, 'signature_file' => $sigFile, 'content_hash' => $d['content_hash'], 'ip' => client_ip(),
        'user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), 'signed_at' => date('Y-m-d H:i:s')]);
}

/** First open by the recipient. */
function doc_mark_viewed(array $d): void
{
    if ($d['status'] === 'Awaiting Signature' && !$d['viewed_at'] && (is_client() || is_vendor())) {
        q('UPDATE hrm_client_documents SET viewed_at = NOW() WHERE id = ? AND viewed_at IS NULL', [$d['id']]);
        doc_event((int) $d['id'], 'viewed');
        if ($d['kind'] === 'managed') doc_notify_once("docviewed:{$d['id']}:{$d['version']}", doc_staff_emails($d), 'Viewed: ' . $d['title'], '"' . $d['title'] . '" was opened by ' . (user()['username'] ?? 'the recipient') . '.');
    }
}

/** Decline / request changes by the recipient. */
function doc_respond(array $d, string $kind, string $note): void
{
    $status = $kind === 'decline' ? 'Declined' : 'Change Requested';
    update('hrm_client_documents', ['status' => $status, 'review_note' => mb_substr($note, 0, 500), 'updated_at' => date('Y-m-d H:i:s')], (int) $d['id']);
    q("UPDATE hrm_document_access SET revoked_at = NOW() WHERE document_id = ? AND permission = 'sign' AND revoked_at IS NULL", [$d['id']]);
    doc_event((int) $d['id'], $kind === 'decline' ? 'declined' : 'changes', $note);
    notify(array_merge([(int) $d['created_by']], logins_with('manage_engagements', false)), 'agreement', "$status: {$d['title']}", $note, url('documents', ['a' => 'view', 'id' => $d['id']]), 'ri-file-shield-2-line', $kind === 'decline' ? 'danger' : 'warning', "docresp:{$d['id']}:{$d['version']}:$kind");
    doc_notify_once("docresp:{$d['id']}:{$d['version']}:$kind", doc_staff_emails($d), "$status: " . $d['title'], (user()['username'] ?? 'The recipient') . ' ' . ($kind === 'decline' ? 'declined' : 'requested changes to') . ' "' . $d['title'] . '" (version ' . $d['version'] . ').' . "\n\nNote: $note");
}

/** Expired signing requests (page loads at most every 30 minutes; also app/cron.php). */
function doc_tick(bool $force = false): int
{
    if (!$force) {
        if (time() - (int) setting('doc_tick_at', 0) < 1800) return 0;
        set_setting('doc_tick_at', time());
    }
    $n = 0;
    foreach (all("SELECT * FROM hrm_client_documents WHERE status IN ('Awaiting Signature','Partially Signed') AND expires_at IS NOT NULL AND expires_at < NOW() AND kind = 'managed' LIMIT 200") as $d) {
        q("UPDATE hrm_client_documents SET status = 'Expired' WHERE id = ?", [$d['id']]);
        q("UPDATE hrm_document_access SET revoked_at = NOW() WHERE document_id = ? AND permission = 'sign' AND revoked_at IS NULL", [$d['id']]);
        doc_event((int) $d['id'], 'expired', 'Not signed by ' . fdate($d['expires_at'], 'd M Y'), 'system');
        doc_notify_once("docexp:{$d['id']}:{$d['version']}", doc_staff_emails(doc_find((int) $d['id'])), 'Signing request expired: ' . $d['title'], '"' . $d['title'] . '" was not signed before ' . fdate($d['expires_at'], 'd M Y') . '. Send it again or create a new version.');
        $n++;
    }
    return $n;
}

/* ───────────────────────── Offer letters (HR Onboarding → client) ───────────────────────── */

/** Client of an offer: the job's client (stable ids, never matched by name). 0 = internal hire. */
function offer_client_id(array $o): int
{
    $job = $o['job_id'] ?: val('SELECT job_id FROM hrm_candidates WHERE id = ?', [(int) $o['candidate_id']]);
    return $job ? (int) val('SELECT COALESCE(client_id, 0) FROM hrm_jobs WHERE id = ?', [$job]) : 0;
}

/**
 * HR + recruitment entitlement: explicitly granted / revoked on the client record, otherwise automatic for clients
 * with an activated recruitment (HR) engagement.
 */
function client_offer_access(int $clientId): bool
{
    $flag = val('SELECT offer_letter_access FROM hrm_clients WHERE id = ?', [$clientId]);
    if ($flag !== null && $flag !== false) return (int) $flag === 1;
    return (bool) val("SELECT 1 FROM hrm_engagements WHERE client_id = ? AND service = 'hr' AND status IN ('Active','Delivered','Closed') LIMIT 1", [$clientId]);
}

/** Keeps the client's copy of an offer in step with HR Onboarding (called from onb_save on every status change). */
function offer_share_sync(int $onbId): void
{
    if (!table_exists('hrm_document_events')) return;
    $o = onb_find($onbId);
    if (!$o) return;
    $cid = offer_client_id($o);
    if (!$cid) return;
    $current = one("SELECT * FROM hrm_client_documents WHERE onboarding_id = ? AND kind = 'offer_letter' AND is_current = 1 ORDER BY id DESC LIMIT 1", [$onbId]);
    if ($o['status'] === 'Offer Sent' && $o['offer_html'] && (!$current || (int) $current['version'] !== (int) $o['revision'])) {
        $html = onb_letter('Offer letter', (string) $o['offer_html'], $o);
        $id = insert('hrm_client_documents', ['client_id' => $cid, 'engagement_id' => val('SELECT id FROM hrm_engagements WHERE job_id = ? ORDER BY id DESC LIMIT 1', [(int) $o['job_id']]) ?: null,
            'candidate_id' => $o['candidate_id'], 'onboarding_id' => $onbId, 'kind' => 'offer_letter', 'service' => 'hr', 'doc_type' => null, 'service_category' => 'recruitment', 'source' => 'offer',
            'title' => mb_substr('Offer letter — ' . $o['full_name'] . ($o['offer_ref'] ? ' (' . $o['offer_ref'] . ')' : ''), 0, 190), 'version' => (int) $o['revision'], 'content' => $html,
            'content_hash' => hash('sha256', $html), 'status' => 'Shared', 'requires_signature' => 0, 'allow_download' => 0, 'allow_changes' => 0, 'sent_at' => date('Y-m-d H:i:s'),
            'supersedes_id' => $current['id'] ?? null, 'is_current' => 1, 'created_by' => user()['id'] ?? null, 'updated_at' => date('Y-m-d H:i:s')]);
        if ($current) q("UPDATE hrm_client_documents SET is_current = 0, superseded_by = ?, status = 'Superseded' WHERE id = ?", [$id, $current['id']]);
        doc_event($id, 'offer_shared', 'Offer ' . $o['offer_ref'] . ' revision ' . $o['revision'] . ' · offer hash ' . substr((string) $o['offer_hash'], 0, 16) . '…', 'system');
        if (client_offer_access($cid)) offer_notify_client($cid, "offershared:$onbId:{$o['revision']}", 'Offer letter shared: ' . $o['full_name'], 'An offer letter for ' . $o['full_name'] . ' (' . ($o['des'] ?: $o['job_title'] ?: 'role') . ') has been shared with your company. View it in the Document Centre → Offer letters.');
        return;
    }
    if (!$current) return;
    if ($o['status'] === 'Accepted' && client_offer_access($cid)) offer_notify_client($cid, "offeracc:$onbId", 'Offer accepted: ' . $o['full_name'], $o['full_name'] . ' accepted the offer' . ($o['joining_date'] ? '; planned joining date ' . fdate($o['joining_date'], 'd M Y') : '') . '.');
    if ($o['status'] === 'Joined' && client_offer_access($cid)) offer_notify_client($cid, "offerjoin:$onbId", 'Candidate joined: ' . $o['full_name'], $o['full_name'] . ' has joined' . ($o['joined_on'] ? ' on ' . fdate($o['joined_on'], 'd M Y') : '') . '.');
}

function offer_notify_client(int $clientId, string $key, string $subject, string $body): void
{
    $to = array_merge([(string) val('SELECT email FROM hrm_clients WHERE id = ?', [$clientId])],
        array_column(all("SELECT email FROM it_login WHERE client_id = ? AND status = 'active' AND (is_signatory = 1 OR client_scope IS NULL OR client_scope = 'hr')", [$clientId]), 'email'));
    doc_notify_once($key, $to, $subject, $body . "\n\nClient portal: " . site_origin() . url('services', ['a' => 'documents', 'tab' => 'offers']));
}

/** Offer status from the authoritative onboarding record (never invented). */
function offer_status(array $r): string
{
    if ($r['doc_status'] === 'Superseded') return 'Superseded';
    $joined = $r['joined_on'] ?: $r['emp_joined'];
    return match (true) {
        $r['status'] === 'Joined' || ($joined && $r['status'] === 'Activated') => 'Joined',
        $r['status'] === 'Withdrawn' => 'Withdrawn',
        $r['status'] === 'Declined' => 'Declined',
        $r['status'] === 'Expired' => 'Expired',
        in_array($r['status'], ['Accepted', 'Submitted', 'Correction Requested', 'Ready to Join', 'Activated'], true) => ($r['joining_date'] && $r['joining_date'] < date('Y-m-d') && !$joined ? 'Not Joined' : 'Accepted'),
        $r['status'] === 'Offer Sent' => $r['email_verified_at'] ? 'Viewed' : 'Shared',
        default => 'Generated',
    };
}

/** Current shared offer letters of a client (one row per offer; superseded revisions excluded). */
function offer_rows(int $clientId): array
{
    $rows = all("SELECT d.id doc_id, d.version, d.sent_at, d.status doc_status, o.id onb_id, o.status, o.full_name, o.work_location, o.joining_date, o.joined_on, o.email_verified_at,
                        o.accepted_at, c.name cand_name, COALESCE(g.title, j.title) designation, emp.join_date emp_joined
                 FROM hrm_client_documents d JOIN hrm_onboardings o ON o.id = d.onboarding_id LEFT JOIN hrm_candidates c ON c.id = o.candidate_id
                 LEFT JOIN hrm_designations g ON g.id = o.designation_id LEFT JOIN hrm_jobs j ON j.id = o.job_id LEFT JOIN hrm_employees emp ON emp.id = o.employee_id
                 WHERE d.client_id = ? AND d.kind = 'offer_letter' AND d.is_current = 1 ORDER BY d.sent_at DESC", [$clientId]);
    foreach ($rows as &$r) $r['offer_status'] = offer_status($r);
    return $rows;
}

/* ───────────────────────── Offers sent before v22 (optional data update) ───────────────────────── */

/** Onboarding ids of offers already sent to candidates of client jobs that have no client copy yet. */
function offer_backfill_rows(): array
{
    if (!table_exists('hrm_document_events')) return [];
    return array_map('intval', array_column(all("SELECT o.id FROM hrm_onboardings o JOIN hrm_jobs j ON j.id = o.job_id
        WHERE j.client_id IS NOT NULL AND o.offer_html IS NOT NULL AND o.sent_at IS NOT NULL AND o.status NOT IN ('Draft','Pending Approval','Changes Requested','Approved')
          AND NOT EXISTS (SELECT 1 FROM hrm_client_documents d WHERE d.onboarding_id = o.id AND d.kind = 'offer_letter')"), 'id'));
}

/** Records the current offer text for the client (same snapshot as when an offer is sent), without changing the onboarding record. */
function offer_share_sync_force(int $onbId): void
{
    $o = onb_find($onbId);
    $cid = $o ? offer_client_id($o) : 0;
    if (!$o || !$cid || !$o['offer_html'] || val("SELECT 1 FROM hrm_client_documents WHERE onboarding_id = ? AND kind = 'offer_letter'", [$onbId])) return;
    $html = onb_letter('Offer letter', (string) $o['offer_html'], $o);
    $id = insert('hrm_client_documents', ['client_id' => $cid, 'candidate_id' => $o['candidate_id'], 'onboarding_id' => $onbId, 'kind' => 'offer_letter', 'service' => 'hr', 'service_category' => 'recruitment', 'source' => 'offer',
        'title' => mb_substr('Offer letter — ' . $o['full_name'] . ($o['offer_ref'] ? ' (' . $o['offer_ref'] . ')' : ''), 0, 190), 'version' => (int) $o['revision'], 'content' => $html,
        'content_hash' => hash('sha256', $html), 'status' => 'Shared', 'requires_signature' => 0, 'allow_download' => 0, 'allow_changes' => 0, 'sent_at' => $o['sent_at'], 'is_current' => 1, 'updated_at' => date('Y-m-d H:i:s')]);
    doc_event($id, 'offer_shared', 'Recorded from an offer sent on ' . fdate($o['sent_at'], 'd M Y') . ' (data update)', 'system');
}
