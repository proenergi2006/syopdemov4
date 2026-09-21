import { escapeHtml } from '@/utils/textFormatter'

/*
|--------------------------------------------------------------------------
| Halaman loading untuk tab cetak
|--------------------------------------------------------------------------
| Tab cetak dibuka lebih dulu supaya tidak diblokir popup blocker, lalu diisi
| halaman ini. Tanpa isi, tab tersebut hanya berupa halaman putih selama
| server merender PDF -- dan indikator di halaman asal tidak terlihat lagi
| karena fokus sudah berpindah ke tab baru.
|
| Halamannya sengaja berdiri sendiri: tab cetak tidak memuat aset aplikasi,
| jadi seluruh gaya harus ikut tertulis di sini.
|--------------------------------------------------------------------------
*/

export const buildPrintLoadingPage = (
  title: string,
  description: string,
  locale = 'id',
): string => {
  const safeTitle = escapeHtml(title)
  const safeDescription = escapeHtml(description)
  const safeLocale = escapeHtml(locale)

  return `<!DOCTYPE html>
<html lang="${safeLocale}">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>${safeTitle}</title>

    <style>
      :root {
        color-scheme: light dark;
      }

      body {
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0;
        background: #f4f5fa;
        color: #2f2b3d;
        font-family: "Public Sans", Arial, sans-serif;
        min-height: 100vh;
      }

      .box {
        padding: 32px 24px;
        text-align: center;
      }

      .spinner {
        width: 46px;
        height: 46px;
        margin: 0 auto 20px;
        border: 4px solid rgba(105, 108, 255, 0.2);
        border-top-color: #696cff;
        border-radius: 50%;
        animation: spin 0.9s linear infinite;
      }

      @keyframes spin {
        to {
          transform: rotate(360deg);
        }
      }

      h3 {
        margin: 0 0 8px;
        font-size: 18px;
        font-weight: 600;
      }

      p {
        margin: 0;
        color: rgba(47, 43, 61, 0.65);
        font-size: 14px;
      }

      @media (prefers-color-scheme: dark) {
        body {
          background: #25293c;
          color: #e1def5;
        }

        p {
          color: rgba(225, 222, 245, 0.65);
        }
      }
    </style>
  </head>

  <body>
    <div class="box">
      <div class="spinner"></div>
      <h3>${safeTitle}</h3>
      <p>${safeDescription}</p>
    </div>
  </body>
</html>`
}
