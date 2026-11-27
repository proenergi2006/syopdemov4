<!DOCTYPE html>
<html lang="{{ $bahasa ?? 'id' }}">
<head>
    <meta charset="UTF-8">
    <title>{{ trans('bdv_print.title', [], $bahasa ?? 'id') }} {{ $bdv->bdv_number }}</title>

    <style>
        @include('pdf.partials.bdv-styles')
    </style>
</head>

<body>
    @include('pdf.partials.bdv-body')
</body>
</html>
