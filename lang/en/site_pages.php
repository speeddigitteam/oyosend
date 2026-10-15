<?php

declare(strict_types=1);

return [
    'apps' => [
        'meta_title' => 'CRM, HR, payroll and marketing apps',
        'meta_description' => 'Add CRM, invoicing, HR, payroll, marketing, support, automation and AI apps to your Oyosend workspace. Most are free, and removing one keeps its data.',
        'eyebrow' => 'Apps',
        'title' => 'One workspace, and the apps your business needs',
        'lede' => 'Every Oyosend workspace starts with the core: your team, roles, shared contacts and companies, branches and departments, an audit trail and billing. Add apps from the App Store when you need them. They work on the same contacts and under the same permissions, so each customer is one record across the apps that use it.',
        'answer_q' => 'What apps does Oyosend include?',
        'answer' => 'Oyosend offers 17 optional apps that you add from the App Store inside your workspace: CRM & Sales, Invoicing, HR Management, Payroll, Talent & Performance, Project Management, Email Marketing, SMS Marketing, WhatsApp Business, Social Media, Meta Ads, Customer Support, Communication, Workflow Automation, AI Assistant, Reports & Analytics and Notes. Most of them are free to add.',
        'cta_title' => 'Start with the apps you need today',
        'cta_body' => 'Create your workspace, add one app or several, and add more as your business grows. Removing an app later keeps its data, so you can add it back at any time.',
    ],
    'pricing' => [
        'meta_title' => 'Pricing, plans and billing',
        'meta_description' => 'Oyosend pricing in Bangladeshi taka: choose a plan, add apps separately and pay each invoice online or by bank transfer. Nothing is charged automatically.',
        'eyebrow' => 'Pricing',
        'title' => 'Clear prices in taka, and nothing charged automatically',
        'lede' => 'Your workspace is on one plan, and apps are added and billed separately. Every charge arrives as an invoice that you choose to pay, online or by transfer, and a paid plan starts only once the payment is confirmed.',
        'answer_q' => 'How does Oyosend billing work?',
        'answer' => 'Oyosend bills by invoice in Bangladeshi taka. Your workspace has one plan, and apps are billed separately. Nothing is charged automatically: you pay each invoice online through SSLCommerz or aamarPay, or by a transfer that our team records. A paid plan or app starts only after the payment has been verified.',
        'plans_heading' => 'Plans',
        'plans_lede' => 'Each workspace is on one plan, chosen when you sign up and changeable later. Prices, trial days and what each plan includes are shown below. A subscription renews at the price you took it at.',
        'closed' => 'Sign-up for new workspaces is closed at the moment. Tell us about your business and we will send you a quote and set up your workspace for you.',
        'apps_heading' => 'App prices',
        'apps_lede' => 'Apps are billed separately from your plan. Most are free to add. A paid app shows its price and any trial here, and is invoiced on its own.',
        'billing_heading' => 'How billing works',
        'billing_points' => [
            'Every charge is an invoice. Nothing is taken from a card or an account automatically.',
            'Pay online through SSLCommerz or aamarPay, or by bank transfer, cheque, cash or mobile banking that our team records against the invoice.',
            'A paid plan or app starts only after the payment gateway itself confirms the payment, or our team records the money received.',
            'If your plan has a free trial, the first invoice arrives before the trial ends and is due on the day it ends.',
            'A late invoice gets a grace period. After that the workspace is locked to its billing pages until the invoice is paid, and your data is kept.',
        ],
        'faq' => [
            [
                'q' => 'Do you charge my card automatically?',
                'a' => 'No. Oyosend never takes money from a card or an account on its own. Each renewal, plan change or app purchase arrives as an invoice, and you decide when to pay it.',
            ],
            [
                'q' => 'Is there a free trial?',
                'a' => 'A plan can include a free trial, and the number of days is shown with each plan. You pay nothing during the trial. The first invoice is issued shortly before it ends.',
            ],
            [
                'q' => 'Do apps cost extra?',
                'a' => 'Most apps are free to add. The AI Assistant is a paid app, billed monthly or yearly, with a 14-day trial offer. A plan can also include apps in its price. Paid apps are invoiced separately from your plan.',
            ],
            [
                'q' => 'How can I pay an invoice?',
                'a' => 'Online through SSLCommerz or aamarPay, where the invoice offers them, or by bank transfer, cheque, cash or mobile banking. Our team records an offline payment against the invoice, and the record appears in your workspace\'s audit trail.',
            ],
            [
                'q' => 'What happens if I pay late?',
                'a' => 'The workspace keeps working through a grace period. If the invoice is still unpaid after that, the workspace is locked to its billing pages. Your data is kept, and paying the open invoice brings the workspace back.',
            ],
            [
                'q' => 'Can I change or cancel my plan?',
                'a' => 'Yes. A move to a more expensive plan is invoiced with credit for the unused part of your current period. A cheaper plan starts when the paid period ends. A cancellation runs to the end of the period, and you can take it back before then.',
            ],
        ],
    ],
    'security' => [
        'meta_title' => 'Security and data protection',
        'meta_description' => 'How Oyosend protects business data: separate workspaces, roles and record scopes, two-step sign-in, an audit trail and support access you can see.',
        'eyebrow' => 'Security',
        'title' => 'Your data stays inside your workspace',
        'lede' => 'Oyosend is built so that one company never sees another company\'s records, people see only what their role allows, and important changes leave a trace. This page explains how, in plain words.',
        'answer_q' => 'How does Oyosend keep my business data secure?',
        'answer' => 'Oyosend keeps every company in its own workspace and applies that workspace to every query on business records, refusing any query that has none. Inside a workspace, roles and record scopes decide who sees what. Two-step sign-in, an audit trail, encrypted credentials and audited, time-limited support access protect the rest.',
        'pillars' => [
            [
                'title' => 'Separate workspaces',
                'body' => 'Each company works on its own address with its own data. The application adds the workspace to every query, and a query without one is refused rather than answered, so a mistake fails closed instead of showing another company\'s records.',
            ],
            [
                'title' => 'Roles, permissions and record scopes',
                'body' => 'Access follows a person\'s role. A permission can reach a person\'s own records, their department\'s, their branch\'s or all of them, and nobody can grant a permission they do not hold themselves.',
            ],
            [
                'title' => 'Two-step sign-in',
                'body' => 'Every member can protect their sign-in with a code from an authenticator app, with single-use recovery codes. It is mandatory for our own operators. A session belongs to one workspace and ends when the membership does.',
            ],
            [
                'title' => 'An audit trail',
                'body' => 'Sign-ins, invitations, role changes, billing events and many app actions are recorded with who did them and when. Entries cannot be edited or deleted, and members with permission can read and filter them.',
            ],
            [
                'title' => 'Support access you can see',
                'body' => 'Our operators can work inside a workspace only through a support session. It needs a written reason, ends by itself after a set time and cannot change members, roles, apps or the owner\'s personal settings. Its start and end appear in your own audit trail.',
            ],
            [
                'title' => 'Encrypted secrets',
                'body' => 'Two-step secrets, connected social account tokens and provider credentials are stored encrypted. Credentials set up by our team are write-only: they are never shown again, logged or sent to your browser. Passwords are stored only as hashes.',
            ],
            [
                'title' => 'Consent before marketing',
                'body' => 'Marketing messages go only to people with recorded consent. The platform checks consent and opt-outs when a message is queued and again when it is sent. Marketing email and SMS carry an unsubscribe link, and WhatsApp customers can reply STOP.',
            ],
            [
                'title' => 'AI that asks first',
                'body' => 'The AI Assistant is an optional app. It sees only what the person using it may see, and it cannot change data on its own: a change is a proposal that a person reads and confirms. It never touches money or permissions.',
            ],
        ],
        'practices_heading' => 'How we work',
        'practices' => [
            'Passwords are stored only as hashes, and a new password must be strong and must not appear in known data breaches.',
            'Sign-in attempts are rate limited, and a failed sign-in does not reveal whether an email address has an account.',
            'Links in invitation, sign-up and password emails expire, work once and are stored only as hashes.',
            'Files are stored privately for each workspace and are served only after a permission check or through a short-lived signed link.',
            'A payment counts only when the payment gateway confirms it directly to our server, never because of a browser redirect.',
            'Every app is first-party code built into Oyosend. A workspace cannot upload or run code, and nothing typed into a workflow or template is executed.',
        ],
        'faq' => [
            [
                'q' => 'Is my data separate from other companies?',
                'a' => 'Yes. Each company has its own workspace on its own address. Every record carries its workspace, the application checks it on every query, and a query without a workspace is refused. Sessions, cache, files and background jobs are kept apart the same way.',
            ],
            [
                'q' => 'Can Oyosend staff enter my workspace?',
                'a' => 'Only through a support session. An authorised operator signs in to our admin panel with two-step verification and gives a written reason. The session ends by itself after a set time, cannot change members, roles or apps, and is recorded in your audit trail under the operator\'s name.',
            ],
            [
                'q' => 'Do you support two-factor authentication?',
                'a' => 'Yes. Each member can turn on two-step verification with an authenticator app and keeps a set of single-use recovery codes. Our own operators cannot use the admin panel without it.',
            ],
            [
                'q' => 'Does the AI Assistant send my data to an AI provider?',
                'a' => 'Only if you add the AI Assistant and use it. The text a request needs is then sent to the AI provider our team has set up, limited to records the person may see and the data you allow. You can also mask email addresses, phone numbers and card-like numbers before anything is sent.',
            ],
        ],
    ],
    'about' => [
        'meta_title' => 'About Oyosend',
        'meta_description' => 'Oyosend is business software from Uttara, Dhaka: one workspace for sales, HR, payroll, marketing and support, in English and Bengali, billed in taka.',
        'eyebrow' => 'About',
        'title' => 'Business software built for how Bangladesh works',
        'lede' => 'Oyosend gives small and growing companies one place to run sales, invoicing, people, marketing and support, in English and Bengali, with prices in taka.',
        'answer_q' => 'What is Oyosend?',
        'answer' => 'Oyosend is a business software platform from Dhaka, Bangladesh. Each company gets its own workspace on its own web address, with team roles, shared contacts and an audit trail, and adds apps for CRM, invoicing, HR, payroll, marketing, support, projects, automation and AI. It works in English and Bengali and bills in taka.',
        'story_heading' => 'Why Oyosend',
        'story' => [
            'Running a growing business often means a mix of spreadsheets, chat groups and separate tools that do not talk to each other. Customer details are typed again in each one, and nobody sees the whole picture. Oyosend exists to bring that work into one place.',
            'Each company gets its own workspace on its own address. The core holds your team, roles, contacts and companies, branches and departments. Apps for sales, invoicing, HR, payroll, marketing, support, projects, automation, reports and AI are added from the App Store and work on the same records, under the same permissions and audit trail.',
            'We build for local needs: an interface in English and Bengali, taka and the Dhaka time zone by default, payslips in Bengali, SMS counting that understands Bengali text, and payment through local gateways. Where a connection to an outside service is not set up, Oyosend says so plainly instead of pretending it works.',
        ],
        'principles_heading' => 'What we hold to',
        'principles' => [
            [
                'title' => 'Your data is yours',
                'body' => 'Each company\'s records stay in its own workspace. Removing an app or ending a plan keeps your data, so you can change your mind without losing what you have built.',
            ],
            [
                'title' => 'No surprise charges',
                'body' => 'Every charge is an invoice you choose to pay. Nothing is taken automatically, and a subscription renews at the price you took it at.',
            ],
            [
                'title' => 'People decide',
                'body' => 'Workflows run with the permissions of the person who published them and cannot approve money. The AI changes nothing until a person confirms. Sensitive steps, such as payroll runs and social posts, can require approval by a second person.',
            ],
            [
                'title' => 'Say what is true',
                'body' => 'If something is not connected or not built yet, the product says so. We would rather show that a service is not configured than offer a feature that only looks like it works.',
            ],
        ],
    ],
    'faq' => [
        'meta_title' => 'Frequently asked questions',
        'meta_description' => 'Answers about Oyosend: signing up, adding apps, Bengali support, invoices and payments in taka, and how your business data is kept separate and secure.',
        'eyebrow' => 'FAQ',
        'title' => 'Questions and answers',
        'lede' => 'Short answers about getting started, apps, billing and security. If your question is not here, get in touch and we will answer it.',
        'groups' => [
            [
                'title' => 'Getting started',
                'items' => [
                    [
                        'q' => 'What is Oyosend?',
                        'a' => 'Oyosend is business software for companies in Bangladesh. Your company gets its own workspace on its own web address, where your team works with shared contacts, roles and an audit trail, and adds apps for sales, invoicing, HR, payroll, marketing, support and more.',
                    ],
                    [
                        'q' => 'How do I sign up?',
                        'a' => 'Choose a plan, pick your workspace address and enter your details. We email you a confirmation link, and opening it creates your workspace. Depending on how sign-up is set, a new workspace may wait for our approval, or we set it up for you on request.',
                    ],
                    [
                        'q' => 'Can I use Oyosend in Bengali?',
                        'a' => 'Yes. The interface is available in English and Bengali, and each person chooses their own language. Numbers can appear in Latin or Bengali digits. Prices are in taka and times follow the Dhaka time zone by default.',
                    ],
                    [
                        'q' => 'How do I add my team?',
                        'a' => 'Invite members by email from your workspace and give each one a role. An invitation expires after seven days if it is not accepted. Your plan may set how many members a workspace can have.',
                    ],
                    [
                        'q' => 'Is there a free trial?',
                        'a' => 'A plan can include a free trial, and the number of days is shown with each plan. You pay nothing during the trial, and the first invoice arrives shortly before it ends.',
                    ],
                ],
            ],
            [
                'title' => 'Apps',
                'items' => [
                    [
                        'q' => 'Which apps can I add?',
                        'a' => 'There are 17: CRM & Sales, Invoicing, HR Management, Payroll, Talent & Performance, Project Management, Email Marketing, SMS Marketing, WhatsApp Business, Social Media, Meta Ads, Customer Support, Communication, Workflow Automation, AI Assistant, Reports & Analytics and Notes. You add them from the App Store inside your workspace.',
                    ],
                    [
                        'q' => 'Do apps cost extra?',
                        'a' => 'Most apps are free to add. The AI Assistant is a paid app with monthly and yearly options and a 14-day trial offer. A plan can also include apps in its price.',
                    ],
                    [
                        'q' => 'What happens to my data if I remove an app?',
                        'a' => 'Removing an app turns off access to it, but its data stays in your workspace. If you add the app back later, your records are still there.',
                    ],
                    [
                        'q' => 'Do I need a provider to send SMS or WhatsApp messages?',
                        'a' => 'Yes. SMS and WhatsApp messages go through a provider account that our team sets up and your workspace selects in the Communication app. WhatsApp uses the official WhatsApp Business Platform. Until a provider is connected, the app says so and sends nothing.',
                    ],
                    [
                        'q' => 'Do the apps work together?',
                        'a' => 'Yes. The apps share one list of contacts and companies, so a customer is one record everywhere. Workflow Automation can react to events from your installed apps, and Reports & Analytics brings their reports together, always within each person\'s permissions.',
                    ],
                ],
            ],
            [
                'title' => 'Billing and payments',
                'items' => [
                    [
                        'q' => 'Do you charge my card automatically?',
                        'a' => 'No. Oyosend never takes money on its own. Every renewal, upgrade or app purchase arrives as an invoice, and a paid plan or app starts only after the payment has been verified.',
                    ],
                    [
                        'q' => 'How can I pay?',
                        'a' => 'Online through SSLCommerz or aamarPay, where your invoice offers them, or by bank transfer, cheque, cash or mobile banking, which our team records against the invoice.',
                    ],
                    [
                        'q' => 'What currency are prices in?',
                        'a' => 'Plans and paid apps are priced and invoiced in Bangladeshi taka (BDT).',
                    ],
                    [
                        'q' => 'What happens if an invoice is not paid on time?',
                        'a' => 'Your workspace keeps working through a grace period. After that it is locked to its billing pages until the invoice is paid. Nothing is deleted, and paying the open invoice brings the workspace back.',
                    ],
                    [
                        'q' => 'Will my price change when I renew?',
                        'a' => 'No. A subscription renews at the price you took it at. A later change to a plan\'s price applies to new subscriptions and to plan changes.',
                    ],
                ],
            ],
            [
                'title' => 'Security and data',
                'items' => [
                    [
                        'q' => 'Is my data separate from other companies?',
                        'a' => 'Yes. Each company has its own workspace, and the application checks the workspace on every database query. A query without one is refused, so a mistake fails closed instead of showing another company\'s records.',
                    ],
                    [
                        'q' => 'Who in my company can see what?',
                        'a' => 'Each member has a role. A permission can reach a person\'s own records, their department\'s, their branch\'s or all records, and nobody can give a permission they do not hold themselves.',
                    ],
                    [
                        'q' => 'Can Oyosend staff enter my workspace?',
                        'a' => 'Only through a support session that needs a written reason and ends by itself after a set time. It cannot change members, roles or apps, and its start and end are recorded in your workspace\'s audit trail under the operator\'s name.',
                    ],
                    [
                        'q' => 'Can I see who changed what?',
                        'a' => 'Yes. The audit trail records sign-ins, invitations, role changes, billing events and many app actions with who did them and when. Entries cannot be edited or deleted.',
                    ],
                    [
                        'q' => 'Does the AI Assistant change my data?',
                        'a' => 'No. The AI Assistant can answer, summarise and draft, but a change is only a proposal. It is applied only when a person reads it and confirms, and the AI cannot touch money or permissions.',
                    ],
                ],
            ],
        ],
    ],
    'contact' => [
        'meta_title' => 'Contact Oyosend',
        'meta_description' => 'Contact Oyosend in Uttara, Dhaka about plans, a demo or support. Call +88 0179 22 88 555 or +88 018 292 33 333, or email info@oyosend.com.',
        'eyebrow' => 'Contact',
        'title' => 'Talk to the Oyosend team',
        'lede' => 'Questions about plans, apps or setting up your workspace? Send us a message, call or email, and we will get back to you.',
        'answer_q' => 'How can I contact Oyosend?',
        'answer' => 'Oyosend can be reached by email at info@oyosend.com or by phone on +88 0179 22 88 555 and +88 018 292 33 333. The office is at 1st Floor, House 08, Road 13/A, Sector 06, Uttara, Dhaka-1230, Bangladesh. You can also send a message with the form on this page.',
        'details_heading' => 'Contact details',
        'address_label' => 'Address',
        'phone_label' => 'Phone',
        'email_label' => 'Email',
        'map' => 'Open in Google Maps',
        'form_heading' => 'Send us a message',
        'form_lede' => 'Tell us a little about your business and what you need. We will reply by email or phone.',
        'name' => 'Your name',
        'email' => 'Email address',
        'phone' => 'Phone number',
        'company' => 'Company',
        'topic' => 'Topic',
        'message' => 'Message',
        'topics' => [
            'sales' => 'Plans and pricing',
            'demo' => 'Book a demo',
            'support' => 'Help with my workspace',
            'partnership' => 'Partnership',
            'other' => 'Something else',
        ],
        'send' => 'Send message',
        'sent' => 'Thank you. Your message has been sent, and we will get back to you.',
        'failed' => 'Your message could not be sent. Please email info@oyosend.com or call +88 0179 22 88 555 instead.',
        'privacy_note' => 'We use these details only to answer your message.',
        'optional' => 'optional',
    ],

    'blog' => [
        'meta_title' => 'Blog: guides and news for running a business in Bangladesh',
        'meta_description' => 'Practical guides on sales, invoicing, payroll, people and marketing for companies in Bangladesh, and what is new in Oyosend.',
        'page_title' => ':title, page :page',
        'eyebrow' => 'Blog',
        'title' => 'Ideas and guides for running a business in Bangladesh',
        'lede' => 'How to sell, bill, pay people and reach customers, written for companies here, and what changed in Oyosend.',
        'filter' => 'Topics',
        'all' => 'All articles',
        'kinds' => [
            'guide' => 'Guides',
            'update' => 'Product news',
            'insight' => 'Business tips',
        ],
        'about_app' => 'About :app',
        'clear_app' => 'Every app',
        'featured' => 'Featured',
        'read' => 'Read the article',
        'minutes' => '{1} :count min read|[2,*] :count min read',
        'empty_title' => 'Nothing published here yet',
        'empty_body' => 'The first articles are on their way. In the meantime, the apps and the questions page answer most of what people ask.',
        'empty_filtered' => 'No articles on this yet. See everything the blog has instead.',
        'other_language' => 'Read the articles in Bengali',
        'pagination' => 'Pages of the blog',
        'previous' => 'Newer articles',
        'next' => 'Older articles',
        'page' => 'Page :page',
        'feed' => 'Follow in a feed reader',
        'feed_title' => ':name blog',
        'by' => 'By :name',
        'updated' => 'Updated :date',
        'summary_title' => 'The short version',
        'contents' => 'On this page',
        'share' => 'Share',
        'share_on' => [
            'facebook' => 'Share on Facebook',
            'linkedin' => 'Share on LinkedIn',
            'x' => 'Share on X',
            'whatsapp' => 'Share on WhatsApp',
        ],
        'apps_title' => 'Apps in this article',
        'related_title' => 'Read next',
        'back' => 'All articles',
        'also_in' => 'Read in Bengali',
        'questions' => 'Questions about this',
        'preview' => 'Preview. This is how the article will look; nobody else can see it until it is published.',
        'latest_eyebrow' => 'From the blog',
        'latest_title' => 'Guides and news for running the company',
        'latest_lede' => 'Practical reading for companies in Bangladesh, and what changed in Oyosend.',
        'latest_all' => 'Read the blog',
        'app_title' => 'Reading about :app',
        'app_all' => 'Every article about :app',
    ],
];
