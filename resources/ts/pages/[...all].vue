<template>
  <!--
    Penangkap alamat tak dikenal.

    Isinya satu komponen bersama dengan halaman /pages/misc/not-found. Dua
    halaman 404 yang berbeda rupa di satu aplikasi membingungkan lebih
    daripada 404 itu sendiri.
  -->
  <NotFoundView />
</template>

<route lang="yaml">
meta:
  layout: blank
  action: read
  subject: Auth
</route>
