/**
 * বাংলা (Bangladesh) catalogue. Mirrors `../en` key for key; every namespace is
 * typed against the English one, so a missing or extra key fails the typecheck.
 *
 * GLOSSARY - one Bengali term per concept. Follow it in new translations, here
 * and in `lang/bn/*.php` and `lang/bn.json`.
 *
 * Kept as the English word, transliterated the usual Bangladeshi way:
 *   email ইমেইল · password পাসওয়ার্ড · dashboard ড্যাশবোর্ড · profile প্রোফাইল
 *   workspace ওয়ার্কস্পেস · app অ্যাপ · log লগ · audit log / audit trail অডিট লগ
 *   code কোড · sign in সাইন ইন · sign out সাইন আউট · account অ্যাকাউন্ট
 *   admin অ্যাডমিন · admin panel অ্যাডমিন প্যানেল · Super Admin সুপার অ্যাডমিন
 *   link লিংক · settings সেটিংস · theme থিম (light লাইট, dark ডার্ক, system সিস্টেম)
 *   reset রিসেট · update আপডেট · filter ফিল্টার · column কলাম · device ডিভাইস
 *   data ডেটা · access অ্যাক্সেস · support সাপোর্ট · team টিম · menu মেনু
 *   action (a command, a table's actions) অ্যাকশন · platform প্ল্যাটফর্ম
 *   tenant টেন্যান্ট · queue কিউ · job জব · cache ক্যাশ · database ডেটাবেস
 *   module মডিউল · subdomain সাবডোমেইন · tab ট্যাব · record রেকর্ড · entry এন্ট্রি
 *   time zone টাইম জোন · setup (noun) সেটআপ · set up (verb) সেট আপ করুন
 *   authenticator app অথেনটিকেটর অ্যাপ · recovery code রিকভারি কোড
 *   setup key (the two-step secret) সেটআপ কী · lock, locked লক করা · ID আইডি
 *   plan প্ল্যান · subscription সাবস্ক্রিপশন · invoice ইনভয়েস · payment পেমেন্ট
 *   billing বিলিং · trial ট্রায়াল · upgrade আপগ্রেড · gateway গেটওয়ে · credit ক্রেডিট
 *   contact (a person in the directory) কন্টাক্ট · logo লোগো · sign up সাইন আপ
 *   support (the platform's operators) সাপোর্ট টিম · support session সাপোর্ট সেশন
 *   bank transfer ব্যাংক ট্রান্সফার · mobile banking মোবাইল ব্যাংকিং · cheque চেক
 *
 * Translated:
 *   member সদস্য · membership সদস্যপদ · role ভূমিকা · permission অনুমতি
 *   owner (the person) মালিক - but the system role named "Owner" keeps its
 *     stored name in Latin script, like every role name
 *   invitation আমন্ত্রণ · invite আমন্ত্রণ জানান · send an invitation আমন্ত্রণ পাঠান
 *   withdraw / revoke (an invitation) প্রত্যাহার করুন · accept গ্রহণ করুন · join যোগ দিন
 *   security নিরাপত্তা · two-step verification = two-factor authentication দুই-ধাপ যাচাইকরণ
 *   verify যাচাই করুন · authentication code যাচাইকরণ কোড
 *   active সক্রিয় · suspended স্থগিত · pending / open (invitation) অপেক্ষমাণ
 *   expired মেয়াদোত্তীর্ণ (badge), মেয়াদ শেষ (in a sentence) · status অবস্থা
 *   on চালু · off বন্ধ · turn on চালু করুন · turn off বন্ধ করুন
 *   save সংরক্ষণ করুন · cancel বাতিল করুন · close / dismiss বন্ধ করুন · confirm নিশ্চিত করুন
 *   delete মুছে ফেলুন (permanent) · clear মুছুন (a search, filters) · remove সরিয়ে দিন
 *   edit সম্পাদনা করুন · create / generate তৈরি করুন · change পরিবর্তন করুন
 *   switch (workspace) বদলান · manage পরিচালনা · view দেখুন (as a permission: দেখা)
 *   search খুঁজুন (action), অনুসন্ধান (the field) · apply প্রয়োগ করুন
 *   select নির্বাচন করুন · choose বেছে নিন · continue এগিয়ে যান · back ফিরে যান
 *   page পৃষ্ঠা · language ভাষা · currency মুদ্রা · address ঠিকানা
 *   numerals অঙ্কের ধরন · Latin digits ইংরেজি অঙ্ক · Bengali digits বাংলা অঙ্ক
 *   digit অঙ্ক · number সংখ্যা · character (of text) অক্ষর
 *   activity কার্যকলাপ · event ঘটনা · details বিস্তারিত · description বিবরণ
 *   person ব্যক্তি · people (counted) {count} জন · user ব্যবহারকারী · company প্রতিষ্ঠান
 *   valid / invalid সঠিক / সঠিক নয় · sensitive সংবেদনশীল · preview নমুনা
 *   previous পূর্ববর্তী · next পরবর্তী · optional ঐচ্ছিক · never কখনো নয়
 *   branch শাখা · department বিভাগ · company / organisation (a business) প্রতিষ্ঠান
 *   owner of a record দায়িত্বে (যাঁর দায়িত্বে) · scope (of a permission) পরিসর
 *   pay পরিশোধ করুন · paid পরিশোধিত · unpaid অপরিশোধিত · overdue বকেয়া · amount পরিমাণ
 *   due date পরিশোধের শেষ তারিখ · renewal নবায়ন · monthly মাসিক · yearly বার্ষিক
 *   free বিনামূল্যে · unlimited সীমাহীন · limit সীমা · usage ব্যবহার · price মূল্য
 *   approve অনুমোদন করুন · suspend স্থগিত করুন · reactivate আবার সক্রিয় করুন
 *   reason কারণ · note নোট · phone ফোন · website ওয়েবসাইট · country দেশ · city শহর
 *   withdraw (an invoice) প্রত্যাহার করুন · record (a payment) রেকর্ড করুন
 *   grace period অতিরিক্ত সময় · confirm (an email address) নিশ্চিত করুন
 *
 * STYLE
 *   - Formal "আপনি"; buttons and instructions in the "করুন / -উন" imperative.
 *     Other people are referred to with honorific forms (তিনি, তাঁকে, পারবেন).
 *   - Full sentences end with a দাঁড়ি (।); labels, buttons and titles take no
 *     terminal punctuation; no exclamation marks.
 *   - Product and app names stay in Latin script: Oyosend, Google Authenticator,
 *     Microsoft Authenticator, 1Password, CRM, HRM. So do IP, URL and QR.
 *   - `{placeholders}` are code: keep them exactly. Rephrase so that no case
 *     ending has to follow one ("{workspace} ওয়ার্কস্পেসে"); where one must,
 *     attach it with a hyphen ("{total}-এর মধ্যে", "{name}-কে").
 *   - Counters attach directly: "{count}টি কোড", "{count} জন".
 *   - Never type digits: numbers arrive already formatted in the person's own
 *     numerals. A number fixed in the English text is spelt out ("ছয় অঙ্কের
 *     কোড", "বারো অক্ষর") so it reads right with either numeral setting.
 *   - Spelling follows Bangla Academy প্রমিত বানান: এখনো, কোনো, সঙ্গে, আরও, হিসেবে.
 *   - Text is Unicode NFC, like the rest of the repository (য়, ড়, ঢ় are the
 *     base letter + nukta, not the single code points some keyboards type).
 *   - `@` and `|` are vue-i18n syntax and must not appear in a message.
 */
import type { MessageSchema } from '@/Core/i18n';
import billing from './billing';
import appStore from './appStore';
import contacts from './contacts';
import crm from './crm';
import projects from './projects';
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

const bn: MessageSchema = {
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

export default bn;
