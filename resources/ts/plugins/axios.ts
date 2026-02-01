import axios from 'axios'


const axiosIns = axios.create({
  baseURL: 'http://127.0.0.1:8000/api',
  timeout: 15000,
})

axiosIns.defaults.headers.common.Accept = 'application/json'
axiosIns.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest'

// 🔐 Auto attach token
axiosIns.interceptors.request.use(config => {
  const token = localStorage.getItem('accessToken')
  if (token) {
    config.headers = config.headers || {}
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

// ❌ Handle unauthorized
axiosIns.interceptors.response.use(
  response => response,
  error => {
    if (error.response?.status === 401) {
      localStorage.removeItem('access_token')
    }
    return Promise.reject(error)
  },
)



export default axiosIns
