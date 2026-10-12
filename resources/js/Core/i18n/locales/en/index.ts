import billing from './billing';
import appStore from './appStore';
import contacts from './contacts';
import crm from './crm';
import projects from './projects';
import invoicing from './invoicing';
import hrm from './hrm';
import talent from './talent';
import payroll from './payroll';
import social from './social';
import automation from './automation';
import support from './support';
import ai from './ai';
import communication from './communication';
import reports from './reports';
import organization from './organization';
import platformAudit from './platformAudit';
import platformBilling from './platformBilling';
import platformPlans from './platformPlans';
import platformSettings from './platformSettings';
import platformWorkspaces from './platformWorkspaces';
import register from './register';
import workspace from './workspace';
import audit from './audit';
import auth from './auth';
import catalogue from './catalogue';
import central from './central';
import common from './common';
import dashboard from './dashboard';
import errors from './errors';
import platform from './platform';
import roles from './roles';
import settings from './settings';
import shell from './shell';
import team from './team';

/**
 * One file per area of the interface, so pages can be worked on
 * independently. Add a namespace here when a module brings its own pages.
 */
export default {
    common,
    shell,
    auth,
    dashboard,
    team,
    roles,
    audit,
    settings,
    platform,
    central,
    catalogue,
    errors,
    organization,
    contacts,
    crm,
    projects,
    invoicing,
    hrm,
    talent,
    payroll,
    social,
    automation,
    support,
    ai,
    communication,
    reports,
    workspace,
    billing,
    appStore,
    register,
    platformWorkspaces,
    platformPlans,
    platformBilling,
    platformAudit,
    platformSettings,
};
