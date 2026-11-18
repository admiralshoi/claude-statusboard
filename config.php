<?php
// Defaults, committed. This machine's own values (paths, URL, extra guard rules) go in config.local.php,
// which install.sh writes and git ignores. Boards themselves live in the database: create and delete
// them from the index of boards.
return [
    // Where the board is served, without a trailing slash. `sb serve` serves exactly this.
    'base_url' => 'http://127.0.0.1:8787/statusboard',
    // Substring of the model id => context window. Anything not matched uses the default,
    // and a session whose context already exceeds the default is assumed to be on 1M.
    'context_windows' => [
        '[1m]' => 1000000,
        'opus-5' => 1000000,
    ],
    'default_context_window' => 200000,
    // Oldest Claude Code version where the board's typed carriage return submits a message.
    // Older sessions get a real Enter key press instead (needs Accessibility permission for php-fpm).
    'typed_enter_min_version' => '2.1.308',
    // Models the board can switch a session to: the /model argument => its label.
    'models' => ['fable' => 'Fable', 'opus' => 'Opus', 'sonnet' => 'Sonnet', 'haiku' => 'Haiku'],
    // When a switch to the key would cost usage credits (the plan's allowance for it is used up), the board
    // declines Claude Code's credits prompt and switches to this model instead.
    'credit_fallback' => ['fable' => 'opus'],
    // What thread sessions start on unless the thread asks for another (`sb spawn --model fable`): the newest Opus.
    // Fable sessions start with --fallback-model from credit_fallback, so an unavailable Fable starts on Opus instead.
    'default_model' => 'opus',
    // Free disk space the machine itself keeps: below it (or below two copies of a project's last worktree) no new
    // worktree is made until the user frees space or allows one more (Store::diskGate).
    'disk_floor_gb' => 20,
    // Validators of critical work run on this model (with the same fallback): tasks of these tiers, and security or money
    // work, told by its roadmap entry's Area (the `sb log` Area: line, written before every handoff). Everything else is
    // validated on the newest Opus (default_model), Fable threads included. null turns it off.
    // Files a validator may never read, relative to each project's root, on top of the built-in list (SSH, cloud and
    // package-manager credentials, gh, .env files). Set your projects' own secret files in config.local.php.
    'validator_secret_paths' => [],
    'validator_critical_model' => 'fable',
    'validator_critical_tiers' => ['T3'],
    // Area words that mean security or money (matched as whole words, any case): billing, payments, auth and sign-in, security.
    // checkout ... credits: money paths under neighbouring names (apps that bill in tokens or credits).
    'validator_critical_areas' => ['billing', 'payment', 'payments', 'money', 'invoice', 'invoices', 'stripe', 'checkout', 'subscription', 'subscriptions',
        'pricing', 'refund', 'refunds', 'payout', 'payouts', 'tokens', 'credits', 'auth', 'sign-in', 'signin', 'login', 'oauth', 'security', 'permissions'],
];
