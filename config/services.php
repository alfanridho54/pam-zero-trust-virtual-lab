<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'proxmox' => [
        'host' => env('PROXMOX_HOST'),
        'node' => env('PROXMOX_NODE'),
        'token_id' => env('PROXMOX_TOKEN_ID'),
        'token_secret' => env('PROXMOX_TOKEN_SECRET'),
        'verify_ssl' => env('PROXMOX_VERIFY_SSL', false),
    ],

    'terminal' => [
        'target_host' => env('PRACTICE_VM_SSH_HOST'),
        'target_port' => env('PRACTICE_VM_SSH_PORT', 22),
        'target_username' => env('PRACTICE_VM_SSH_USERNAME', 'student'),
        'ssh_password' => env('PRACTICE_VM_SSH_PASSWORD'),
        'command_timeout' => env('PRACTICE_VM_COMMAND_TIMEOUT', 10),
    ],

];
