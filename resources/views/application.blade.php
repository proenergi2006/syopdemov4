<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />

  <link rel="icon" href="{{ asset('favicon.ico') }}" type="image/x-icon" />
  <link rel="shortcut icon" href="{{ asset('favicon.ico') }}" type="image/x-icon" />
  <link rel="icon" href="{{ asset('logo-proenergi.png') }}?v=2" type="image/png" />

  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>SYOP v4 Pro Energi</title>
  <link rel="stylesheet" type="text/css" href="{{ asset('loader.css') }}" />
  @vite(['resources/ts/main.ts'])
</head>

<body>
  <div id="app">
    <div id="loading-bg">
      <div class="loading-container">
        <div class="loading-logo">
          <img
            src="{{ asset('logo-proenergi.png') }}"
            alt="Logo Pro Energi"
          />
        </div>

        <div class="loading-title">
          SYOP
        </div>

        <div class="loading-version">
          Version 4.0
        </div>

        <div class="loading-progress">
          <div class="loading-progress-bar"></div>
        </div>

        <div class="loading-status">
          Mohon tunggu...
        </div>
      </div>
    </div>
  </div>
  
  <script>
    const loaderColor = localStorage.getItem('Materio-initial-loader-bg') || '#FFFFFF'
    const primaryColor = localStorage.getItem('Materio-initial-loader-color') || '#9155FD'

    if (loaderColor)
      document.documentElement.style.setProperty('--initial-loader-bg', loaderColor)
    if (primaryColor)
      document.documentElement.style.setProperty('--initial-loader-color', primaryColor)

  </script>
</body>

</html>
