A request to {{ config('app.name') }} failed with a server error.

Error:    {{ $details['class'] }}
Message:  {{ $details['message'] }}
Where:    {{ $details['where'] }}
When:     {{ $details['time'] }}
Request:  {{ $details['request'] ?? 'none (a console command or queued job)' }}
User:     {{ $details['user'] ?? 'not signed in' }}
Server:   {{ $details['environment'] }}

Further alerts for this same error are held back for {{ $details['quiet_minutes'] }} minutes.
The full entry is in storage/logs on the server.

Stack trace (first lines):
{{ $details['trace'] }}
