import auth from './auth.json'
import common from './common.json'
import dashboard from './dashboard.json'
import navigation from './navigation.json'
import purchaseRequest from './purchaseRequest.json'
import purchaseOrder from './purchaseOrder.json'
import goodsReceive from './goodsReceive.json'
import goodsReturn from './goodsReturn.json'
import vendor from './vendor.json'

export default {
  auth,
  common,
  dashboard,
  purchaseRequest,
  purchaseOrder,
  goodsReceive,
  goodsReturn,
  vendor,

  /*
   * navigation.json di-spread di root (bukan dinamai `navigation: {...}`)
   * karena komponen sidebar (VerticalNavLink/VerticalNavGroup) memanggil
   * t(item.title) langsung dengan judul menu mentah dari database sebagai
   * key, tanpa prefix -- ini pola bawaan template Materio yang sudah ada,
   * bukan sesuatu yang kita ubah.
   */
  ...navigation,
}
