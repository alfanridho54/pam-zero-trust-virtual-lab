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
        'student_template_vmid' => env('PROXMOX_STUDENT_TEMPLATE_VMID', 9000),
        'student_clone_full' => env('PROXMOX_STUDENT_CLONE_FULL', true),
        'student_storage' => env('PROXMOX_STUDENT_STORAGE'),
        'task_timeout' => env('PROXMOX_TASK_TIMEOUT', 60),
        'student_wait_for_clone' => env('PROXMOX_STUDENT_WAIT_FOR_CLONE', false),
        'vmid_allocation_attempts' => env('PROXMOX_VMID_ALLOCATION_ATTEMPTS', 25),
    ],

    'terminal' => [
        'target_host' => env('PRACTICE_VM_SSH_HOST'),
        'target_port' => env('PRACTICE_VM_SSH_PORT', 22),
        'target_username' => env('PRACTICE_VM_SSH_USERNAME', 'student'),
        'ssh_password' => env('PRACTICE_VM_SSH_PASSWORD'),
        'command_timeout' => env('PRACTICE_VM_COMMAND_TIMEOUT', 10),
        'websocket_url' => env('TERMINAL_WEBSOCKET_URL', 'ws://127.0.0.1:8090'),
        'websocket_ticket_ttl' => env('TERMINAL_WEBSOCKET_TICKET_TTL', 600),
        'ssh_ready_attempts' => env('TERMINAL_SSH_READY_ATTEMPTS', 6),
        'ssh_ready_delay_ms' => env('TERMINAL_SSH_READY_DELAY_MS', 500),
        'ssh_ready_timeout' => env('TERMINAL_SSH_READY_TIMEOUT', 1.0),
    ],

];
