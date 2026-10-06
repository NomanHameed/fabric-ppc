<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are used during authentication for various
    | messages that we need to display to the user. You are free to modify
    | these language lines according to your application's requirements.
    |
    */

    'failed' => 'These credentials do not match our records.',
    'password' => 'The provided password is incorrect.',
    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',
    'inactive' => 'Your account is disabled. Contact the administrator.',

    // The starter kit's auth pages (M-11).
    'pages' => [
        'register' => [
            'title' => 'Create an account',
            'description' => 'Enter your details below. A moderator has to approve the account before you can use the system.',
            'name' => 'Full name',
            'email' => 'Email address',
            'email_placeholder' => 'email@example.com',
            'password' => 'Password',
            'confirm' => 'Confirm password',
            'submit' => 'Create account',
            'approval_notice' => 'New accounts start as pending. You will be able to log in once a moderator approves yours.',
            'have_account' => 'Already have an account?',
            'login' => 'Log in',
        ],
        'login' => [
            'head' => 'Log in',
            'title' => 'Log in to your account',
            'description' => 'Enter your email and password below to log in',
            'email' => 'Email address',
            'email_placeholder' => 'email@example.com',
            'password' => 'Password',
            'forgot' => 'Forgot your password?',
            'remember' => 'Remember me',
            'submit' => 'Log in',
            'passkey' => 'Sign in with a passkey',
            'passkey_loading' => 'Authenticating...',
            'passkey_separator' => 'Or continue with email',
            'no_account' => 'Do not have an account?',
            'register' => 'Create one',
        ],
        'forgot' => [
            'title' => 'Forgot password',
            'description' => 'Enter your email to receive a password reset link',
            'email' => 'Email address',
            'email_placeholder' => 'email@example.com',
            'submit' => 'Email password reset link',
            'or_return' => 'Or, return to',
            'login' => 'log in',
        ],
        'reset' => [
            'title' => 'Reset password',
            'description' => 'Please enter your new password below',
            'email' => 'Email',
            'password' => 'Password',
            'confirm' => 'Confirm password',
            'submit' => 'Reset password',
        ],
        'verify' => [
            'title' => 'Email verification',
            'description' => 'Please verify your email address by clicking on the link we just emailed to you.',
            'sent' => 'A new verification link has been sent to the email address you provided during registration.',
            'resend' => 'Resend verification email',
            'logout' => 'Log out',
        ],
        'two_factor' => [
            'head' => 'Two-factor authentication',
            'code_title' => 'Authentication code',
            'code_description' => 'Enter the authentication code provided by your authenticator application.',
            'use_recovery' => 'login using a recovery code',
            'recovery_title' => 'Recovery code',
            'recovery_description' => 'Please confirm access to your account by entering one of your emergency recovery codes.',
            'use_code' => 'login using an authentication code',
            'recovery_placeholder' => 'Enter recovery code',
            'continue' => 'Continue',
            'or' => 'or you can',
        ],
        'confirm' => [
            'title' => 'Confirm password',
            'description' => 'This is a secure area of the application. Please confirm your password before continuing.',
            'password' => 'Password',
            'submit' => 'Confirm password',
            'passkey' => 'Confirm with passkey',
            'passkey_loading' => 'Confirming...',
            'passkey_separator' => 'Or confirm with password',
        ],
    ],

];
