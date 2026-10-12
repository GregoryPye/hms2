@component('mail::message')
# Hello {{ $fullName }},

Unfortunately your booking for {{ $toolName }} has been cancelled due to an issue with the tool.

Thanks,  
{{ config('branding.community_name') }} Membership Team
@endcomponent
