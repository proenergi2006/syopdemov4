// import auth from './auth.json'
import common from './common.json'
import dashboard from './dashboard.json'
import navigation from './navigation.json'

export default {
  // auth,
  common,
  dashboard,

  /*
   * navigation.json di-spread di root (bukan dinamai `navigation: {...}`)
   * karena komponen sidebar (VerticalNavLink/VerticalNavGroup) memanggil
   * t(item.title) langsung dengan judul menu mentah dari database sebagai
   * key, tanpa prefix -- ini pola bawaan template Materio yang sudah ada,
   * bukan sesuatu yang kita ubah.
   */
  ...navigation,
}
