<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Perdin {{ $trip->trip_number }}</title>

    <style>
        @include('pdf.partials.business-trip-styles')
    </style>
</head>

<body>
    @include('pdf.partials.business-trip-body')
</body>
</html>