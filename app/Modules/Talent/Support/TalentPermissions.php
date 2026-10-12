<?php

declare(strict_types=1);

namespace App\Modules\Talent\Support;

/** The permission names the Talent module declares. */
final class TalentPermissions
{
    public const string SELF_VIEW = 'talent.self.view';

    public const string OPENINGS_VIEW = 'talent.openings.view';

    public const string OPENINGS_CREATE = 'talent.openings.create';

    public const string OPENINGS_MANAGE = 'talent.openings.manage';

    public const string CANDIDATES_VIEW = 'talent.candidates.view';

    public const string CANDIDATES_MANAGE = 'talent.candidates.manage';

    public const string APPLICATIONS_VIEW = 'talent.applications.view';

    public const string APPLICATIONS_MANAGE = 'talent.applications.manage';

    public const string APPLICATIONS_HIRE = 'talent.applications.hire';

    public const string INTERVIEWS_VIEW = 'talent.interviews.view';

    public const string INTERVIEWS_MANAGE = 'talent.interviews.manage';

    public const string INTERVIEWS_FEEDBACK = 'talent.interviews.feedback';

    public const string OFFERS_MANAGE = 'talent.offers.manage';

    public const string ONBOARDING_VIEW = 'talent.onboarding.view';

    public const string ONBOARDING_MANAGE = 'talent.onboarding.manage';

    public const string TASKS_COMPLETE = 'talent.tasks.complete';

    public const string PROBATION_VIEW = 'talent.probation.view';

    public const string PROBATION_MANAGE = 'talent.probation.manage';

    public const string CYCLES_MANAGE = 'talent.cycles.manage';

    public const string REVIEWS_VIEW = 'talent.reviews.view';

    public const string REVIEWS_SUBMIT = 'talent.reviews.submit';

    public const string REVIEWS_CALIBRATE = 'talent.reviews.calibrate';

    public const string GOALS_VIEW = 'talent.goals.view';

    public const string GOALS_MANAGE = 'talent.goals.manage';

    public const string COURSES_VIEW = 'talent.courses.view';

    public const string COURSES_MANAGE = 'talent.courses.manage';

    public const string ENROLMENTS_VIEW = 'talent.enrolments.view';

    public const string ENROLMENTS_MANAGE = 'talent.enrolments.manage';

    public const string ANALYTICS_VIEW = 'talent.analytics.view';

    public const string SETTINGS_MANAGE = 'talent.settings.manage';
}
