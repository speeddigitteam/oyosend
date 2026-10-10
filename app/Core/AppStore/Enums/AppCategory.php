<?php

declare(strict_types=1);

namespace App\Core\AppStore\Enums;

enum AppCategory: string
{
    case HrmPayroll = 'hrm_payroll';
    case CrmSales = 'crm_sales';
    case ProjectManagement = 'project_management';
    case Invoicing = 'invoicing';
    case SocialMedia = 'social_media';
    case EmailMarketing = 'email_marketing';
    case SmsMarketing = 'sms_marketing';
    case WhatsappMarketing = 'whatsapp_marketing';
    case WorkflowAutomation = 'workflow_automation';
    case AiTools = 'ai_tools';
    case CustomerSupport = 'customer_support';
    case AccountingBilling = 'accounting_billing';
    case Reporting = 'reporting';
    case Productivity = 'productivity';
    case Integrations = 'integrations';
    case Utilities = 'utilities';
}
