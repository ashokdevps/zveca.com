<?php
/**
 * Policy pages the payment gateway requires on the website: /terms, /privacy, /refund-policy, /delivery-policy.
 * Template wording — have it reviewed by your legal adviser before going live.
 */
require __DIR__ . '/app/bootstrap.php';

$co = setting('company_name', 'Zveca');
$email = setting('company_email', 'info@adorableit.com');
$addr = '1st Floor, B-135, B Block, Sector 2, Noida, Uttar Pradesh 201301, India';
$updated = '4 October 2026';
$days = 7;

$pages = [
    'terms' => ['Terms of service', [
        'Agreement' => "These terms govern your use of Zveca HRMS (the \"Service\"), a cloud HR software service provided by $co (\"we\", \"us\"). By creating an order, paying, or using the Service you agree to these terms on behalf of your organisation (\"Customer\").",
        'Subscription and users' => 'The Service is sold per active user per month on the Light, Standard or Pro plan, billed monthly or annually in advance. An active user is an employee record with an active status. Inactive employees, candidates, sub-vendor and client logins are not billed. Adding users or upgrading takes effect immediately and is charged pro-rata for the current period; downgrades take effect at the next renewal.',
        'Payment and taxes' => 'Prices are in Indian Rupees and exclude GST, which is charged at the applicable rate. Payments are processed by Razorpay. Subscriptions renew for the same period unless cancelled before the end of the current period; we send a renewal reminder before each renewal. If payment is not received within 15 days of the period end, access may be suspended until payment is made.',
        'Customer data' => 'You own all data you put into the Service. We process it only to provide the Service, as described in our privacy policy. You are responsible for the accuracy of the data, for obtaining any consents required from your employees, and for keeping login credentials confidential.',
        'Acceptable use' => 'You will not misuse the Service, attempt to access other customers\' data, probe or attack its security, upload malicious files, or use it for unlawful purposes. We may suspend accounts that put the Service or other customers at risk.',
        'Availability and support' => 'We aim for high availability and take daily backups, but the Service is provided on an "as is" basis. Planned maintenance is announced in advance where possible. Support is provided by e-mail (and chat or priority support where included in your plan) on Indian business days.',
        'Liability' => 'To the extent permitted by law, our total liability for any claim relating to the Service is limited to the fees you paid for the Service in the 12 months before the claim. We are not liable for indirect or consequential losses, such as loss of profits or data that could have been avoided by reasonable backups or exports.',
        'Termination' => 'You may cancel at any time; access continues until the end of the paid period. On termination you can export your data for 30 days, after which it is deleted from our systems except where retention is required by law.',
        'Governing law' => 'These terms are governed by the laws of India. Courts at Noida / Gautam Buddh Nagar, Uttar Pradesh have exclusive jurisdiction.',
    ]],
    'privacy' => ['Privacy policy', [
        'Who we are' => "$co, $addr, provides Zveca HRMS. For the employee data you store in the Service, your organisation is the data fiduciary and we act as its processor. For website visitors and buyers, we are the data fiduciary.",
        'What we collect' => 'Buyer details you give at checkout (company, name, e-mail, phone, billing address, GSTIN); data your organisation stores in the Service (employee profiles, attendance, leave, payroll, performance and documents); technical data needed to secure the Service (IP address, device and browser, sign-in times). Card and bank details are entered on Razorpay\'s secure page and are never stored by us.',
        'How we use it' => 'To provide and secure the Service, process payments and issue GST invoices, provide support, send service and billing e-mails, and comply with law. We do not sell personal data or use your organisation\'s HR data for advertising.',
        'Sharing' => 'With Razorpay to process payments, our hosting and e-mail providers to run the Service, and authorities where legally required. Each provider is bound to protect the data.',
        'Security' => 'Role-based access checked on every request, hashed passwords, login throttling, encrypted connections (HTTPS), private document storage, security headers and an audit log of important changes.',
        'Retention' => 'Customer data is kept while your subscription is active and deleted within 30 days after termination unless law requires longer retention (for example, invoices are kept for 8 years under GST rules).',
        'Your rights' => "Under the Digital Personal Data Protection Act, 2023 you may request access, correction or erasure of your personal data and withdraw consent. Employees should contact their employer first. Write to $email; we respond within 30 days.",
        'Cookies' => 'We use only essential cookies needed for sign-in and security (session and CSRF protection). We do not use advertising cookies.',
    ]],
    'refund-policy' => ['Refund & cancellation policy', [
        "$days-day money-back guarantee" => "If you are not satisfied with your first purchase of Zveca HRMS, write to $email within $days days of payment and we will refund the full amount, no questions asked. This applies once per organisation.",
        'Cancellation' => 'You can cancel your subscription at any time from your account or by e-mail. Cancellation stops the next renewal; your workspace stays available until the end of the period you have already paid for.',
        'Refunds after the guarantee period' => 'Payments made after the first ' . $days . ' days are non-refundable for the current billing period, except where we fail to provide the Service as described, in which case we refund the unused portion pro-rata.',
        'Duplicate or failed payments' => 'If you were charged twice or money was deducted for a failed payment, it is refunded automatically by Razorpay or your bank, usually within 5–7 working days. If not, contact us with your order number.',
        'How refunds are paid' => 'Approved refunds are made to the original payment method within 5–7 working days. A GST credit note is issued against the original invoice.',
    ]],
    'delivery-policy' => ['Delivery policy', [
        'Digital service' => 'Zveca HRMS is a cloud software service. Nothing is shipped physically.',
        'How the service is delivered' => 'After successful payment you receive an e-mail receipt and GST invoice immediately. Your workspace is set up and the administrator login is sent to the e-mail address given at checkout within one business day (usually the same day).',
        'Delays' => "If you have not received your login within one business day, contact $email or WhatsApp +91-9871864942 with your order number.",
    ]],
];
$page = $_GET['page'] ?? 'terms';
if (!isset($pages[$page])) {
    http_response_code(404);
    $page = 'terms';
}
[$title, $sections] = $pages[$page];

site_page_start("$title · Zveca HRMS", "$title for Zveca HRMS by $co.");
?>
<section class="legal"><div class="container">
  <nav class="legal-nav" aria-label="Policies"><?php foreach ($pages as $k => [$t]): ?><a href="<?= e(root_url($k)) ?>" class="<?= $k === $page ? 'active' : '' ?>"><?= e($t) ?></a><?php endforeach; ?></nav>
  <article>
    <h1><?= e($title) ?></h1>
    <p class="lp-muted">Last updated <?= $updated ?> · <?= e($co) ?></p>
    <?php foreach ($sections as $h => $p): ?><h2><?= e($h) ?></h2><p><?= e($p) ?></p><?php endforeach; ?>
    <h2>Contact</h2>
    <p><?= e($co) ?>, <?= e($addr) ?> · <a href="mailto:<?= e($email) ?>"><?= e($email) ?></a> · +91-9871864942</p>
  </article>
</div></section>
<?php
site_page_end();
