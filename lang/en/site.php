<?php

declare(strict_types=1);

/*
| The public website's shared words: the frame (navigation, footer), the home and sign-in pages, and the labels the
| templates use. Page copy lives in site_pages.php, app pages in site_apps.php, legal pages in site_legal.php.
| lang/bn/site.php has exactly the same keys (tests/Feature/Central/WebsiteTest.php checks it).
*/

return [
    'meta' => [
        'description' => 'Oyosend is business software for Bangladesh: CRM, invoicing, HR, payroll, projects, support and WhatsApp, email and SMS marketing in one workspace.',
        'tagline' => 'Business software for Bangladesh',
        'logo' => 'Oyosend home',
    ],

    'skip' => 'Skip to content',

    'nav' => [
        'label' => 'Main',
        'home' => 'Home',
        'apps' => 'Apps',
        'pricing' => 'Pricing',
        'security' => 'Security',
        'about' => 'About',
        'faq' => 'FAQ',
        'blog' => 'Blog',
        'contact' => 'Contact',
        'signin' => 'Sign in',
        'start' => 'Start free',
        'talk' => 'Talk to us',
        'menu' => 'Open menu',
        'close' => 'Close menu',
        'apps_menu' => 'Apps by area',
        'all_apps' => 'See all :count apps',
        'language' => 'বাংলা',
        'language_label' => 'Read this page in Bengali',
        'theme' => 'Switch between light and dark',
    ],

    'common' => [
        'in_short' => 'In short',
        'questions' => 'Questions people ask',
        'learn_more' => 'Learn more',
        'see_pricing' => 'See pricing',
        'see_apps' => 'Explore the apps',
        'contact_us' => 'Contact us',
        'free' => 'Free',
        'per_month' => 'per month',
        'per_year' => ':price per year',
        'trial' => ':count-day free trial',
        'app_free' => 'Free to add',
        'app_paid_month' => 'From :price a month',
        'app_paid_year' => 'From :price a year',
        'breadcrumb' => 'Breadcrumb',
        'related' => 'Works well with',
        'all_apps' => 'All apps',
        'features' => 'What it does',
        'audience' => 'Who it is for',
        'apps_count' => '{1} :count app|[2,*] :count apps',
    ],

    'footer' => [
        'about' => 'One workspace for the whole company: sales, money, people, projects, service and marketing, in English and Bengali.',
        'product' => 'Product',
        'company' => 'Company',
        'legal' => 'Legal',
        'contact' => 'Get in touch',
        'rights' => '© :year Oyosend. All rights reserved.',
        'based' => 'Based in Uttara, Dhaka.',
        'privacy' => 'Privacy',
        'terms' => 'Terms',
        'refund' => 'Refunds',
        'social' => 'Oyosend elsewhere',
        'networks' => [
            'facebook' => 'Oyosend on Facebook',
            'linkedin' => 'Oyosend on LinkedIn',
            'youtube' => 'Oyosend on YouTube',
            'instagram' => 'Oyosend on Instagram',
            'x' => 'Oyosend on X',
        ],
    ],

    'home' => [
        'meta_title' => 'Oyosend | All-in-one Business Software for Bangladesh',

        'eyebrow' => 'Business software, built for Bangladesh',
        // The H1; :highlight is underlined on the page.
        'title' => 'Run your whole company from :highlight',
        'title_highlight' => 'one workspace.',
        // Shown in turn in place of the highlight on the home page (the first is the highlight itself). Each must
        // finish the title's sentence and stay short enough to keep its line.
        'title_rotate' => ['one workspace.', 'one sign-in.', 'one shared inbox.', 'one dashboard.', 'anywhere.'],
        'lede' => 'Oyosend brings sales, invoicing, HR and payroll, projects, customer support and WhatsApp, email and SMS marketing together in one secure workspace, in English or Bengali, billed in taka.',
        'stats' => [
            'apps' => 'apps, one sign-in',
            'languages' => 'languages: English and বাংলা',
            'trial' => 'days free, nothing charged automatically',
            'charges' => 'automatic charges: you pay by invoice',
        ],

        // The card beside the headline: the start of sign-up, carried to the full form.
        'start' => [
            'title' => 'Start your workspace',
            'lede' => 'Choose your company\'s own address. It takes a few minutes and nothing is charged.',
            'company' => 'Company name',
            'company_placeholder' => 'Meghna Traders',
            'email' => 'Work email',
            'email_placeholder' => 'you@company.com',
            'address' => 'Your workspace address',
            'submit' => 'Continue',
            'next' => 'Next you choose a password and confirm your email.',
            'existing' => 'Already have a workspace?',
            'closed_title' => 'Talk to us about a workspace',
            'closed_body' => 'New workspaces are set up with our team at the moment. Tell us about your company and we will get you started.',
        ],

        // The product as it looks at work, rising into place as the page scrolls. Decorative sample data.
        'dash' => [
            'label' => 'An Oyosend workspace: the day\'s sales, unpaid invoices, people on leave and new WhatsApp chats, deals by stage, the WhatsApp inbox and a reply the assistant drafted.',
            'nav' => ['Home', 'CRM', 'Invoices', 'People', 'Payroll', 'WhatsApp', 'Support', 'Reports'],
            'apps' => 'Apps',
            'greeting' => 'Good morning, Nusrat',
            'period' => 'October · Week 42',
            'live' => 'Payroll approved',
            'kpis' => [
                ['label' => 'Open deals', 'note' => '12 in proposal'],
                ['label' => 'Unpaid invoices', 'note' => '5 due this week'],
                ['label' => 'On leave today', 'note' => 'of 48 people'],
                ['label' => 'New WhatsApp chats', 'note' => '3 waiting'],
            ],
            'deals' => 'Deals by stage',
            'deals_note' => 'this month',
            'deal_list' => [
                ['name' => 'Meghna Traders', 'stage' => 'Proposal', 'tone' => 'warn'],
                ['name' => 'Padma Foods', 'stage' => 'Waiting for reply', 'tone' => 'accent'],
                ['name' => 'Karnaphuli Logistics', 'stage' => 'Won', 'tone' => 'ok'],
                ['name' => 'Sundarban Textiles', 'stage' => 'New', 'tone' => 'info'],
            ],
            'inbox' => 'WhatsApp inbox',
            'inbox_note' => 'reply window open',
            'chats' => [
                ['name' => 'Rafiq', 'text' => 'Is the blue one back in stock?'],
                ['name' => 'Tania', 'text' => 'Thanks, the invoice is paid.'],
                ['name' => 'Arif', 'text' => 'Can you deliver to Sylhet?'],
            ],
            'assistant' => 'AI assistant',
            'assistant_when' => 'just now',
            'assistant_text' => 'Padma Foods has not replied for 9 days. I drafted a follow-up with the quote attached.',
            'assistant_send' => 'Review and send',
            'assistant_dismiss' => 'Not now',
        ],

        'show_title' => 'Your whole business on one screen',
        'show_body' => 'Sales, money, people and customer conversations side by side, in the language each person chooses. The assistant drafts; you decide.',

        'answer_q' => 'What is Oyosend?',
        'answer' => 'Oyosend is cloud business software for companies in Bangladesh. Each company gets its own secure workspace at its own address and adds the apps it needs, such as CRM, invoicing, HR, payroll, projects, customer support and WhatsApp, email and SMS marketing. Everything works in English and Bengali and is billed in taka.',

        'apps_eyebrow' => 'One workspace, :count apps',
        'apps_title' => 'Add the apps you need, when you need them',
        'apps_lede' => 'Start with what matters today and add more from the App Store inside your workspace. Your team, contacts and companies are shared by every app, so nothing is typed twice.',

        'local_eyebrow' => 'Made for how Bangladesh works',
        'local_title' => 'Built around Bengali, taka and WhatsApp',
        'local' => [
            ['icon' => 'languages', 'title' => 'Bengali, all the way through', 'body' => 'Every screen works in Bengali and English, and each person picks their own language. Payslips print in both.'],
            ['icon' => 'banknote', 'title' => 'Taka, and invoices you pay yourself', 'body' => 'Prices and invoices are in BDT. A paid plan starts only after its invoice is paid and the payment is confirmed.'],
            ['icon' => 'message-circle', 'title' => 'WhatsApp where your customers are', 'body' => 'Answer customers through the official WhatsApp Business Platform from a shared inbox, with templates, quick replies and simple chatbot menus.'],
            ['icon' => 'list-checks', 'title' => 'Your rules, not hardcoded ones', 'body' => 'Payroll tax and contribution slabs, leave policies, shifts and approvals are settings you control, not assumptions built into the software.'],
        ],

        'steps_eyebrow' => 'Getting started',
        'steps_title' => 'Three steps to your own workspace',
        'steps' => [
            ['title' => 'Create your workspace', 'body' => 'Choose your company\'s address, such as yourcompany.:domain, and confirm your email. Nothing is created until you do.'],
            ['title' => 'Add your apps', 'body' => 'Pick the apps you need from the App Store. Most are free to add, and you can remove one later without losing its records.'],
            ['title' => 'Invite your team', 'body' => 'Give each person a role. They see only the apps and records their role allows, down to their own records if you choose.'],
        ],

        'security_eyebrow' => 'Security',
        'security_title' => 'Your company\'s data stays your company\'s',
        'security_lede' => 'Security is part of how Oyosend is built, not a feature added at the end.',
        'security_points' => [
            ['icon' => 'layers', 'title' => 'Separate by design', 'body' => 'Every workspace\'s records are kept to that workspace. A query that does not know its workspace is refused.'],
            ['icon' => 'key-round', 'title' => 'Two-step sign-in', 'body' => 'Any member can protect their account with an authenticator app code.'],
            ['icon' => 'user-round-check', 'title' => 'Roles and record scopes', 'body' => 'Decide who sees what: their own records, their department, their branch or everything.'],
            ['icon' => 'scroll-text', 'title' => 'An audit trail', 'body' => 'Sign-ins, changes to people and roles, and other important actions are recorded with who did them.'],
        ],
        'security_link' => 'How we protect your data',

        'pricing_eyebrow' => 'Pricing',
        'pricing_title' => 'Plans in taka, paid by invoice',
        'pricing_lede' => 'Every plan is billed in BDT. You pay each invoice yourself; nothing is charged automatically.',
        'pricing_closed' => 'Tell us about your company and we will send you a quote.',

        'faq' => [
            ['q' => 'Can I use Oyosend in Bengali?', 'a' => 'Yes. Every screen of Oyosend works in Bengali and English, and each person chooses their own language. Bengali payslips, Bengali-aware SMS counting and Bengali email templates are part of the apps.'],
            ['q' => 'Is my company\'s data separate from other companies?', 'a' => 'Yes. Each company has its own workspace at its own address, and the application keeps every record inside the workspace that owns it. People in one workspace cannot see another workspace\'s data.'],
            ['q' => 'Do you charge my card automatically?', 'a' => 'No. Oyosend bills by invoice in taka. A paid plan or app starts only after its invoice is paid and the payment is confirmed with the payment gateway, or recorded by our team.'],
            ['q' => 'Which apps can I use?', 'a' => 'Twenty apps cover sales, invoicing, HR, payroll, recruitment, projects, customer support, business calling, WhatsApp, email, SMS and social media marketing, Meta ads, workflow automation, reports, notes, shared links, a team calendar and an AI assistant. You add them from the App Store in your workspace.'],
            ['q' => 'Can I try Oyosend before paying?', 'a' => 'Yes. Plans that offer a free trial can be used before the first invoice, and the pricing page shows each plan\'s trial. Nothing is charged automatically when a trial ends; you decide whether to pay the invoice.'],
            ['q' => 'Where is Oyosend based?', 'a' => 'Oyosend is based at 1st Floor, House 08, Road 13/A, Sector 06, Uttara, Dhaka-1230, Bangladesh. You can reach us at info@oyosend.com or on +88 0179 22 88 555.'],
        ],

        'cta_title' => 'Bring your whole company into one workspace',
        'cta_body' => 'Create your workspace in a few minutes, or tell us what you need and we will help you choose.',
        'orbit_label' => 'Some of the apps in your workspace',
    ],

    'signin' => [
        'meta_title' => 'Sign in to your workspace',
        'meta_description' => 'Sign in to your Oyosend workspace. Type your company\'s workspace address and we will take you straight to its sign-in page.',
        'eyebrow' => 'Sign in',
        'title' => 'Sign in to your workspace',
        'lede' => 'Every company has its own address. Type yours and we will take you to its sign-in page.',
        'label' => 'Workspace address',
        'placeholder' => 'yourcompany',
        'hint' => 'For example yourcompany.:domain',
        'submit' => 'Continue',
        'new' => 'New to Oyosend?',
        'create' => 'Create a workspace',
        'unsure' => 'Not sure of your address? Ask the person who invited you, or look for it in your invitation email.',
    ],

    'pricing' => [
        'members' => '{1} Up to :count member|[2,*] Up to :count members',
        'members_unlimited' => 'No limit on members',
        'monthly' => 'per month',
        'yearly' => 'per year',
        'includes' => 'Apps included in the plan',
        'core_only' => 'The core workspace: members, roles, contacts and companies, and the App Store.',
        'choose' => 'Choose :plan',
        'popular' => 'Most complete',
        'offer_until' => 'Offer until :date',
        'usual_price' => 'Usual price',
        'yearly_saving' => 'Save :percent% yearly',
        'offer_keeps' => 'Sign up before the offer ends and you keep this price when you renew.',
        'apps_heading' => 'App prices',
        'app_name' => 'App',
        'app_price' => 'Price',
        'included' => 'Free to add',
        'paid_month' => ':price a month',
        'paid_month_trial' => ':price a month, :count-day free trial',
        'paid_year' => ':price a year',
        'paid_year_trial' => ':price a year, :count-day free trial',
    ],

    'contact' => [
        'required' => 'Required',
    ],

    'llms' => [
        'summary' => 'Oyosend is a multi-tenant business platform for small and growing companies in Bangladesh. Each company gets its own workspace at its own subdomain and installs the apps it needs from an in-product App Store. The interface is in English and Bengali; prices are in Bangladeshi taka (BDT).',
        'facts' => 'Key facts',
        'fact_list' => [
            'Twenty apps: CRM & Sales, Invoicing, HR Management, Payroll, Talent & Performance, WhatsApp Business, Email Marketing, SMS Marketing, Social Media, Meta Ads, Customer Support, Communication, Telephony, Project Management, Workflow Automation, AI Assistant, Reports & Analytics, Notes, Links and Calendar.',
            'Every screen is available in English and Bengali; each person chooses their own language.',
            'Billing is by invoice in BDT. Nothing is charged automatically; a paid plan starts only after a confirmed payment.',
            'Each workspace\'s data is kept separate by the application; roles and record scopes decide what each person sees.',
            'Two-step sign-in is available to every member, and important actions are recorded in an audit trail.',
            'The AI Assistant drafts and proposes; it never changes data until a person confirms.',
        ],
        'pages' => 'Website pages',
        'apps' => 'Apps',
        'blog' => 'Blog articles',
        'bengali' => 'In Bengali',
        'bengali_body' => 'Every page of this website is also published in Bengali:',
        'contact' => 'Contact',
    ],
];
