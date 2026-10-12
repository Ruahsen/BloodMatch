import React, { createContext, useContext, useState, useEffect, useCallback, useRef } from 'react'
import { api } from '../services/apiClient'
import { useAuth } from './AuthContext'

const ChatContext = createContext(null)

const MAX_DESKTOP_TABS = 3
const UNREAD_POLL_INTERVAL_MS = 30000

export function ChatProvider({ children }) {
  const { user } = useAuth()
  const [unreadCount, setUnreadCount] = useState(0)
  const [drawerOpen, setDrawerOpen] = useState(false)
  const [openTabs, setOpenTabs] = useState([])
  const pollTimerRef = useRef(null)

  const fetchUnreadCount = useCallback(async () => {
    if (!user) {
      setUnreadCount(0)
      return
    }
    if (document.hidden) return

    try {
      const res = await api.get('/api/chat/unread-count')
      if (typeof res?.unread_count === 'number') {
        setUnreadCount(res.unread_count)
      }
    } catch {
      // Background poll failure handled silently
    }
  }, [user])

  useEffect(() => {
    if (!user) {
      setUnreadCount(0)
      setOpenTabs([])
      return undefined
    }

    fetchUnreadCount()

    const interval = setInterval(fetchUnreadCount, UNREAD_POLL_INTERVAL_MS)
    pollTimerRef.current = interval

    const onVisibilityChange = () => {
      if (!document.hidden) {
        fetchUnreadCount()
      }
    }
    document.addEventListener('visibilitychange', onVisibilityChange)

    return () => {
      clearInterval(interval)
      document.removeEventListener('visibilitychange', onVisibilityChange)
    }
  }, [user, fetchUnreadCount])

  const openConversationTab = useCallback((conv) => {
    if (!conv?.id) return

    setOpenTabs((prev) => {
      const existingIndex = prev.findIndex((t) => t.conversationId === conv.id)
      if (existingIndex !== -1) {
        // Unminimize and move to active focus
        const updated = [...prev]
        updated[existingIndex] = {
          ...updated[existingIndex],
          isMinimized: false,
          recipient: conv.recipient || updated[existingIndex].recipient,
          matchRole: conv.match_role || updated[existingIndex].matchRole || null,
          suggestedChats: conv.suggested_chats || updated[existingIndex].suggestedChats || null,
        }
        return updated
      }

      const isMobile = window.innerWidth < 640
      const limit = isMobile ? 1 : MAX_DESKTOP_TABS

      const newTab = {
        conversationId: conv.id,
        recipient: conv.recipient,
        matchRole: conv.match_role || null,
        suggestedChats: conv.suggested_chats || null,
        isMinimized: false,
      }

      if (prev.length >= limit) {
        // Drop the oldest tab
        return [...prev.slice(prev.length - limit + 1), newTab]
      }
      return [...prev, newTab]
    })
  }, [])

  const openChatWithUser = useCallback(async (recipientId, fallbackInfo = {}) => {
    if (!recipientId) return

    try {
      const res = await api.post('/api/chat/conversations', { recipient_id: Number(recipientId) })
      if (res?.conversation) {
        const conv = res.conversation
        if (!conv.recipient && fallbackInfo) {
          conv.recipient = {
            id: recipientId,
            full_name: fallbackInfo.name || fallbackInfo.full_name || 'Member',
            profile_picture_url: fallbackInfo.avatar || fallbackInfo.profile_picture_url || null,
          }
        }
        openConversationTab(conv)
        setDrawerOpen(false)
      }
    } catch (err) {
      console.error('Failed to open chat with user:', err)
      alert(err.message || 'Could not start conversation.')
    }
  }, [openConversationTab])

  const closeTab = useCallback((conversationId) => {
    setOpenTabs((prev) => prev.filter((t) => t.conversationId !== conversationId))
    fetchUnreadCount()
  }, [fetchUnreadCount])

  const toggleMinimizeTab = useCallback((conversationId) => {
    setOpenTabs((prev) =>
      prev.map((t) => (t.conversationId === conversationId ? { ...t, isMinimized: !t.isMinimized } : t))
    )
  }, [])

  const value = {
    unreadCount,
    setUnreadCount,
    fetchUnreadCount,
    drawerOpen,
    setDrawerOpen,
    openTabs,
    openConversationTab,
    openChatWithUser,
    closeTab,
    toggleMinimizeTab,
  }

  return <ChatContext.Provider value={value}>{children}</ChatContext.Provider>
}

export function useChat() {
  const context = useContext(ChatContext)
  if (!context) {
    return {
      unreadCount: 0,
      setUnreadCount: () => {},
      fetchUnreadCount: () => {},
      drawerOpen: false,
      setDrawerOpen: () => {},
      openTabs: [],
      openConversationTab: () => {},
      openChatWithUser: () => {},
      closeTab: () => {},
      toggleMinimizeTab: () => {},
    }
  }
  return context
}
