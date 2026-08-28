import { createContext, useContext, useEffect, useState } from 'react'
import { api, clearCsrf } from '../services/apiClient'

const AuthContext = createContext({
  user: null,
  loading: true,
  login: async () => {},
  logout: async () => {},
  refresh: async () => {}
})

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null)
  const [loading, setLoading] = useState(true)

  const refresh = async () => {
    try {
      const data = await api.get('/api/auth/me')
      setUser(data.user)
    } catch {
      setUser(null)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    refresh()
  }, [])

  const login = async (email, password) => {
    const data = await api.post('/api/login', { email, password })
    setUser(data.user)
    return data.user
  }

  const logout = async () => {
    try {
      await api.post('/api/logout')
    } finally {
      setUser(null)
      clearCsrf()
    }
  }

  return (
    <AuthContext.Provider value={{ user, loading, login, logout, refresh }}>
      {children}
    </AuthContext.Provider>
  )
}

export function useAuth() {
  return useContext(AuthContext)
}
