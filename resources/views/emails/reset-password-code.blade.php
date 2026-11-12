<x-mail::message>
# Password Reset Code

You recently requested to reset your password for your {{ config('app.name') }} account.

Use the following 6-digit verification code to reset your password:

<x-mail::panel>
# {{ $code }}
</x-mail::panel>

This code will expire in {{ $expiresInMinutes }} minutes.

If you did not request a password reset, no further action is required.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
