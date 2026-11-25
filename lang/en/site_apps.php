<?php

declare(strict_types=1);

return [
    'categories' => [
        'sales' => [
            'name' => 'Sales and invoicing',
            'description' => 'Track leads and deals, send quotations, then issue invoices and follow up on what customers owe.',
        ],
        'people' => [
            'name' => 'People and payroll',
            'description' => 'Keep employee records, attendance and leave, run payroll with a second approval, and manage hiring, reviews and training.',
        ],
        'marketing' => [
            'name' => 'Marketing',
            'description' => 'Reach customers by email, SMS, WhatsApp and social media, with consent checked before anything is sent, and follow your Meta ad spend.',
        ],
        'service' => [
            'name' => 'Customer service',
            'description' => 'Answer customers from tickets, website chat and one shared inbox, call them through your own phone system, and keep every conversation on the customer\'s timeline.',
        ],
        'operations' => [
            'name' => 'Operations and productivity',
            'description' => 'Plan projects, automate routine work, ask an AI assistant, run reports across your apps, keep quick notes and save the links your team uses.',
        ],
    ],

    'items' => [
        'crm' => [
            'name' => 'CRM & Sales',
            'meta_title' => 'CRM and Sales Software for Bangladesh',
            'meta_description' => 'Oyosend CRM & Sales turns contacts into leads, deals, quotations and sales orders, with pipelines, follow-ups and forecasts in English or Bengali.',
            'tagline' => 'Leads, deal pipelines, quotations and sales orders in one place.',
            'headline' => 'Turn enquiries into won deals, quotations and sales orders',
            'lede' => 'Oyosend CRM & Sales keeps every lead, deal and follow-up on the same customer record your whole workspace shares. Move deals through your pipeline, send quotations and see your forecast without retyping anything.',
            'answer_q' => 'What is Oyosend CRM & Sales?',
            'answer' => 'Oyosend CRM & Sales is the sales app of the Oyosend business platform. It turns shared contacts and companies into scored leads and deals, tracks them on visual pipelines, schedules calls, meetings and follow-ups, and produces quotations and sales orders with exact tax. Targets, weighted forecasts and conversion reports show how the team is doing.',
            'features' => [
                [
                    'title' => 'Leads with scores and sources',
                    'body' => 'Capture leads against a shared contact or company and give each one a 0 to 100 score, an owner and a source. Then qualify the lead or convert it into a deal.',
                ],
                [
                    'title' => 'Visual deal pipelines',
                    'body' => 'Set up your own pipelines and stages and drag deals across the board. Every stage move is kept in a history that cannot be edited, and won or lost deals are locked.',
                ],
                [
                    'title' => 'Activities and follow-ups',
                    'body' => 'Schedule calls, meetings, tasks and follow-ups with reminders, priorities and assignees, entered in your own local time.',
                ],
                [
                    'title' => 'Products, quotations and orders',
                    'body' => 'Keep products and price lists, and send quotations whose prices are frozen once sent. An accepted quotation becomes a sales order automatically.',
                ],
                [
                    'title' => 'Targets and forecasts',
                    'body' => 'Set targets for a person, a department, a branch or the whole company, and follow attainment, weighted forecast, conversion and order trends.',
                ],
                [
                    'title' => 'One customer record',
                    'body' => 'Leads, deals, notes, files and activities attach to the shared contact, with duplicate detection and a field-by-field merge.',
                ],
            ],
            'audience' => [
                'Sales teams that need one pipeline everyone can see.',
                'Owners who want an honest forecast and target tracking.',
                'Businesses that send quotations and then invoice the same customer.',
            ],
            'faq' => [
                [
                    'q' => 'Can Oyosend CRM create invoices from quotations?',
                    'a' => 'Yes, when the workspace also uses the Invoicing app. An accepted quotation creates a draft invoice with the same lines, prices and tax, once per quotation. A person checks the draft and issues it, so nobody is billed by accident.',
                ],
                [
                    'q' => 'Can leads come in from WhatsApp or Facebook?',
                    'a' => 'Yes. The WhatsApp Business app can turn a new conversation into a CRM lead, and the Social Media app can send Facebook lead form answers to the CRM. Both reuse the existing contact when the email or phone number matches, so customers are not duplicated.',
                ],
                [
                    'q' => 'Who can see which deals in Oyosend CRM?',
                    'a' => 'Each role has its own permissions, and customer records can be limited to a person\'s own records, their department, their branch or the whole company. A salesperson with access to their own records sees only the leads and deals they own.',
                ],
            ],
        ],

        'invoicing' => [
            'name' => 'Invoicing',
            'meta_title' => 'Invoicing Software for Bangladesh Businesses',
            'meta_description' => 'Oyosend Invoicing issues numbered invoices and credit notes in English or Bengali, records cash, bank and mobile banking payments and tracks dues.',
            'tagline' => 'Numbered invoices, payments, credit notes and receivables, in English or Bengali.',
            'headline' => 'Send accurate invoices and always know who still owes you',
            'lede' => 'Issue invoices with gap-free numbers and exact line tax, record every payment as it arrives and follow up on overdue balances. Documents print in English or Bengali, on your own letterhead if you like.',
            'answer_q' => 'What is Oyosend Invoicing?',
            'answer' => 'Oyosend Invoicing is the customer invoicing app of the Oyosend platform. It issues numbered invoices and credit notes with exact tax and discounts, records cash, bank, mobile banking, cheque and card payments, including part payments, and shows what each customer owes in an ageing report. Recurring invoices and payment reminders run on a schedule.',
            'features' => [
                [
                    'title' => 'Gap-free invoice numbering',
                    'body' => 'A number such as INV-0001 or INV-2026-0001 is given only when an invoice is issued, with optional fiscal-year numbering from the start month you choose.',
                ],
                [
                    'title' => 'Exact tax and discounts',
                    'body' => 'Each line has its own tax rate and a fixed or percentage discount. Amounts are kept in the smallest currency unit, so totals never drift.',
                ],
                [
                    'title' => 'Payments and credit notes',
                    'body' => 'Record full or part payments by cash, bank transfer, mobile banking, cheque or card, reverse a mistaken one with a reason, and issue numbered credit notes.',
                ],
                [
                    'title' => 'Recurring invoices and reminders',
                    'body' => 'Bill weekly, monthly, quarterly or yearly from a template, and send reminders before the due date, on the day and after it.',
                ],
                [
                    'title' => 'Print-ready documents',
                    'body' => 'Choose a classic, modern, compact or letterhead layout with your logo or your own header and footer images, then print or save a PDF from the browser.',
                ],
                [
                    'title' => 'Receivables ageing',
                    'body' => 'See what is not yet due and what is 1 to 30, 31 to 60, 61 to 90 or over 90 days late, by customer and by currency.',
                ],
            ],
            'audience' => [
                'Service businesses that bill clients every month.',
                'Accounts staff who record bank, cash and mobile banking payments.',
                'Owners who want overdue balances followed up on time.',
            ],
            'faq' => [
                [
                    'q' => 'Can Oyosend invoices be printed in Bengali?',
                    'a' => 'Yes. Invoices and credit notes are print-ready pages in the workspace language, English or Bengali. Pick a classic, modern, compact or letterhead layout, then print or save a PDF from your browser. An issued invoice keeps the customer details it was issued with.',
                ],
                [
                    'q' => 'How are payments recorded in Oyosend Invoicing?',
                    'a' => 'A team member records each payment with its method, reference, date and amount: cash, bank transfer, mobile banking, cheque, card or other. Part payments are allowed, the balance updates at once, and a mistaken payment can be reversed with a reason that stays on record.',
                ],
                [
                    'q' => 'Does Invoicing work with CRM and projects?',
                    'a' => 'Yes. An accepted CRM quotation can become a draft invoice, and approved billable time from the Project Management app can be grouped into one draft invoice per project and currency. Each quotation and each time entry is billed only once.',
                ],
            ],
        ],

        'hrm' => [
            'name' => 'HR Management',
            'meta_title' => 'HR Management Software for Bangladesh',
            'meta_description' => 'Oyosend HR Management keeps employee records, shifts, clock-in attendance and leave approvals in one place, in English or Bengali, for growing teams.',
            'tagline' => 'Employee records, shifts, attendance and leave with approvals.',
            'headline' => 'Run employee records, attendance and leave from one place',
            'lede' => 'Oyosend HR Management gives every employee one record with a full history, counts attendance to the minute from clock-in and clock-out, and sends leave requests to the right approvers.',
            'answer_q' => 'What is Oyosend HR Management?',
            'answer' => 'Oyosend HR Management is the HR app of the Oyosend platform. It keeps employee records with reporting lines and a history that cannot be edited, sets shifts and holidays, records clock-in and clock-out, and works out late, early-leave and overtime minutes. Leave types, policies, approvals and a balance ledger handle time off, with self-service and HR reports.',
            'features' => [
                [
                    'title' => 'Employee records with history',
                    'body' => 'Personal, job and placement details, private documents, assets and emergency contacts, with automatic employee numbers and a history that cannot be edited.',
                ],
                [
                    'title' => 'Shifts and holidays',
                    'body' => 'Define shifts with grace periods and overtime thresholds, set weekly days off (Friday and Saturday by default) and keep a holiday calendar.',
                ],
                [
                    'title' => 'Clock-in attendance',
                    'body' => 'Staff clock in and out on the server clock. Each day is closed automatically with worked, late, early-leave and overtime minutes.',
                ],
                [
                    'title' => 'Corrections with approval',
                    'body' => 'Employees request attendance corrections for the last 31 days, and their manager approves or rejects them. Nobody decides their own request.',
                ],
                [
                    'title' => 'Leave policies and balances',
                    'body' => 'Paid and unpaid leave types, half days, annual or monthly accrual, carry forward and a sandwich rule, with a balance ledger that is never overwritten.',
                ],
                [
                    'title' => 'Self-service and HR reports',
                    'body' => 'Employees see their own attendance, balances and requests, and HR sees headcount, attendance and leave reports.',
                ],
            ],
            'audience' => [
                'Growing teams moving HR off spreadsheets.',
                'Managers who approve leave and attendance corrections.',
                'HR staff who need headcount and attendance reports.',
            ],
            'faq' => [
                [
                    'q' => 'How does Oyosend count attendance?',
                    'a' => 'Employees clock in and out, and the server clock is the only time source. Each working day is closed automatically against the employee\'s shift, giving worked, late, early-leave and overtime minutes, and marking holidays, weekly days off, approved leave and absences.',
                ],
                [
                    'q' => 'Can leave requests go to a manager and then HR?',
                    'a' => 'Yes. Each leave type has an approval flow: manager then HR, manager only, or HR only. Pending requests already count against the balance, a rejection needs a note, and nobody can approve their own leave.',
                ],
                [
                    'q' => 'Does Oyosend HR Management connect to payroll?',
                    'a' => 'Yes. The Payroll app reads closed attendance days and approved leave from HR Management, so unpaid leave, absence and overtime flow into each payroll run. Payroll will not calculate a period while any attendance day in it is still open.',
                ],
            ],
        ],

        'payroll' => [
            'name' => 'Payroll',
            'meta_title' => 'Payroll Software for Bangladesh',
            'meta_description' => 'Oyosend Payroll runs salaries, overtime, festival bonuses, loans and deductions with second-person approval, and prints payslips in English or Bengali.',
            'tagline' => 'Salary runs with four-eyes approval and payslips in English or Bengali.',
            'headline' => 'Pay your team accurately, with a second person checking every run',
            'lede' => 'Oyosend Payroll calculates salaries from your own structures, attendance and leave, and by default locks a run only after a different person approves it. Payslips print in English or Bengali.',
            'answer_q' => 'What is Oyosend Payroll?',
            'answer' => 'Oyosend Payroll is the payroll app of the Oyosend platform. It calculates pay from salary structures, attendance and leave, adds overtime, allowances, festival bonuses and loan instalments, and applies the tax and contribution slabs your workspace enters. A different person approves each run by default, after which payslips are numbered, locked and printable in English or Bengali.',
            'features' => [
                [
                    'title' => 'Salary structures and revisions',
                    'body' => 'Build earnings and deductions as fixed amounts or a share of basic pay. Each salary change is a new revision, and earlier ones are never overwritten.',
                ],
                [
                    'title' => 'Attendance and leave-based pay',
                    'body' => 'Monthly or custom pay periods prorate pay for joiners and leavers, and deduct unpaid leave and absence from closed attendance days.',
                ],
                [
                    'title' => 'Overtime, bonuses and loans',
                    'body' => 'Pay overtime at your own multiplier, add allowances, bonuses, festival bonuses and reimbursements, and deduct loan and advance instalments.',
                ],
                [
                    'title' => 'Your own tax and contribution rules',
                    'body' => 'Enter progressive or banded slab tables for income tax, contributions or levies, with effective dates. No rules are preloaded, so you set the ones you follow.',
                ],
                [
                    'title' => 'Four-eyes approval and locking',
                    'body' => 'By default, someone other than the preparer approves each run. Approval numbers and locks the payslips, and corrections go through adjustment or reversal runs.',
                ],
                [
                    'title' => 'Payslips and bank sheets',
                    'body' => 'Print payslips in English or Bengali, export a bank payment sheet as CSV, and record each payslip as paid by bank, cash, mobile wallet or cheque.',
                ],
            ],
            'audience' => [
                'Businesses that pay monthly salaries and festival bonuses.',
                'Finance teams that want a second check before salaries are final.',
                'Employees who want to see and print their own payslips.',
            ],
            'faq' => [
                [
                    'q' => 'Does Oyosend Payroll include income tax rules?',
                    'a' => 'No tax law is preloaded. Your workspace enters its own tax, contribution and levy slab tables, progressive or banded, with effective dates. Each payslip stores how its figures were worked out, so a later rule change never alters a locked payslip.',
                ],
                [
                    'q' => 'What does Oyosend Payroll need to run?',
                    'a' => 'Payroll needs the HR Management app, which supplies employees, attendance and leave. You then enter salary structures, salaries and, if you want them, tax and contribution rules. A run can be calculated once the pay period has ended and its attendance days are closed.',
                ],
                [
                    'q' => 'Can employees see their own payslips?',
                    'a' => 'Yes. With self-service access an employee sees only their own approved payslips and can print them. Draft payslips are visible only to the people who run payroll, and another employee\'s payslip is never shown.',
                ],
            ],
        ],

        'talent' => [
            'name' => 'Talent & Performance',
            'meta_title' => 'Recruitment and Performance Review Software',
            'meta_description' => 'Oyosend Talent & Performance runs hiring pipelines, interviews, onboarding checklists, probation, performance reviews, goals and training for your team.',
            'tagline' => 'Hiring, onboarding, probation, reviews, goals and training in one app.',
            'headline' => 'Hire, onboard and develop your people in one connected process',
            'lede' => 'Oyosend Talent & Performance takes a candidate from application to hire, starts onboarding when HR adds the employee, and runs fair, calibrated performance reviews.',
            'answer_q' => 'What is Oyosend Talent & Performance?',
            'answer' => 'Oyosend Talent & Performance is the recruitment and performance app of the Oyosend platform. It manages job openings, a candidate pipeline, panel interviews with scorecards and offers, starts onboarding and offboarding checklists from HR events, tracks probation, and runs weighted review cycles with calibration, plus goals, training, certification reminders and hiring and attrition analytics.',
            'features' => [
                [
                    'title' => 'Hiring pipeline',
                    'body' => 'Job openings with a stage board from Applied to Hired, candidate documents and notes, duplicate checks and offer tracking.',
                ],
                [
                    'title' => 'Panel interviews and scorecards',
                    'body' => 'Schedule interviews with up to ten panelists. Each panelist scores the candidate privately and sees the others only after submitting.',
                ],
                [
                    'title' => 'Onboarding and offboarding',
                    'body' => 'Checklists from your templates start automatically when HR adds or terminates an employee, with tasks for the employee, the manager and HR.',
                ],
                [
                    'title' => 'Probation tracking',
                    'body' => 'Follow every probation to its end date with reminders, and record a decision to confirm, extend or end it.',
                ],
                [
                    'title' => 'Calibrated performance reviews',
                    'body' => 'Review cycles with weighted competencies, self and manager steps, calibration and acknowledgement by the employee.',
                ],
                [
                    'title' => 'Goals, training and analytics',
                    'body' => 'Track goals and KPIs, training courses and certificate expiry, and see the hiring funnel, time to fill, headcount and attrition.',
                ],
            ],
            'audience' => [
                'HR teams that hire regularly and want a clear pipeline.',
                'Managers who run interviews and yearly reviews.',
                'Companies with training or certification requirements.',
            ],
            'faq' => [
                [
                    'q' => 'Does Talent & Performance need the HR Management app?',
                    'a' => 'Yes. It reads employees from HR Management, starts onboarding when an employee is added and offboarding when one leaves, and follows the probation dates set there. After a hire, HR creates the employee record in HR Management and onboarding begins from it.',
                ],
                [
                    'q' => 'How does Oyosend keep interview scores fair?',
                    'a' => 'Feedback is blind: each panelist sees only their own scorecard until they submit, and then everyone\'s. Ratings run from 1 to 5 with a clear recommendation, and the hiring manager sees every scorecard before deciding.',
                ],
                [
                    'q' => 'How does Oyosend handle candidate data?',
                    'a' => 'Each candidate has a recorded consent basis and a retention date. When that date passes and no application is open, the candidate is anonymised automatically: documents and notes are deleted and personal details cleared, while application counts stay for statistics.',
                ],
            ],
        ],

        'email_marketing' => [
            'name' => 'Email Marketing',
            'meta_title' => 'Email Marketing Software for Bangladesh',
            'meta_description' => 'Oyosend Email Marketing sends scheduled campaigns and welcome sequences to your contact lists, with consent, unsubscribe and click reports handled for you.',
            'tagline' => 'Campaigns and welcome sequences to consenting contacts, with click reports.',
            'headline' => 'Email your customers with consent built into every send',
            'lede' => 'Build lists from your shared contacts, write emails in a structured block editor and schedule campaigns or welcome sequences. The platform checks consent and suppression before every email.',
            'answer_q' => 'What is Oyosend Email Marketing?',
            'answer' => 'Oyosend Email Marketing is the email campaign app of the Oyosend platform. It builds lists and segments from shared contacts, collects sign-ups with double opt-in, and sends scheduled campaigns, A/B subject tests and welcome or drip sequences. Consent, suppression and one-click unsubscribe are enforced at send time, and reports show deliveries, clicks and bounces.',
            'features' => [
                [
                    'title' => 'Lists, segments and imports',
                    'body' => 'Import subscribers from CSV with consent evidence and build rule-based segments. Subscribers stay linked to your shared contacts, never copied.',
                ],
                [
                    'title' => 'Double opt-in sign-up form',
                    'body' => 'Each list can have a public sign-up form. A subscriber is added only after confirming from their own inbox.',
                ],
                [
                    'title' => 'Block editor and personalisation',
                    'body' => 'Write with headings, text, images, buttons and dividers, and personalise with fields such as first name, with a fallback.',
                ],
                [
                    'title' => 'Campaigns and A/B subject tests',
                    'body' => 'Send now or schedule, pause or resume, send a test, and let a test group pick the better subject line by clicks or web-version views.',
                ],
                [
                    'title' => 'Welcome and drip sequences',
                    'body' => 'Up to ten timed steps start when someone subscribes to a list and stop when they leave it.',
                ],
                [
                    'title' => 'Consent and clear reports',
                    'body' => 'Every email carries an unsubscribe link, blocked addresses are never sent to, and reports show sent, blocked, bounced and clicked.',
                ],
            ],
            'audience' => [
                'Shops and service businesses that email customers who signed up.',
                'Marketers who want welcome emails to run on their own.',
                'Businesses that need proof of consent for every subscriber.',
            ],
            'faq' => [
                [
                    'q' => 'Does Oyosend Email Marketing track opens and clicks?',
                    'a' => 'It measures clicks through signed links and counts views of the web version. Emails are sent as plain text with a link to the designed web version, so opens are not measured, and the reports say so rather than guessing.',
                ],
                [
                    'q' => 'How does Oyosend handle unsubscribes?',
                    'a' => 'Every marketing email includes a one-click unsubscribe link and the standard unsubscribe headers. When someone unsubscribes, bounces or complains, further marketing email to that address is blocked and any running sequence stops for them.',
                ],
                [
                    'q' => 'Does Email Marketing need the Communication app?',
                    'a' => 'Yes. Email Marketing sends through the Oyosend Communication app, which holds the sending connection, consent records and suppression list. Your workspace sets its own from name and reply-to address, and a postal address is required in every footer before sending.',
                ],
            ],
        ],

        'sms_marketing' => [
            'name' => 'SMS Marketing',
            'meta_title' => 'Bulk SMS Marketing Software for Bangladesh',
            'meta_description' => 'Oyosend SMS Marketing sends bulk and scheduled SMS campaigns with Bengali-aware segment counting, cost estimates, opt-out links and consent checks.',
            'tagline' => 'Bulk and scheduled SMS with Bengali-aware segment counts and opt-out links.',
            'headline' => 'Send bulk SMS in Bengali or English and know the cost first',
            'lede' => 'Oyosend SMS Marketing counts segments the way phones do, Bengali text included, estimates the cost before you launch and checks consent for every recipient at send time.',
            'answer_q' => 'What is Oyosend SMS Marketing?',
            'answer' => 'Oyosend SMS Marketing is the bulk SMS app of the Oyosend platform. It sends immediate or scheduled campaigns to contact groups, counts segments for English and Bengali text, estimates cost from your own price per segment, adds an opt-out link to every message, checks consent at send time and controls speed with throttling and a daily cap.',
            'features' => [
                [
                    'title' => 'Bengali-aware segment counter',
                    'body' => 'English text fits 160 characters in one segment and Bengali or other Unicode text 70. The editor names the characters that turn a message into Unicode.',
                ],
                [
                    'title' => 'Cost estimate before launch',
                    'body' => 'Enter your own price per segment and see the estimated segments and cost of a campaign before it is launched.',
                ],
                [
                    'title' => 'Contact groups and CSV import',
                    'body' => 'Build groups from your shared contacts or add them from a CSV of phone numbers. Bangladeshi numbers are recognised in any usual spelling, Bengali digits included.',
                ],
                [
                    'title' => 'Consent and opt-out',
                    'body' => 'Consent and suppression are checked when each message is sent, and every message carries a one-click opt-out link.',
                ],
                [
                    'title' => 'Templates and personalisation',
                    'body' => 'Reusable templates with first name, last name, full name and company name, each with an optional fallback.',
                ],
                [
                    'title' => 'Throttling and daily caps',
                    'body' => 'Set messages per minute and a daily cap. Failed messages are retried a limited number of times, and each recipient\'s status is shown.',
                ],
            ],
            'audience' => [
                'Retailers sending offers and announcements by SMS.',
                'Businesses texting customers in Bengali.',
                'Teams that need to control SMS spend before sending.',
            ],
            'faq' => [
                [
                    'q' => 'How does Oyosend count Bengali SMS segments?',
                    'a' => 'Any character outside the standard GSM set, such as a Bengali letter or an emoji, makes the whole message Unicode: 70 characters for one segment, then 67 per segment. English text fits 160, then 153 per segment. The opt-out line is counted too.',
                ],
                [
                    'q' => 'What do I need before I can send SMS?',
                    'a' => 'An SMS connection must be selected in the Oyosend Communication settings. Until one is connected you can build groups, write templates, draft campaigns and estimate cost, but a campaign cannot be launched, and the app tells you so.',
                ],
                [
                    'q' => 'Can I record my masking sender ID?',
                    'a' => 'Yes. You can record masking (text) and non-masking (number) sender IDs with their registration status and reference. These are your own records: Oyosend does not verify registration with operators, and the sender recipients see is set by the SMS connection.',
                ],
            ],
        ],

        'whatsapp_marketing' => [
            'name' => 'WhatsApp Business',
            'meta_title' => 'WhatsApp Business Inbox and Campaigns',
            'meta_description' => 'Oyosend WhatsApp Business runs approved template campaigns to opted-in customers and a shared team inbox that respects the 24-hour reply window.',
            'tagline' => 'Template campaigns, a shared team inbox and chatbot menus on WhatsApp.',
            'headline' => 'Answer and message customers on WhatsApp as one team',
            'lede' => 'Oyosend WhatsApp Business gives your team a shared inbox for customer chats and sends approved template campaigns to people who opted in, through the official WhatsApp Business Platform (Cloud API).',
            'answer_q' => 'What is Oyosend WhatsApp Business?',
            'answer' => 'Oyosend WhatsApp Business is the WhatsApp app of the Oyosend platform. It uses the official WhatsApp Business Platform (Cloud API) for a shared team inbox with assignment, tags and internal notes, replies inside the 24-hour customer-service window, approved template campaigns to opted-in contacts, quick replies, simple chatbot menus, and delivery and read reports.',
            'features' => [
                [
                    'title' => 'Shared team inbox',
                    'body' => 'Assign conversations to agents, add tags and internal notes, and send free-form replies while the 24-hour window is open.',
                ],
                [
                    'title' => 'Template campaigns',
                    'body' => 'Send approved templates to a contact list or a CRM segment. Only contacts who opted in receive them, and consent is checked again at send time.',
                ],
                [
                    'title' => 'Buttons, lists and media',
                    'body' => 'Inside the window, reply with up to three buttons, a list of up to ten options, a link button, or an image, document, video or audio file.',
                ],
                [
                    'title' => 'Quick replies and chatbot menus',
                    'body' => 'Save quick replies, and build keyword or welcome flows that reply, tag, assign or hand the chat over to a person.',
                ],
                [
                    'title' => 'CRM leads and reminders',
                    'body' => 'Turn new conversations into CRM leads without duplicating contacts, and send order, invoice or payment reminders from templates or workflows.',
                ],
                [
                    'title' => 'Several numbers and reports',
                    'body' => 'Send from more than one business number, and see delivered, read and failed counts, conversation activity and customer ratings.',
                ],
            ],
            'audience' => [
                'Businesses whose customers already message them on WhatsApp.',
                'Sales and support teams sharing one WhatsApp number.',
                'Shops that send order and payment reminders.',
            ],
            'faq' => [
                [
                    'q' => 'Does Oyosend use the official WhatsApp API?',
                    'a' => 'Yes. Oyosend connects through the official WhatsApp Business Platform (Cloud API). Templates are created and approved in Meta\'s WhatsApp Manager, and only approved templates are sent. Until a WhatsApp number is connected, the app shows "not configured" and sends nothing.',
                ],
                [
                    'q' => 'What is the WhatsApp 24-hour window?',
                    'a' => 'When a customer writes to you, WhatsApp lets you reply freely for 24 hours. Oyosend tracks that window for each conversation and allows free-form replies, buttons and media only while it is open. After it closes, only an approved template can be sent.',
                ],
                [
                    'q' => 'How do customers opt out of WhatsApp messages?',
                    'a' => 'A customer can reply STOP, unsubscribe or opt out, or the Bengali words বন্ধ or আনসাবস্ক্রাইব. Oyosend records the opt-out at once, so marketing templates are blocked from the very next message, and confirms it to the customer once.',
                ],
            ],
        ],

        'social' => [
            'name' => 'Social Media',
            'meta_title' => 'Social Media Scheduling and Inbox Software',
            'meta_description' => 'Oyosend Social Media plans, approves and schedules posts on a shared calendar, answers Facebook comments and messages, and reports Page performance.',
            'tagline' => 'Plan, approve and schedule posts, and answer comments and messages.',
            'headline' => 'Plan, approve and publish social posts from one calendar',
            'lede' => 'Oyosend Social Media brings your content calendar, approvals, media library and Facebook Page conversations into one workspace, with reports built only from what each platform returns.',
            'answer_q' => 'What is Oyosend Social Media?',
            'answer' => 'Oyosend Social Media is the social media app of the Oyosend platform. It plans posts on a shared calendar, routes them through approval, schedules them with checks for each platform, and publishes photos, videos and Reels to Facebook Pages. It also answers Facebook comments and Messenger and Instagram messages, captures Facebook lead forms and reports Page performance.',
            'features' => [
                [
                    'title' => 'Calendar and media library',
                    'body' => 'See every post on one calendar, keep photos and videos in a shared library, write a different version of the text for each account, and schedule in bulk from CSV.',
                ],
                [
                    'title' => 'Approval before publishing',
                    'body' => 'Require a second person to approve posts. Any change to an approved post sends it back for approval.',
                ],
                [
                    'title' => 'Facebook photos, video and Reels',
                    'body' => 'Publish several photos, videos and Reels to Facebook Pages, with size and length checks before a post can be scheduled.',
                ],
                [
                    'title' => 'Comments and messages inbox',
                    'body' => 'Reply to, hide or assign Facebook comments, set keyword rules in Bengali or English, and answer Messenger and Instagram messages within 24 hours.',
                ],
                [
                    'title' => 'Lead forms to CRM',
                    'body' => 'Facebook lead form answers go to the CRM with a field mapping you choose, and every lead has a processing log.',
                ],
                [
                    'title' => 'Page performance and AI help',
                    'body' => 'Track followers, reach and best posting times. With the AI Assistant app, get caption, hashtag and translation suggestions that you accept yourself.',
                ],
            ],
            'audience' => [
                'Businesses that run Facebook Pages and want posts approved first.',
                'Teams that answer comments and messages together.',
                'Marketers who plan content for several accounts.',
            ],
            'faq' => [
                [
                    'q' => 'Which social networks can Oyosend publish to?',
                    'a' => 'Oyosend has publishing connections for Facebook Pages, Instagram professional accounts, LinkedIn profiles, X, TikTok, YouTube and Pinterest. LinkedIn and X posts are text only, while TikTok and YouTube take video. Each network becomes available once its connection is configured and approved by that network.',
                ],
                [
                    'q' => 'Can my team approve posts before they go live?',
                    'a' => 'Yes. When approval is switched on, a post is submitted and then approved by someone who is neither its author nor the person who submitted it. Changing the text, accounts, media or link afterwards returns it to draft, so what is published is what was approved.',
                ],
                [
                    'q' => 'Does the AI write and publish posts on its own?',
                    'a' => 'No. With the AI Assistant app, the composer suggests captions, hashtags, rewrites, shorter versions and English or Bengali translations. A person accepts each suggestion, the post is marked as AI-assisted, and the AI never schedules or publishes anything.',
                ],
            ],
        ],

        'meta_ads' => [
            'name' => 'Meta Ads',
            'meta_title' => 'Facebook and Instagram Ads Reporting',
            'meta_description' => 'Oyosend Meta Ads shows what your Facebook and Instagram ads spend and deliver, with trends and CSV export, and lets you pause or adjust budgets safely.',
            'tagline' => 'Facebook and Instagram ad spend, results and safe budget changes.',
            'headline' => 'See what your Facebook and Instagram ads spend and deliver',
            'lede' => 'Oyosend Meta Ads connects your ad accounts, syncs campaigns and daily results every hour, and lets permitted people pause, resume or adjust a budget within a ceiling you set.',
            'answer_q' => 'What is Oyosend Meta Ads?',
            'answer' => 'Oyosend Meta Ads is the ad reporting app of the Oyosend platform. It connects Meta ad accounts with Facebook Login, syncs campaigns, ad sets and ads every hour, and shows spend, reach, clicks, leads, cost per lead and trends in each account\'s currency. Permitted people can pause, resume or change an existing budget, always within a ceiling and after confirming.',
            'features' => [
                [
                    'title' => 'Connect with Facebook Login',
                    'body' => 'Authorise once, choose which ad accounts to track and see each account\'s status and currency.',
                ],
                [
                    'title' => 'Hourly campaign sync',
                    'body' => 'Campaigns, ad sets and ads, with their status, objective, budget and schedule, are refreshed every hour.',
                ],
                [
                    'title' => 'Spend and results dashboard',
                    'body' => 'Spend, impressions, reach, clicks, leads, CTR, CPC, CPM and cost per lead, compared with the previous period.',
                ],
                [
                    'title' => 'Safe budget changes',
                    'body' => 'Pause, resume or edit an existing budget only after confirming, below a ceiling you set and within a daily number of changes.',
                ],
                [
                    'title' => 'CSV export and reports',
                    'body' => 'Export performance as a spreadsheet-safe CSV file and use the daily spend and campaign performance reports.',
                ],
                [
                    'title' => 'Clear sync status',
                    'body' => 'Syncing respects Meta\'s rate limits and shows when an account is waiting, needs authorising again or reported a problem.',
                ],
            ],
            'audience' => [
                'Owners who want a clear view of ad spend and results.',
                'Marketers who report campaign results every week.',
                'Teams that need budget changes with a safety limit.',
            ],
            'faq' => [
                [
                    'q' => 'Can Oyosend create or boost Facebook ads?',
                    'a' => 'No. Oyosend Meta Ads reads your ad accounts, campaigns and results, and can pause, resume or change the budget of an existing campaign or ad set. Creating campaigns, ads or audiences and boosting posts are done in Meta\'s own tools.',
                ],
                [
                    'q' => 'How does Oyosend keep budget changes safe?',
                    'a' => 'Changes stay off until your workspace switches them on. Each ad account needs a budget ceiling, an increase is limited per change, there is a daily cap on changes, and Oyosend checks the live value at Meta before applying anything you confirmed.',
                ],
                [
                    'q' => 'Which figures does Oyosend Meta Ads report?',
                    'a' => 'Daily spend, impressions, reach, clicks and leads at account and campaign level, plus CTR, CPC, CPM and cost per lead. Amounts stay in each ad account\'s own currency and are never added up across currencies.',
                ],
            ],
        ],

        'support' => [
            'name' => 'Customer Support',
            'meta_title' => 'Help Desk and Ticketing Software',
            'meta_description' => 'Oyosend Customer Support handles tickets with queues, SLA targets in business hours, canned replies, satisfaction surveys, a knowledge base and live chat.',
            'tagline' => 'Tickets, SLA targets, satisfaction surveys and website live chat.',
            'headline' => 'Answer every customer on time with tickets and SLA targets',
            'lede' => 'Oyosend Customer Support routes each request to the right queue and agent, tracks response and resolution targets in business hours, and asks customers how you did.',
            'answer_q' => 'What is Oyosend Customer Support?',
            'answer' => 'Oyosend Customer Support is the help desk app of the Oyosend platform. It numbers tickets linked to shared contacts, routes them with queues, round-robin and rules, tracks SLA targets on business-hours calendars, and offers canned responses, satisfaction surveys, an internal knowledge base, merge and split, a website live chat widget and support reports.',
            'features' => [
                [
                    'title' => 'Tickets on the customer record',
                    'body' => 'Gap-free ticket numbers, priorities, tags and a full history, linked to your shared contacts and companies.',
                ],
                [
                    'title' => 'Queues and assignment rules',
                    'body' => 'Team queues with round-robin or rules by channel, priority, category, company or a keyword in the subject.',
                ],
                [
                    'title' => 'SLA targets in business hours',
                    'body' => 'First-response and resolution targets per priority, counted on your working hours and holidays, and paused while you wait for the customer.',
                ],
                [
                    'title' => 'Replies, notes and canned text',
                    'body' => 'Public replies, internal notes the customer never sees, private attachments and canned responses with safe placeholders.',
                ],
                [
                    'title' => 'Surveys and knowledge base',
                    'body' => 'Customers rate the help from 1 to 5 through a single-use link, and agents share answers in an internal knowledge base with versions.',
                ],
                [
                    'title' => 'Website live chat',
                    'body' => 'Add a chat widget to your website, answer visitors from the Live chat page or the shared inbox, and save a visitor as a contact or turn a chat into a ticket.',
                ],
            ],
            'audience' => [
                'Businesses handling customer requests on several channels.',
                'Support teams with response-time promises to keep.',
                'Managers who track satisfaction and backlog.',
            ],
            'faq' => [
                [
                    'q' => 'How does Oyosend measure SLA?',
                    'a' => 'Each policy sets first-response and resolution targets per priority in minutes, counted on a business-hours calendar with holidays and your timezone. Once a ticket has been answered, the clock pauses while it waits for the customer, and a breach is recorded once.',
                ],
                [
                    'q' => 'Can I add live chat to my website with Oyosend?',
                    'a' => 'Yes. Switch the chat on, list the websites allowed to show it and paste one script tag into your pages. Visitors chat in a small window, and your team answers from the Live chat page or the shared inbox.',
                ],
                [
                    'q' => 'Who can see which support tickets?',
                    'a' => 'Ticket permissions can be limited to a person\'s own tickets, their department, their branch or all tickets. Agents in a queue also see its unassigned tickets so they can pick them up, and internal notes are never shown to customers.',
                ],
            ],
        ],

        'communication' => [
            'name' => 'Communication',
            'meta_title' => 'Unified Inbox and Customer Messaging',
            'meta_description' => 'Oyosend Communication gives your team one inbox for WhatsApp, Facebook, Instagram, website chat and tickets, plus notifications, templates and consent.',
            'tagline' => 'One inbox, notifications, message templates and consent in one place.',
            'headline' => 'Every customer conversation and notification in one place',
            'lede' => 'Oyosend Communication brings WhatsApp chats, Facebook comments and messages, Instagram messages, website chat and support tickets into one inbox, and keeps every interaction on the customer\'s timeline.',
            'answer_q' => 'What is Oyosend Communication?',
            'answer' => 'Oyosend Communication is the messaging foundation of the Oyosend platform. It provides a notification centre for the team, email, SMS and WhatsApp sending with retries, message templates in English and Bengali, consent and suppression lists with one-click unsubscribe, a timeline of every customer interaction, and a unified inbox for conversations from the other apps.',
            'features' => [
                [
                    'title' => 'Unified inbox',
                    'body' => 'Read and answer WhatsApp chats, Facebook comment threads, Messenger and Instagram messages, website chats and support tickets from one list.',
                ],
                [
                    'title' => 'Suggested replies',
                    'body' => 'With the AI Assistant app, get a suggested reply in the customer\'s language that you check, edit and send yourself.',
                ],
                [
                    'title' => 'Notification centre',
                    'body' => 'Each team member gets in-app notifications and chooses, per category, whether email copies are sent too.',
                ],
                [
                    'title' => 'Templates in English and Bengali',
                    'body' => 'Email, SMS and WhatsApp templates for each language, with a fixed list of safe placeholders such as the contact\'s first name.',
                ],
                [
                    'title' => 'Consent, suppression and unsubscribe',
                    'body' => 'Record opt-ins with evidence, block bounced or unsubscribed addresses, and hold marketing messages during your quiet hours.',
                ],
                [
                    'title' => 'Customer timeline',
                    'body' => 'Messages, calls, meetings and notes are kept on the contact or company timeline for the people allowed to see them.',
                ],
            ],
            'audience' => [
                'Teams that answer customers on several channels.',
                'Businesses that need consent records for marketing.',
                'Managers who want one view of every customer conversation.',
            ],
            'faq' => [
                [
                    'q' => 'Which channels appear in the Oyosend unified inbox?',
                    'a' => 'WhatsApp conversations, Facebook comment threads, Messenger and Instagram messages, website live chats and support tickets, each when its app is installed. A person sees only the conversations their permissions allow, and replies still follow each channel\'s rules, such as the WhatsApp 24-hour window.',
                ],
                [
                    'q' => 'How does Oyosend handle marketing consent?',
                    'a' => 'No record means no consent. An opt-in needs evidence, and every marketing message is checked against consent and the suppression list when it is queued and again when it is sent. Unsubscribe links work with one click and keep working even if an app is later removed.',
                ],
                [
                    'q' => 'Which Oyosend apps use Communication?',
                    'a' => 'Email Marketing, SMS Marketing and WhatsApp Business send through it, and the unified inbox also lists Social Media and Customer Support conversations. Workflow Automation can send transactional templates through it as well.',
                ],
            ],
        ],

        'telephony' => [
            'name' => 'Telephony',
            'meta_title' => 'Browser Softphone, Dialer and Call Log',
            'meta_description' => 'Oyosend Telephony connects your own phone system to the workspace: a browser softphone, a dialer, click-to-call from contacts and a call log on each customer.',
            'tagline' => 'A browser softphone, dialer, click-to-call and call log on your own phone system.',
            'headline' => 'Call customers from the workspace and keep every call on their record',
            'lede' => 'Oyosend Telephony connects to your company\'s own phone system. Team members call and answer in the browser or through their own phone, and every call is logged against the matching customer.',
            'answer_q' => 'What is Oyosend Telephony?',
            'answer' => 'Oyosend Telephony is the calling app of the Oyosend platform. It works with your company\'s own phone system (an Asterisk or FreeSWITCH PBX on a SIP line from a licensed IPTSP) and gives each team member a browser softphone, a dialer and click-to-call from contact records, or rings their own phone first and then the customer. Every call is logged against the matching shared contact, shows on that contact\'s record and is counted in a calls-by-member report. Calls are not recorded.',
            'features' => [
                [
                    'title' => 'Softphone in the browser',
                    'body' => 'Each member signs in to their own extension and makes or answers calls in the browser, with mute and keypad tones, one call at a time.',
                ],
                [
                    'title' => 'Dialer for Bangladeshi numbers',
                    'body' => 'A keypad and number box that accept a number in any common spelling, Bengali digits included, and put it in the international format.',
                ],
                [
                    'title' => 'Click-to-call from contacts',
                    'body' => 'The Call action on a contact\'s record opens the dialer with that contact\'s number already filled in.',
                ],
                [
                    'title' => 'Call through your own phone',
                    'body' => 'For a member without a headset, the phone system rings their own mobile or desk phone first and then connects the customer.',
                ],
                [
                    'title' => 'Call log on the customer',
                    'body' => 'Direction, status, number, times and duration of every call, matched to the shared contact and shown on that contact\'s record.',
                ],
                [
                    'title' => 'Reports and automation',
                    'body' => 'A report counts calls by team member, and a finished call can start a workflow in Workflow Automation.',
                ],
            ],
            'audience' => [
                'Sales and support teams who phone customers every day.',
                'Businesses that run, or plan to run, their own office phone system.',
                'Managers who want each call next to the customer it was with.',
            ],
            'faq' => [
                [
                    'q' => 'What do I need to make calls with Oyosend Telephony?',
                    'a' => 'Your own phone system: an Asterisk or FreeSWITCH PBX connected to a SIP line from a licensed IPTSP in Bangladesh. Oyosend does not sell phone lines or run the PBX for you. An administrator enters the phone system\'s details in the Telephony settings and gives each member an extension; until then, no call is placed.',
                ],
                [
                    'q' => 'Does Oyosend record phone calls?',
                    'a' => 'No. Telephony keeps a log of each call, with its direction, number, status, times, duration and the matching contact, but it stores no audio recording.',
                ],
                [
                    'q' => 'Who can see the call log in Oyosend?',
                    'a' => 'Each call belongs to the member who made or took it. Each role decides whether people see only their own calls, their department\'s, their branch\'s or everyone\'s, and the same limit applies to calls shown on a contact\'s record.',
                ],
            ],
        ],

        'projects' => [
            'name' => 'Project Management',
            'meta_title' => 'Project Management Software for Teams',
            'meta_description' => 'Oyosend Project Management plans client work with tasks, Kanban, calendar and Gantt views, tracks time and budgets, and shares progress with clients.',
            'tagline' => 'Tasks, Kanban and Gantt views, time tracking, budgets and client sharing.',
            'headline' => 'Deliver client projects on time and within budget',
            'lede' => 'Oyosend Project Management keeps the tasks, deadlines, time and costs of every project in one place, and lets clients follow progress through a secure invitation.',
            'answer_q' => 'What is Oyosend Project Management?',
            'answer' => 'Oyosend Project Management is the project app of the Oyosend platform. It plans projects from templates, breaks work into tasks with subtasks, checklists, dependencies, milestones and sprints, and shows them as a list, Kanban board, calendar or Gantt chart. Teams log and approve time and expenses against budgets, and clients can follow progress through a secure portal.',
            'features' => [
                [
                    'title' => 'Tasks in four views',
                    'body' => 'Subtasks, checklists, several assignees, watchers, reminders and recurring tasks, in list, Kanban, calendar and Gantt views.',
                ],
                [
                    'title' => 'Dependencies and milestones',
                    'body' => 'Link tasks that depend on each other, with circular links refused, and group work into milestones and sprints.',
                ],
                [
                    'title' => 'Project templates',
                    'body' => 'Start a new project from a template that copies its task plan, so repeat work begins from a plan you already trust.',
                ],
                [
                    'title' => 'Time tracking and approval',
                    'body' => 'Log time with a timer or by hand, submit it for approval and see workload across the team. Nobody approves their own time.',
                ],
                [
                    'title' => 'Budgets and expenses',
                    'body' => 'Track budgets, expenses, billable rates and cost rates for each project in its currency, with profitability from approved figures.',
                ],
                [
                    'title' => 'Client portal',
                    'body' => 'Invite a client to see the project summary and, if you allow it, the budget and what has been spent. Internal comments stay hidden.',
                ],
            ],
            'audience' => [
                'Agencies and consultancies running client projects.',
                'Teams that bill clients for approved hours.',
                'Managers who need to see workload and deadlines.',
            ],
            'faq' => [
                [
                    'q' => 'Can I bill project time with Oyosend?',
                    'a' => 'Yes. Approved billable time goes to the Invoicing app, which groups unbilled hours by project and currency into a draft invoice with one line per rate. Each time entry is billed only once.',
                ],
                [
                    'q' => 'Can clients see their project in Oyosend?',
                    'a' => 'Yes. You can invite a client contact through a secure link that expires. They see the published project summary and, only if you allow it, the budget, amount spent and remaining budget. Internal comments stay hidden, and access can be revoked at any time.',
                ],
                [
                    'q' => 'Does Oyosend Project Management have a Gantt chart?',
                    'a' => 'Yes. The same task data appears as a list, a Kanban board, a calendar and a Gantt chart. Dates are entered in your workspace timezone, and moving a task on the Kanban board goes through the same checks as editing it.',
                ],
            ],
        ],

        'automation' => [
            'name' => 'Workflow Automation',
            'meta_title' => 'No-Code Workflow Automation for Business',
            'meta_description' => 'Oyosend Workflow Automation builds workflows on a visual canvas that react to events in your apps, wait, branch, ask for approval and call your systems.',
            'tagline' => 'Visual workflows that react to your apps, wait, branch and ask for approval.',
            'headline' => 'Automate routine work across your apps without writing code',
            'lede' => 'Oyosend Workflow Automation lets you draw a workflow on a canvas: start it from an event, a schedule or a webhook, add conditions and approvals, and test it before it goes live.',
            'answer_q' => 'What is Oyosend Workflow Automation?',
            'answer' => 'Oyosend Workflow Automation is the automation app of the Oyosend platform. On a visual canvas you start workflows from events in installed apps, schedules, signed webhooks or a manual run, then add conditions, branches, loops, delays, human approvals and actions. Published versions are fixed, a test run changes nothing, and every run keeps step-by-step logs.',
            'features' => [
                [
                    'title' => 'Visual workflow canvas',
                    'body' => 'Connect triggers, conditions and actions on a canvas. On a phone, the workflow is shown as a readable outline.',
                ],
                [
                    'title' => 'Triggers from every app',
                    'body' => 'Start from events such as a new lead, an overdue invoice or a missed SLA, from a schedule in your timezone, a signed webhook or a manual run.',
                ],
                [
                    'title' => 'Logic without code',
                    'body' => 'Conditions, branches, loops, parallel steps, delays of up to 30 days and data mapping, all chosen from fixed options.',
                ],
                [
                    'title' => 'Human approvals',
                    'body' => 'Pause a workflow until the right person approves or rejects, with a timeout and no self-approval by default.',
                ],
                [
                    'title' => 'Versions and safe test runs',
                    'body' => 'Draft, publish and archive versions. A test run evaluates the logic with sample data and performs no action.',
                ],
                [
                    'title' => 'Run history and retries',
                    'body' => 'See every run step by step, retry failed steps with growing waits, and cancel or retry whole runs.',
                ],
            ],
            'audience' => [
                'Owners who want follow-ups and reminders to happen on time.',
                'Operations teams linking sales, billing and support steps.',
                'Businesses that need an approval before certain steps.',
            ],
            'faq' => [
                [
                    'q' => 'What can Oyosend Workflow Automation do?',
                    'a' => 'It reacts to events in your installed apps and runs their actions, for example creating a CRM lead from a new WhatsApp conversation, sending an approved WhatsApp template when an invoice is issued, or assigning a support ticket. It can also wait, branch, ask for approval and call your own systems over HTTPS.',
                ],
                [
                    'q' => 'Is it safe to let workflows act on our data?',
                    'a' => 'A workflow runs as the person who published it and only with their permissions, checked again before every step. If that person loses a permission or an app is removed, the next step stops. Workflows never run code, only fixed actions the apps provide.',
                ],
                [
                    'q' => 'Can I test a workflow before publishing it?',
                    'a' => 'Yes. A test run uses a sample payload and evaluates conditions, branches, loops and mappings for real, but performs no action, webhook or approval and sends nothing. Action inputs are still validated, so you see problems before going live.',
                ],
            ],
        ],

        'ai' => [
            'name' => 'AI Assistant',
            'meta_title' => 'AI Business Assistant for Your Workspace',
            'meta_description' => 'Oyosend AI Assistant answers from the records you may see, summarises and drafts replies, and proposes changes that apply only after you confirm them.',
            'tagline' => 'A private assistant that answers, drafts and summarises, and never changes data alone.',
            'headline' => 'A private AI assistant that works only with what you may see',
            'lede' => 'Oyosend AI Assistant answers questions about your contacts and companies, drafts and rewrites text, and suggests changes that a person always confirms first.',
            'answer_q' => 'What is Oyosend AI Assistant?',
            'answer' => 'Oyosend AI Assistant is the AI app of the Oyosend platform. It is a private chat that answers from the records the signed-in person may see, summarises contacts and companies, drafts and rewrites replies, and proposes changes that apply only after a person confirms them. Use is capped by a monthly token allowance, with a usage page.',
            'features' => [
                [
                    'title' => 'Private assistant chat',
                    'body' => 'Each conversation belongs to one person and sees only the records their permissions and record scope allow.',
                ],
                [
                    'title' => 'Summaries and reply drafts',
                    'body' => 'Summarise a contact or company, draft a reply in a neutral, friendly or formal tone, or rewrite your own text.',
                ],
                [
                    'title' => 'Changes you confirm',
                    'body' => 'When the assistant suggests creating or updating a contact, it shows each value first, and nothing is saved until you confirm.',
                ],
                [
                    'title' => 'Help inside other apps',
                    'body' => 'Suggested replies in the shared inbox and in WhatsApp, and caption, hashtag and translation suggestions in Social Media.',
                ],
                [
                    'title' => 'Cost guardrails',
                    'body' => 'A monthly token allowance, per-person rate limits and a usage page keep spending predictable.',
                ],
                [
                    'title' => 'Privacy controls',
                    'body' => 'Switch features on or off, choose which data the assistant may read, set how long chats are kept, and mask emails and phone numbers.',
                ],
            ],
            'audience' => [
                'Teams that write many customer replies every day.',
                'Managers who want a quick summary of a customer.',
                'Businesses that want AI help with clear limits.',
            ],
            'faq' => [
                [
                    'q' => 'Can Oyosend AI change my data by itself?',
                    'a' => 'No. Anything that would change data is shown as a proposal with the exact values, and it applies only after a person with the right permission confirms it. The assistant cannot touch payments, payroll, roles or permissions.',
                ],
                [
                    'q' => 'What data can the Oyosend AI Assistant read?',
                    'a' => 'Only what the signed-in person may see. By default it reads contacts and companies; other data, such as WhatsApp conversations, is added only when the workspace switches it on. Record content is passed to the model as data, never as instructions.',
                ],
                [
                    'q' => 'Does the AI Assistant work in Bengali?',
                    'a' => 'Yes. Suggested replies follow the language of the customer\'s message, so a message in Bengali gets a draft in Bengali, and the Social Media composer can translate posts between English and Bengali. The app\'s own screens are available in both languages.',
                ],
            ],
        ],

        'reports' => [
            'name' => 'Reports & Analytics',
            'meta_title' => 'Business Reports Across All Your Apps',
            'meta_description' => 'Oyosend Reports & Analytics runs, saves, shares and exports reports from every app you use, and each person sees only the data their permissions allow.',
            'tagline' => 'One catalogue of reports from your apps, with saved views and safe CSV export.',
            'headline' => 'One place to run every report your business needs',
            'lede' => 'Oyosend Reports & Analytics collects the reports your installed apps publish, lets you pick columns, filters and dates, and shares saved reports without showing anyone more than they may see.',
            'answer_q' => 'What is Oyosend Reports & Analytics?',
            'answer' => 'Oyosend Reports & Analytics is the reporting app of the Oyosend platform. It brings the reports published by your installed apps, such as sales pipeline, receivables, attendance, payroll totals, support SLA and campaign delivery, into one catalogue. You choose columns, filters and dates, save and share setups, and export CSV files that respect each person\'s permissions.',
            'features' => [
                [
                    'title' => 'One report catalogue',
                    'body' => 'Reports from CRM, Invoicing, HR, Payroll, Talent, Projects, Support, the marketing apps, Social Media, Meta Ads and Automation appear as each app is installed.',
                ],
                [
                    'title' => 'Columns, filters and dates',
                    'body' => 'Pick the columns you need, filter by the options each report offers and choose any date range of up to 366 days.',
                ],
                [
                    'title' => 'Saved and shared reports',
                    'body' => 'Save a report for yourself or share it with a role or department. Sharing shares the setup, never the data.',
                ],
                [
                    'title' => 'Permission-aware results',
                    'body' => 'Every report respects the permissions and record scope of the person running it, shared reports included.',
                ],
                [
                    'title' => 'Spreadsheet-safe CSV export',
                    'body' => 'Export UTF-8 files with protection against spreadsheet formulas, and every export is recorded in the audit trail.',
                ],
                [
                    'title' => 'Honest figures',
                    'body' => 'Money stays in its own currency, and a figure an app could not report is left empty instead of being shown as zero.',
                ],
            ],
            'audience' => [
                'Owners who want figures from every app in one place.',
                'Managers who share the same report with their team.',
                'Accounts and HR staff who export data to spreadsheets.',
            ],
            'faq' => [
                [
                    'q' => 'Which reports does Oyosend Reports & Analytics include?',
                    'a' => 'The catalogue shows the reports of the apps your workspace has installed, for example sales pipeline, lead sources, receivables ageing, revenue, headcount, attendance, leave, payroll run totals, recruitment funnel, support SLA and satisfaction, and email, SMS and WhatsApp campaign delivery.',
                ],
                [
                    'q' => 'If I share a report, can others see my data?',
                    'a' => 'No. Sharing a saved report shares its columns, filters and date range only. Whoever opens it runs it with their own permissions and record scope, so a salesperson limited to their own deals sees only those deals.',
                ],
                [
                    'q' => 'Can I export Oyosend reports to Excel?',
                    'a' => 'Yes, as CSV, which Excel and other spreadsheets open directly. Files are UTF-8 so Bengali text stays intact, are protected against formula injection and name the unit of each number. Each export is recorded in the audit trail.',
                ],
            ],
        ],

        'notes' => [
            'name' => 'Notes',
            'meta_title' => 'Team Notes Linked to Customers',
            'meta_description' => 'Oyosend Notes captures short notes, pins the important ones and links them to your contacts and companies, so customer details stay with the customer.',
            'tagline' => 'Quick notes you can pin, colour and link to contacts and companies.',
            'headline' => 'Keep quick notes next to the customers they are about',
            'lede' => 'Oyosend Notes is a simple board for short notes: write one, pin what matters and attach it to a contact or company so the whole team finds it with the customer.',
            'answer_q' => 'What is Oyosend Notes?',
            'answer' => 'Oyosend Notes is the note-taking app of the Oyosend platform. Team members write short notes with an optional title, pin the important ones, give them a colour and search them. A note can be linked to a shared contact or company, and notes linked to a contact appear on that contact\'s record. Each note follows its author\'s access scope.',
            'features' => [
                [
                    'title' => 'Quick note board',
                    'body' => 'Type a note in the box at the top and see your notes as cards, with pinned notes kept above the rest.',
                ],
                [
                    'title' => 'Pin and colour',
                    'body' => 'Pin the notes that matter and give each one a colour to group them at a glance.',
                ],
                [
                    'title' => 'Search',
                    'body' => 'Search your notes from the board to find the one you need.',
                ],
                [
                    'title' => 'Linked to customers',
                    'body' => 'Attach a note to a contact or company. Notes linked to a contact appear on that contact\'s record for the team.',
                ],
                [
                    'title' => 'Ownership and access',
                    'body' => 'Each note belongs to its author, and access can cover your own notes, a department, a branch or everyone.',
                ],
                [
                    'title' => 'Reports and automation',
                    'body' => 'A report counts notes by author, and a new note can start a workflow in Workflow Automation.',
                ],
            ],
            'audience' => [
                'Sales and support staff jotting down customer details.',
                'Managers keeping reminders next to customer records.',
                'Small teams that want shared notes without another tool.',
            ],
            'faq' => [
                [
                    'q' => 'Can notes be linked to a customer in Oyosend?',
                    'a' => 'Yes. A note can be linked to a shared contact or company. Notes linked to a contact appear on that contact\'s record, where team members who may manage notes can also add new ones.',
                ],
                [
                    'q' => 'Who can see my notes in Oyosend?',
                    'a' => 'A note belongs to the person who wrote it. Each role decides whether people see only their own notes, their department\'s, their branch\'s or everyone\'s, and the same scope applies to editing, pinning and deleting.',
                ],
                [
                    'q' => 'Does Oyosend Notes work in Bengali?',
                    'a' => 'Yes. You can write notes in Bengali or English, and the Notes screens are available in both languages, like the rest of the Oyosend workspace.',
                ],
            ],
        ],

        'links' => [
            'name' => 'Links',
            'meta_title' => 'Shared Links for Your Team',
            'meta_description' => 'Oyosend Links keeps the websites, tools, email addresses and documents your team uses in one place, with folders, pins, search and filters by kind.',
            'tagline' => 'The websites, tools, email addresses and documents your team uses, in one place.',
            'headline' => 'Keep the links your team uses every day in one place',
            'lede' => 'Oyosend Links is a simple organiser for the websites, online tools, email addresses and documents your team keeps coming back to. Save a link once, put it in a folder, pin the important ones and find any of them by search.',
            'answer_q' => 'What is Oyosend Links?',
            'answer' => 'Oyosend Links is the link organiser of the Oyosend platform. Team members save websites, tools, email addresses and documents with a title, an optional description and an optional folder, pin the ones they use most, and find them by search or by filtering on kind and folder. Each link belongs to the person who saved it and follows their access scope. Links has no place for passwords.',
            'features' => [
                [
                    'title' => 'Every kind of link',
                    'body' => 'Save websites, online tools, email addresses and documents, each with a title and a short description.',
                ],
                [
                    'title' => 'Folders',
                    'body' => 'Put links in folders. The form suggests the folders already in use, and a link\'s folder label filters the page to that folder.',
                ],
                [
                    'title' => 'Pins, search and filters',
                    'body' => 'Pinned links stay above the rest. Search titles, addresses, descriptions and folders, or filter by kind with a count for each.',
                ],
                [
                    'title' => 'Safe addresses',
                    'body' => 'Only web addresses and email addresses are accepted. Web links open in a new tab, and Oyosend fetches nothing from them.',
                ],
                [
                    'title' => 'Ownership and access',
                    'body' => 'Each link belongs to the person who saved it, and access can cover your own links, a department, a branch or everyone.',
                ],
                [
                    'title' => 'Reports and automation',
                    'body' => 'A report counts the links saved by kind, and a newly saved link can start a workflow in Workflow Automation.',
                ],
            ],
            'audience' => [
                'Offices that use the same portals, payment sites and online tools every day.',
                'New team members who need the important addresses in one place.',
                'Small teams that want shared links without another tool.',
            ],
            'faq' => [
                [
                    'q' => 'Can I keep passwords in Oyosend Links?',
                    'a' => 'No. Links has no password or login field, and the form asks you to keep passwords in a password manager. Save only the address, a title and a short description.',
                ],
                [
                    'q' => 'Who can see the links I save in Oyosend?',
                    'a' => 'A link belongs to the person who saved it. Each role decides whether people see only their own links, their department\'s, their branch\'s or everyone\'s, so a link is not private from someone whose role covers everyone.',
                ],
                [
                    'q' => 'What kinds of addresses can I save in Oyosend Links?',
                    'a' => 'Web addresses that start with http or https, for websites, tools and documents, and plain email addresses. Other kinds of addresses are refused, so opening a saved link never runs anything.',
                ],
            ],
        ],
        'calendar' => [
            'name' => 'Calendar',
            'meta_title' => 'Team Calendar with Time Zones',
            'meta_description' => 'Oyosend Calendar gives every member a personal calendar and the team shared ones, with day, week, month, year and schedule views, repeating events, guests, reminders and time zones.',
            'tagline' => 'Your day and your team\'s, in one calendar that works in every time zone.',
            'headline' => 'Plan your day and your team\'s in one calendar',
            'lede' => 'Oyosend Calendar is the calendar inside your workspace. Everyone has their own, the team has shared ones, and you can see when a colleague is busy without seeing what they are doing. Drag events to move them, repeat them, invite teammates and get a reminder before they start.',
            'answer_q' => 'What is Oyosend Calendar?',
            'answer' => 'Oyosend Calendar is the team calendar of the Oyosend platform. Each member has a personal calendar and the workspace can have shared calendars everyone sees. It has day, 4-day, week, month, year and schedule views with drag and drop, repeating events, guests who answer yes, no or maybe, and reminders. Every member can use their own time zone, and personal calendars stay private unless their owner shares them.',
            'features' => [
                [
                    'title' => 'Every view you expect',
                    'body' => 'Day, 4 days, week, month, year and a schedule list, with a small month to jump to any date and keyboard shortcuts for all of them.',
                ],
                [
                    'title' => 'Drag and drop',
                    'body' => 'Draw an event by dragging on the grid, drag it to another time or day, or pull its edge to make it longer.',
                ],
                [
                    'title' => 'Repeating events',
                    'body' => 'Daily, weekly on chosen days, monthly by date or weekday, or yearly, until a date or a number of times. Change one occurrence, this and the following ones, or all of them.',
                ],
                [
                    'title' => 'Guests and reminders',
                    'body' => 'Invite teammates, who see the event on their own calendar and answer. Reminders arrive in the notification centre before an event starts.',
                ],
                [
                    'title' => 'Private by person',
                    'body' => 'A personal calendar is yours. Choose whether teammates see nothing, only when you are busy, or the details, and mark single events private.',
                ],
                [
                    'title' => 'Any time zone',
                    'body' => 'Each member picks a time zone and a first day of the week. A meeting planned in one city shows at the right time everywhere.',
                ],
            ],
            'audience' => [
                'Teams that plan meetings, visits and deadlines together.',
                'Companies with people in more than one city or country.',
                'Offices that want shared calendars for holidays, rooms or events.',
            ],
            'faq' => [
                [
                    'q' => 'Can my manager see my Oyosend calendar?',
                    'a' => 'Only as much as you allow. A personal calendar can show nothing, only when you are busy, or the event details, and a role in the workspace does not change that. Workspace calendars are shared with everyone.',
                ],
                [
                    'q' => 'Does Oyosend Calendar work across time zones?',
                    'a' => 'Yes. Each member chooses a time zone, and every event keeps the zone it was planned in, so a 9:00 meeting in London shows at the right local time in Dhaka and stays at 9:00 London time when the clocks change.',
                ],
                [
                    'q' => 'Does Oyosend Calendar sync with Google Calendar or Outlook?',
                    'a' => 'Not yet. Events live in your Oyosend workspace. Importing, exporting and syncing with other calendars is planned.',
                ],
            ],
        ],
    ],
];
