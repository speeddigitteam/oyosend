<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Bengali (Bangladesh). Mirrors lang/en/validation.php key for key.
    |
    | The messages avoid the word "field": ":attribute দিতে হবে।" reads as
    | "ইমেইল দিতে হবে।". Where a case ending has to follow a placeholder it is
    | attached with a hyphen (":attribute-এ", ":max-এর"), because the value
    | substituted at runtime is not known here. Terminology follows the
    | glossary at the top of resources/js/Core/i18n/locales/bn/index.ts.
    |
    */

    'accepted' => ':attribute গ্রহণ করতে হবে।',
    'accepted_if' => ':other :value হলে :attribute গ্রহণ করতে হবে।',
    'active_url' => ':attribute একটি সঠিক URL হতে হবে।',
    'after' => ':attribute :date-এর পরের কোনো তারিখ হতে হবে।',
    'after_or_equal' => ':attribute :date বা তার পরের কোনো তারিখ হতে হবে।',
    'alpha' => ':attribute-এ শুধু অক্ষর থাকতে পারবে।',
    'alpha_dash' => ':attribute-এ শুধু অক্ষর, সংখ্যা, ড্যাশ ও আন্ডারস্কোর থাকতে পারবে।',
    'alpha_num' => ':attribute-এ শুধু অক্ষর ও সংখ্যা থাকতে পারবে।',
    'any_of' => ':attribute সঠিক নয়।',
    'array' => ':attribute একটি অ্যারে হতে হবে।',
    'array_keys' => ':attribute-এ শুধু এই কীগুলো থাকতে পারবে: :values।',
    'ascii' => ':attribute-এ শুধু এক-বাইটের অক্ষর, সংখ্যা ও চিহ্ন থাকতে পারবে।',
    'base64' => ':attribute সঠিক Base64 টেক্সট হতে হবে।',
    'before' => ':attribute :date-এর আগের কোনো তারিখ হতে হবে।',
    'before_or_equal' => ':attribute :date বা তার আগের কোনো তারিখ হতে হবে।',
    'between' => [
        'array' => ':attribute-এ :min থেকে :max-টি আইটেম থাকতে হবে।',
        'file' => ':attribute :min থেকে :max কিলোবাইটের মধ্যে হতে হবে।',
        'numeric' => ':attribute :min থেকে :max-এর মধ্যে হতে হবে।',
        'string' => ':attribute :min থেকে :max অক্ষরের মধ্যে হতে হবে।',
    ],
    'boolean' => ':attribute সত্য বা মিথ্যা হতে হবে।',
    'can' => ':attribute-এ এমন একটি মান আছে যার অনুমতি নেই।',
    'confirmed' => ':attribute ও তার নিশ্চিতকরণ মিলছে না।',
    'contains' => ':attribute-এ একটি আবশ্যক মান নেই।',
    'current_password' => 'পাসওয়ার্ডটি সঠিক নয়।',
    'date' => ':attribute একটি সঠিক তারিখ হতে হবে।',
    'date_equals' => ':attribute ও :date একই তারিখ হতে হবে।',
    'date_format' => ':attribute :format ফরম্যাটে হতে হবে।',
    'decimal' => ':attribute-এ দশমিকের পর :decimal ঘর থাকতে হবে।',
    'declined' => ':attribute প্রত্যাখ্যান করতে হবে।',
    'declined_if' => ':other :value হলে :attribute প্রত্যাখ্যান করতে হবে।',
    'different' => ':attribute ও :other আলাদা হতে হবে।',
    'digits' => ':attribute :digits অঙ্কের হতে হবে।',
    'digits_between' => ':attribute :min থেকে :max অঙ্কের মধ্যে হতে হবে।',
    'dimensions' => ':attribute-এর ছবির মাপ সঠিক নয়।',
    'distinct' => ':attribute-এ একই মান একাধিকবার আছে।',
    'doesnt_contain' => ':attribute-এ এগুলোর কোনোটি থাকতে পারবে না: :values।',
    'doesnt_end_with' => ':attribute এগুলোর কোনোটি দিয়ে শেষ হতে পারবে না: :values।',
    'doesnt_start_with' => ':attribute এগুলোর কোনোটি দিয়ে শুরু হতে পারবে না: :values।',
    'email' => ':attribute একটি সঠিক ইমেইল ঠিকানা হতে হবে।',
    'encoding' => ':attribute :encoding এনকোডিংয়ে হতে হবে।',
    'ends_with' => ':attribute এগুলোর কোনো একটি দিয়ে শেষ হতে হবে: :values।',
    'enum' => 'নির্বাচিত :attribute সঠিক নয়।',
    'exists' => 'নির্বাচিত :attribute সঠিক নয়।',
    'extensions' => ':attribute ফাইলের এক্সটেনশন এগুলোর কোনো একটি হতে হবে: :values।',
    'file' => ':attribute একটি ফাইল হতে হবে।',
    'filled' => ':attribute খালি রাখা যাবে না।',
    'gt' => [
        'array' => ':attribute-এ :value-টির বেশি আইটেম থাকতে হবে।',
        'file' => ':attribute :value কিলোবাইটের বেশি হতে হবে।',
        'numeric' => ':attribute :value-এর বেশি হতে হবে।',
        'string' => ':attribute :value অক্ষরের বেশি হতে হবে।',
    ],
    'gte' => [
        'array' => ':attribute-এ অন্তত :value-টি আইটেম থাকতে হবে।',
        'file' => ':attribute :value কিলোবাইট বা তার বেশি হতে হবে।',
        'numeric' => ':attribute :value বা তার বেশি হতে হবে।',
        'string' => ':attribute :value অক্ষর বা তার বেশি হতে হবে।',
    ],
    'hex_color' => ':attribute একটি সঠিক হেক্সাডেসিমাল রঙের কোড হতে হবে।',
    'image' => ':attribute একটি ছবি হতে হবে।',
    'in' => 'নির্বাচিত :attribute সঠিক নয়।',
    'in_array' => ':attribute :other-এর মধ্যে থাকতে হবে।',
    'in_array_keys' => ':attribute-এ এই কীগুলোর অন্তত একটি থাকতে হবে: :values।',
    'integer' => ':attribute একটি পূর্ণসংখ্যা হতে হবে।',
    'ip' => ':attribute একটি সঠিক IP ঠিকানা হতে হবে।',
    'ipv4' => ':attribute একটি সঠিক IPv4 ঠিকানা হতে হবে।',
    'ipv6' => ':attribute একটি সঠিক IPv6 ঠিকানা হতে হবে।',
    'json' => ':attribute সঠিক JSON হতে হবে।',
    'list' => ':attribute একটি তালিকা হতে হবে।',
    'lowercase' => ':attribute ছোট হাতের অক্ষরে হতে হবে।',
    'lt' => [
        'array' => ':attribute-এ :value-টির কম আইটেম থাকতে হবে।',
        'file' => ':attribute :value কিলোবাইটের কম হতে হবে।',
        'numeric' => ':attribute :value-এর কম হতে হবে।',
        'string' => ':attribute :value অক্ষরের কম হতে হবে।',
    ],
    'lte' => [
        'array' => ':attribute-এ :value-টির বেশি আইটেম থাকতে পারবে না।',
        'file' => ':attribute :value কিলোবাইট বা তার কম হতে হবে।',
        'numeric' => ':attribute :value বা তার কম হতে হবে।',
        'string' => ':attribute :value অক্ষর বা তার কম হতে হবে।',
    ],
    'mac_address' => ':attribute একটি সঠিক MAC ঠিকানা হতে হবে।',
    'max' => [
        'array' => ':attribute-এ :max-টির বেশি আইটেম থাকতে পারবে না।',
        'file' => ':attribute :max কিলোবাইটের বেশি হতে পারবে না।',
        'numeric' => ':attribute :max-এর বেশি হতে পারবে না।',
        'string' => ':attribute :max অক্ষরের বেশি হতে পারবে না।',
    ],
    'max_digits' => ':attribute-এ :max-টির বেশি অঙ্ক থাকতে পারবে না।',
    'mimes' => ':attribute এই ধরনের ফাইল হতে হবে: :values।',
    'mimetypes' => ':attribute এই ধরনের ফাইল হতে হবে: :values।',
    'min' => [
        'array' => ':attribute-এ অন্তত :min-টি আইটেম থাকতে হবে।',
        'file' => ':attribute অন্তত :min কিলোবাইট হতে হবে।',
        'numeric' => ':attribute অন্তত :min হতে হবে।',
        'string' => ':attribute অন্তত :min অক্ষরের হতে হবে।',
    ],
    'min_digits' => ':attribute-এ অন্তত :min-টি অঙ্ক থাকতে হবে।',
    'missing' => ':attribute থাকতে পারবে না।',
    'missing_if' => ':other :value হলে :attribute থাকতে পারবে না।',
    'missing_unless' => ':other :value না হলে :attribute থাকতে পারবে না।',
    'missing_with' => ':values থাকলে :attribute থাকতে পারবে না।',
    'missing_with_all' => ':values-এর সবগুলো থাকলে :attribute থাকতে পারবে না।',
    'multiple_of' => ':attribute :value-এর গুণিতক হতে হবে।',
    'not_in' => 'নির্বাচিত :attribute সঠিক নয়।',
    'not_regex' => ':attribute-এর ফরম্যাট সঠিক নয়।',
    'numeric' => ':attribute একটি সংখ্যা হতে হবে।',
    'password' => [
        'letters' => ':attribute-এ অন্তত একটি অক্ষর থাকতে হবে।',
        'mixed' => ':attribute-এ অন্তত একটি বড় হাতের ও একটি ছোট হাতের অক্ষর থাকতে হবে।',
        'numbers' => ':attribute-এ অন্তত একটি সংখ্যা থাকতে হবে।',
        'symbols' => ':attribute-এ অন্তত একটি চিহ্ন থাকতে হবে।',
        'uncompromised' => 'এই :attribute একটি ডেটা ফাঁসের ঘটনায় পাওয়া গেছে। অনুগ্রহ করে অন্য একটি :attribute বেছে নিন।',
    ],
    'present' => ':attribute থাকতে হবে।',
    'present_if' => ':other :value হলে :attribute থাকতে হবে।',
    'present_unless' => ':other :value না হলে :attribute থাকতে হবে।',
    'present_with' => ':values থাকলে :attribute থাকতে হবে।',
    'present_with_all' => ':values-এর সবগুলো থাকলে :attribute থাকতে হবে।',
    'prohibited' => ':attribute দেওয়া যাবে না।',
    'prohibited_if' => ':other :value হলে :attribute দেওয়া যাবে না।',
    'prohibited_if_accepted' => ':other গ্রহণ করা হলে :attribute দেওয়া যাবে না।',
    'prohibited_if_declined' => ':other প্রত্যাখ্যান করা হলে :attribute দেওয়া যাবে না।',
    'prohibited_unless' => ':other :values-এর মধ্যে না থাকলে :attribute দেওয়া যাবে না।',
    'prohibits' => ':attribute দিলে :other দেওয়া যাবে না।',
    'regex' => ':attribute-এর ফরম্যাট সঠিক নয়।',
    'required' => ':attribute দিতে হবে।',
    'required_array_keys' => ':attribute-এ এগুলোর প্রতিটির জন্য মান থাকতে হবে: :values।',
    'required_if' => ':other :value হলে :attribute দিতে হবে।',
    'required_if_accepted' => ':other গ্রহণ করা হলে :attribute দিতে হবে।',
    'required_if_declined' => ':other প্রত্যাখ্যান করা হলে :attribute দিতে হবে।',
    'required_unless' => ':other :values-এর মধ্যে না থাকলে :attribute দিতে হবে।',
    'required_with' => ':values থাকলে :attribute দিতে হবে।',
    'required_with_all' => ':values-এর সবগুলো থাকলে :attribute দিতে হবে।',
    'required_without' => ':values না থাকলে :attribute দিতে হবে।',
    'required_without_all' => ':values-এর কোনোটিই না থাকলে :attribute দিতে হবে।',
    'same' => ':attribute ও :other একই হতে হবে।',
    'size' => [
        'array' => ':attribute-এ ঠিক :size-টি আইটেম থাকতে হবে।',
        'file' => ':attribute :size কিলোবাইট হতে হবে।',
        'numeric' => ':attribute :size হতে হবে।',
        'string' => ':attribute :size অক্ষরের হতে হবে।',
    ],
    'starts_with' => ':attribute এগুলোর কোনো একটি দিয়ে শুরু হতে হবে: :values।',
    'string' => ':attribute টেক্সট হতে হবে।',
    'timezone' => ':attribute একটি সঠিক টাইম জোন হতে হবে।',
    'unique' => 'এই :attribute আগেই ব্যবহার করা হয়েছে।',
    'uploaded' => ':attribute আপলোড করা যায়নি।',
    'uppercase' => ':attribute বড় হাতের অক্ষরে হতে হবে।',
    'url' => ':attribute একটি সঠিক URL হতে হবে।',
    'ulid' => ':attribute একটি সঠিক ULID হতে হবে।',
    'uuid' => ':attribute একটি সঠিক UUID হতে হবে।',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Custom messages for a given attribute and rule, named "attribute.rule".
    | The entry below is the framework's placeholder, kept so the key tree
    | matches the English file.
    |
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'custom-message',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | Bengali names for the fields this application validates. They replace
    | :attribute (and :other) in the messages above, so each one is a bare
    | noun that reads naturally at the start of a sentence.
    |
    */

    'attributes' => [
        'email' => 'ইমেইল',
        'password' => 'পাসওয়ার্ড',
        'password_confirmation' => 'পাসওয়ার্ড নিশ্চিতকরণ',
        'current_password' => 'বর্তমান পাসওয়ার্ড',
        'name' => 'নাম',
        'code' => 'কোড',
        'recovery_code' => 'রিকভারি কোড',
        'role_id' => 'ভূমিকা',
        'locale' => 'ভাষা',
        'numerals' => 'অঙ্কের ধরন',
        'description' => 'বিবরণ',
        'permissions' => 'অনুমতি',
        'workspace' => 'ওয়ার্কস্পেস',
        'status' => 'অবস্থা',
        'token' => 'টোকেন',
        'search' => 'অনুসন্ধান',

        // Audit log filters.
        'event' => 'ঘটনা',
        'from' => 'শুরুর তারিখ',
        'to' => 'শেষের তারিখ',
    ],

];
