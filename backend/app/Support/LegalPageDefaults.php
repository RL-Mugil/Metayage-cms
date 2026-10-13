<?php

namespace App\Support;

/** Built-in text for the public legal pages, used until a system admin saves their own version. */
class LegalPageDefaults
{
    public static function privacy(): string
    {
        return <<<'HTML'
<h1>Privacy Policy</h1>
<p>Last updated: 13 October 2026</p>
<p>This policy describes how MyIPStrategy ("we", "us") handles personal data on myipstrategy.com and the client portal at login.myipstrategy.com.</p>

<h2>1. Information we collect</h2>
<ul>
  <li><strong>Enquiry form:</strong> your name, email address, organization (optional) and the message you write.</li>
  <li><strong>Client portal and staff accounts:</strong> account details, login activity and the matter, billing and HR information needed to deliver our services.</li>
  <li><strong>Technical data:</strong> IP address, browser type and request logs, kept for security and abuse prevention.</li>
  <li><strong>Analytics (only with your consent):</strong> anonymized usage data such as pages viewed and approximate region.</li>
</ul>

<h2>2. How we use it</h2>
<ul>
  <li>To reply to your enquiry and provide our intellectual property services.</li>
  <li>To secure the website and portal, and to prevent spam and fraud.</li>
  <li>To improve the website, where you have accepted analytics cookies.</li>
  <li>To meet legal, tax and professional obligations.</li>
</ul>

<h2>3. Cookies and similar technologies</h2>
<p>The marketing site stores a single item in your browser (your cookie choice). The client portal uses strictly necessary session and security cookies. Analytics cookies are set only if you click "Accept analytics", and you can change your choice at any time using "Cookie settings" in the footer.</p>

<h2>4. Service providers</h2>
<p>We use trusted providers to run the service, including Cloudflare (bot protection), Google (analytics, if you accept) and our hosting and email providers. They process data only on our instructions or under their own privacy terms.</p>

<h2>5. Retention and security</h2>
<p>Enquiries are kept only as long as needed to respond and for reasonable record keeping. Matter records are kept as long as required for the engagement and by law. We protect data with encryption in transit, access controls and encrypted storage of sensitive fields.</p>

<h2>6. Your rights</h2>
<p>Subject to applicable law, including India's Digital Personal Data Protection Act, 2023, you may ask to access, correct or erase your personal data, withdraw consent, or raise a grievance. Use the <a href="/#contact">contact form</a> and mention "Privacy request" so we can route it correctly.</p>

<h2>7. International transfers</h2>
<p>Some providers process data outside India. Where this happens, we rely on appropriate contractual safeguards.</p>

<h2>8. Changes</h2>
<p>We may update this policy and will change the date above when we do.</p>
HTML;
    }

    public static function terms(): string
    {
        return <<<'HTML'
<h1>Terms &amp; Conditions</h1>
<p>Last updated: 13 October 2026</p>
<p>These terms govern your use of myipstrategy.com and the client portal at login.myipstrategy.com. Legal services are provided under a separate engagement letter.</p>

<h2>1. Acceptance</h2>
<p>By using this website you agree to these terms. If you do not agree, please do not use it.</p>

<h2>2. No legal advice or attorney-client relationship</h2>
<p>Content on this website is general information, not legal advice. Sending a message through the contact form does not create an attorney-client relationship. Please do not send confidential or invention-specific information until an engagement is in place.</p>

<h2>3. Portal access</h2>
<ul>
  <li>Portal accounts are issued to authorized clients and staff only. Keep your credentials confidential.</li>
  <li>You are responsible for activity under your account and must tell us promptly of any suspected misuse.</li>
  <li>We may suspend access to protect the security of the service or other users.</li>
</ul>

<h2>4. Acceptable use</h2>
<p>You must not attempt to gain unauthorized access, probe or disrupt the service, submit malicious code, scrape the site, or use it unlawfully.</p>

<h2>5. Intellectual property</h2>
<p>The website, its design and content belong to MyIPStrategy or its licensors. Documents you upload remain yours (or your clients'), and you authorize us to process them to deliver our services.</p>

<h2>6. Disclaimers and liability</h2>
<p>The website is provided "as is". To the extent permitted by law, we are not liable for indirect or consequential loss arising from use of the website. Nothing here limits liability that cannot be limited by law.</p>

<h2>7. Governing law</h2>
<p>These terms are governed by the laws of India, and the courts of India have jurisdiction, subject to any engagement letter that provides otherwise.</p>

<h2>8. Contact and changes</h2>
<p>Questions about these terms can be sent through our <a href="/#contact">contact form</a>. We may update these terms and will revise the date above.</p>
HTML;
    }

    public static function for(string $slug): string
    {
        return match ($slug) {
            'privacy' => self::privacy(),
            'terms' => self::terms(),
            default => '',
        };
    }
}
