{{-- The one look every report PDF shares: the BLIS header, the document's
     title and facts, and a footer with the export time (Philippine time)
     and page numbers. A report fills the "content" section. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>@yield('title')</title>
    <style>
        @page { margin: 92px 38px 54px 38px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2937; line-height: 1.4; }
        header { position: fixed; top: -70px; left: 0; right: 0; height: 52px; border-bottom: 2px solid #1a237e; }
        header img { position: absolute; top: 4px; left: 0; width: 40px; height: 40px; }
        header .brand { position: absolute; top: 8px; left: 50px; }
        header .brand strong { display: block; font-size: 13px; color: #1a237e; letter-spacing: 0.5px; }
        header .brand span { font-size: 8.5px; color: #5f6577; }
        header .kind { position: absolute; top: 14px; right: 0; font-size: 11px; font-weight: bold; color: #1a237e; text-transform: uppercase; letter-spacing: 1px; }
        footer { position: fixed; bottom: -36px; left: 0; right: 0; height: 20px; border-top: 1px solid #d7dbe7; font-size: 8px; color: #5f6577; padding-top: 5px; }

        h1 { margin: 0 0 2px; font-size: 17px; color: #1a237e; }
        .subtitle { margin: 0 0 12px; color: #5f6577; font-size: 10px; }
        h2 { margin: 18px 0 6px; font-size: 12px; color: #1a237e; padding-bottom: 3px; border-bottom: 1px solid #d7dbe7; }
        p { margin: 0 0 6px; }
        .muted { color: #5f6577; }
        .note { color: #5f6577; font-size: 9px; margin-top: 4px; }

        table { width: 100%; border-collapse: collapse; }
        table.facts td { padding: 2px 10px 2px 0; vertical-align: top; border: 0; }
        table.facts td.label { color: #5f6577; width: 72px; white-space: nowrap; }
        table.facts td.value { font-weight: bold; }

        table.stats { margin: 4px 0 2px; }
        table.stats td { border: 1px solid #d7dbe7; background: #f6f7fb; text-align: center; padding: 7px 4px; width: 16%; }
        table.stats .figure { display: block; font-size: 15px; font-weight: bold; color: #1a237e; }
        table.stats .caption { display: block; font-size: 8.5px; color: #5f6577; margin-top: 1px; }

        table.grid th, table.grid td { border: 1px solid #d7dbe7; padding: 5px 6px; vertical-align: top; text-align: center; }
        table.grid th { background: #eceef6; color: #1a237e; font-size: 9px; font-weight: bold; vertical-align: middle; }
        table.grid .text, table.grid th.text { text-align: left; }
        table.grid tr { page-break-inside: avoid; }
        table.grid thead { display: table-header-group; }

        .mark { font-weight: bold; font-size: 12px; }
        .mark-correct { color: #1e6b34; }
        .mark-wrong { color: #a3261e; }
        .mark-blank, .mark-pending { color: #5f6577; }
        .pass { color: #1e6b34; font-weight: bold; }
        .below { color: #8a5a00; font-weight: bold; }
        .tag { display: inline-block; padding: 1px 6px; border-radius: 8px; font-size: 8.5px; font-weight: bold; }
        .tag-easy { background: #e7f4ea; color: #1e6b34; }
        .tag-average { background: #eceef6; color: #3949ab; }
        .tag-hard { background: #fbe9e7; color: #a3261e; }
        @yield('styles')
    </style>
</head>
<body>
    <header>
        <img src="data:image/png;base64,{{ base64_encode(file_get_contents(resource_path('images/report-logo.png'))) }}" alt="">
        <div class="brand">
            <strong>BLIS</strong>
            <span>LNU LLE Platform</span>
        </div>
        <div class="kind">@yield('kind')</div>
    </header>
    <footer>
        Exported from BLIS on {{ \App\Support\Reports\ReportOutput::time(now()) }} (Philippine time)
    </footer>

    @yield('content')
</body>
</html>
