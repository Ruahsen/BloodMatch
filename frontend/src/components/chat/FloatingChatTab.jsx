import React, { useState, useEffect, useRef, useCallback } from 'react'
import { Link } from 'react-router-dom'
import { X, Minus, CaretUp, PaperPlaneRight, User, ChatCircleDots } from '@phosphor-icons/react'
import { api } from '../../services/apiClient'
import { useAuth } from '../../context/AuthContext'
import { useChat } from '../../context/ChatContext'
import styles from './FloatingChatTab.module.css'

const ACTIVE_POLL_INTERVAL_MS = 3000

function formatTime(isoString) {
  if (!isoString) return ''
  try {
    const d = new Date(isoString.includes('T') ? isoString : `${isoString.replace(' ', 'T')}Z`)
    return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
  } catch {
    return ''
  }
}

export default function FloatingChatTab({ tab, onClose, onToggleMinimize }) {
  const { user } = useAuth()
  const { fetchUnreadCount } = useChat()
  const { conversationId, recipient, isMinimized } = tab

  const [messages, setMessages] = useState([])
  const [suggestedChats, setSuggestedChats] = useState(tab.suggestedChats || null)
  const [suggestedSendingText, setSuggestedSendingText] = useState(null)
  const [inputText, setInputText] = useState('')
  const [sending, setSending] = useState(false)
  const [loading, setLoading] = useState(true)

  const messagesEndRef = useRef(null)
  const lastIdRef = useRef(0)
  const inputRef = useRef(null)

  const scrollToBottom = useCallback((smooth = true) => {
    messagesEndRef.current?.scrollIntoView({ behavior: smooth ? 'smooth' : 'auto' })
  }, [])

  const markAsRead = useCallback(async () => {
    try {
      await api.post(`/api/chat/conversations/${conversationId}/read`)
      fetchUnreadCount()
    } catch {
      // Ignored
    }
  }, [conversationId, fetchUnreadCount])

  // Initial load
  useEffect(() => {
    let mounted = true

    async function loadInitial() {
      try {
        const res = await api.get(`/api/chat/conversations/${conversationId}/messages?limit=50`)
        if (mounted && Array.isArray(res?.messages)) {
          setMessages(res.messages)
          if (res.messages.length > 0) {
            lastIdRef.current = res.messages[res.messages.length - 1].id
            setSuggestedChats(null)
          } else {
            setSuggestedChats(res.suggested_chats || null)
          }
          setLoading(false)
          setTimeout(() => scrollToBottom(false), 50)
          markAsRead()
        }
      } catch (err) {
        if (mounted) setLoading(false)
      }
    }

    loadInitial()

    return () => {
      mounted = false
    }
  }, [conversationId, markAsRead, scrollToBottom])

  // Polling for new messages
  useEffect(() => {
    if (isMinimized) return undefined

    const poll = async () => {
      if (document.hidden) return

      try {
        const afterId = lastIdRef.current
        const res = await api.get(
          `/api/chat/conversations/${conversationId}/messages?after_id=${afterId}&limit=50`
        )
        if (Array.isArray(res?.messages) && res.messages.length > 0) {
          setMessages((prev) => {
            // Deduplicate
            const existingIds = new Set(prev.map((m) => m.id))
            const newMsgs = res.messages.filter((m) => !existingIds.has(m.id))
            if (newMsgs.length === 0) return prev
            return [...prev, ...newMsgs]
          })
          lastIdRef.current = res.messages[res.messages.length - 1].id
          setSuggestedChats(null)
          scrollToBottom(true)
          markAsRead()
        }
      } catch {
        // Handled silently
      }
    }

    const timer = setInterval(poll, ACTIVE_POLL_INTERVAL_MS)
    return () => clearInterval(timer)
  }, [conversationId, isMinimized, markAsRead, scrollToBottom])

  const handleSend = async (e) => {
    e.preventDefault()
    const text = inputText.trim()
    if (!text || sending) return

    const tempId = `temp-${Date.now()}`
    const optimisticMsg = {
      id: tempId,
      conversation_id: conversationId,
      sender_id: user?.id,
      sender_name: user?.full_name || 'You',
      body: text,
      created_at: new Date().toISOString(),
      isOptimistic: true,
    }

    setMessages((prev) => [...prev, optimisticMsg])
    setSuggestedChats(null)
    setInputText('')
    setSending(true)
    scrollToBottom(true)

    try {
      const res = await api.post(`/api/chat/conversations/${conversationId}/messages`, { body: text })
      if (res?.message) {
        setMessages((prev) =>
          prev.map((m) => (m.id === tempId ? res.message : m))
        )
        lastIdRef.current = Math.max(lastIdRef.current, res.message.id)
        markAsRead()
      }
    } catch (err) {
      alert(err.message || 'Failed to send message.')
      setMessages((prev) => prev.filter((m) => m.id !== tempId))
    } finally {
      setSending(false)
      inputRef.current?.focus()
    }
  }

  const handleSendSuggested = async (text) => {
    const cleanText = text?.trim()
    if (!cleanText || sending || suggestedSendingText) return

    setSuggestedSendingText(cleanText)
    setSending(true)

    const tempId = `temp-${Date.now()}`
    const optimisticMsg = {
      id: tempId,
      conversation_id: conversationId,
      sender_id: user?.id,
      sender_name: user?.full_name || 'You',
      body: cleanText,
      created_at: new Date().toISOString(),
      isOptimistic: true,
    }

    setMessages((prev) => [...prev, optimisticMsg])
    setSuggestedChats(null)
    scrollToBottom(true)

    try {
      const res = await api.post(`/api/chat/conversations/${conversationId}/messages`, {
        body: cleanText,
      })
      if (res?.message) {
        setMessages((prev) =>
          prev.map((m) => (m.id === tempId ? res.message : m))
        )
        lastIdRef.current = Math.max(lastIdRef.current, res.message.id)
        markAsRead()
      }
    } catch (err) {
      alert(err.message || 'Failed to send message.')
      setMessages((prev) => prev.filter((m) => m.id !== tempId))
      setSuggestedChats(tab.suggestedChats || null)
    } finally {
      setSending(false)
      setSuggestedSendingText(null)
      inputRef.current?.focus()
    }
  }

  const recipientName = recipient?.full_name || 'Chat'
  const recipientAvatar = recipient?.profile_picture_url
  const [avatarError, setAvatarError] = useState(false)

  useEffect(() => {
    setAvatarError(false)
  }, [recipientAvatar])

  return (
    <div
      className={`${styles.tabWrapper} ${isMinimized ? styles.tabWrapperMinimized : styles.tabWrapperExpanded}`}
      role="region"
      aria-label={`Chat with ${recipientName}`}
    >
      <header className={styles.tabHeader} onClick={onToggleMinimize}>
        <div className={styles.headerLeft}>
          {recipient?.id ? (
            <Link
              to={`/profile/${recipient.id}`}
              className={styles.avatarLink}
              onClick={(e) => e.stopPropagation()}
              title={`View ${recipientName}'s profile`}
              aria-label={`View ${recipientName}'s profile`}
            >
              <div className={styles.avatarWrap}>
                {recipientAvatar && !avatarError ? (
                  <img
                    src={recipientAvatar}
                    alt=""
                    className={styles.avatarImg}
                    onError={() => setAvatarError(true)}
                  />
                ) : (
                  <User size={18} weight="bold" />
                )}
              </div>
            </Link>
          ) : (
            <div className={styles.avatarWrap}>
              {recipientAvatar && !avatarError ? (
                <img
                  src={recipientAvatar}
                  alt=""
                  className={styles.avatarImg}
                  onError={() => setAvatarError(true)}
                />
              ) : (
                <User size={18} weight="bold" />
              )}
            </div>
          )}

          <div className={styles.nameBlock}>
            {recipient?.id ? (
              <Link
                to={`/profile/${recipient.id}`}
                className={styles.recipientName}
                onClick={(e) => e.stopPropagation()}
                title={`View ${recipientName}'s profile`}
              >
                {recipientName}
              </Link>
            ) : (
              <span className={styles.recipientName}>{recipientName}</span>
            )}
            <span className={styles.recipientStatus}>Active now</span>
          </div>
        </div>

        <div className={styles.headerActions} onClick={(e) => e.stopPropagation()}>
          <button
            type="button"
            className={styles.iconBtn}
            onClick={onToggleMinimize}
            aria-label={isMinimized ? 'Expand chat' : 'Minimize chat'}
            title={isMinimized ? 'Expand chat' : 'Minimize chat'}
          >
            {isMinimized ? <CaretUp size={18} weight="bold" /> : <Minus size={18} weight="bold" />}
          </button>
          <button
            type="button"
            className={styles.iconBtn}
            onClick={onClose}
            aria-label="Close chat"
            title="Close chat"
          >
            <X size={18} weight="bold" />
          </button>
        </div>
      </header>

      {!isMinimized && (
        <div className={styles.bodyArea}>
          <div className={styles.messageList}>
            {loading ? (
              <div className={styles.emptyNotice}>Loading conversation…</div>
            ) : messages.length === 0 ? (
              <div className={styles.emptyNotice}>
                <ChatCircleDots size={32} className={styles.emptyNoticeIcon} />
                <span>No messages yet. Say hello to {recipientName}!</span>
              </div>
            ) : (
              messages.map((m) => {
                const isSentByMe = m.sender_id === user?.id
                return (
                  <div
                    key={m.id}
                    className={`${styles.messageRow} ${
                      isSentByMe ? styles.messageRowSent : styles.messageRowReceived
                    }`}
                  >
                    <div
                      className={`${styles.bubble} ${
                        isSentByMe ? styles.bubbleSent : styles.bubbleReceived
                      } ${m.isOptimistic ? styles.bubbleOptimistic : ''}`}
                    >
                      {m.body}
                    </div>
                    <span className={styles.msgTime}>
                      {m.isOptimistic ? 'Sending…' : formatTime(m.created_at)}
                    </span>
                  </div>
                )
              })
            )}
            <div ref={messagesEndRef} />
          </div>

          {/* Suggested Chats (Quick Replies) for Matched Users */}
          {!loading && messages.length === 0 && suggestedChats && suggestedChats.length > 0 && (
            <div className={styles.suggestedContainer} role="region" aria-label="Suggested messages">
              <div className={styles.suggestedHeader}>
                <span>Suggested messages</span>
              </div>
              <div className={styles.suggestedList}>
                {suggestedChats.map((text, idx) => (
                  <button
                    key={idx}
                    type="button"
                    className={styles.suggestedPill}
                    onClick={() => handleSendSuggested(text)}
                    disabled={sending || suggestedSendingText !== null}
                    aria-label={`Send suggested message: "${text}"`}
                    title={`Send: "${text}"`}
                  >
                    {suggestedSendingText === text ? (
                      <span className={styles.suggestedSendingText}>Sending…</span>
                    ) : (
                      <span>{text}</span>
                    )}
                  </button>
                ))}
              </div>
            </div>
          )}

          <form className={styles.inputForm} onSubmit={handleSend}>
            <input
              ref={inputRef}
              type="text"
              className={styles.chatInput}
              placeholder="Type a message…"
              value={inputText}
              onChange={(e) => setInputText(e.target.value)}
              aria-label="Type a message"
              maxLength={2000}
            />
            <button
              type="submit"
              className={styles.sendBtn}
              disabled={!inputText.trim() || sending}
              aria-label="Send message"
              title="Send message"
            >
              <PaperPlaneRight size={22} weight="fill" />
            </button>
          </form>
        </div>
      )}
    </div>
  )
}
