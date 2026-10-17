@php
    $roleLabel = match ($sender?->role) {
        'student' => 'Student' . ($sender?->year_level ? ', year ' . $sender->year_level : ''),
        'admin' => 'Administrator',
        null => 'Account since removed',
        default => 'Teacher',
    };
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; color: #1f2937; line-height: 1.5; }
        .container { max-width: 640px; margin: 0 auto; padding: 20px; }
        .meta { margin: 4px 0; }
        .card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 14px; margin-top: 14px; }
        .message { white-space: pre-wrap; margin: 0; }
        .muted { color: #6b7280; }
    </style>
</head>
<body>
    <div class="container">
        @if ($feedback->type === \App\Models\Feedback::TYPE_PROBLEM && !$reviewer)
            <p>A problem was reported in {{ config('app.name') }}. Problem reports come to you as they are sent; the administrator sees it too.</p>
        @else
            <p>
                {{ $reviewer?->name ?? 'The administrator' }} sent you this {{ strtolower($typeLabel) }}
                from {{ config('app.name') }}'s feedback{{ $feedback->type === \App\Models\Feedback::TYPE_IDEA ? ' to be built' : '' }}.
            </p>
        @endif

        @if ($feedback->developer_note)
            <div class="card">
                <p class="meta"><strong>Note from the administrator</strong></p>
                <p class="message">{{ $feedback->developer_note }}</p>
            </div>
        @endif

        <div class="card">
            <p class="meta"><strong>{{ $typeLabel }}</strong>@if ($feedback->rating) · Satisfaction {{ $feedback->rating }} / 5 @endif</p>
            <p class="message">{{ $feedback->message }}</p>
        </div>

        <div class="card">
            <p class="meta"><strong>From:</strong> {{ $roleLabel }}</p>
            @if ($feedback->may_contact && $sender)
                <p class="meta"><strong>Name:</strong> {{ $sender->name }}</p>
                <p class="meta"><strong>Email:</strong> {{ $sender->email }} (agreed to be contacted; replying to this email reaches them)</p>
            @else
                <p class="meta muted">They did not agree to be contacted, so their name and address are left out. The administrator can see who sent it.</p>
            @endif
            <p class="meta"><strong>Sent:</strong> {{ $feedback->created_at?->timezone(config('app.timezone'))->format('M j, Y g:i A') }}</p>
            @if (!empty($context['page']))<p class="meta"><strong>Page before Settings:</strong> {{ $context['page'] }}</p>@endif
            @if (!empty($context['device']))<p class="meta"><strong>Device:</strong> {{ $context['device'] }}</p>@endif
            @if (!empty($context['screen']))<p class="meta"><strong>Screen:</strong> {{ $context['screen'] }}</p>@endif
            @if (!empty($context['app_version']))<p class="meta"><strong>App version:</strong> {{ $context['app_version'] }}</p>@endif
            @if (!empty($context['user_agent']))<p class="meta muted">{{ $context['user_agent'] }}</p>@endif
        </div>

        <p class="muted">Feedback #{{ $feedback->id }}. The administrator follows it at {{ $adminUrl }}</p>
    </div>
</body>
</html>
