import axios from 'axios'

const baseURL = import.meta.env.VITE_API_URL || '/api'

const publicHttp = axios.create({
  baseURL,
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
})

export default publicHttp
