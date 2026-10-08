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
};
