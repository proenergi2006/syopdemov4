<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Claim {{ $claim->claim_number }}</title>

    <style>
        @include('pdf.partials.claim-styles')
    </style>
</head>

<body>
    @include('pdf.partials.claim-body')
</body>
</html>
