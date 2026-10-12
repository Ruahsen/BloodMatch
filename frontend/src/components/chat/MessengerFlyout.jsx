import React, { useState, useEffect, useRef, useCallback } from 'react'
import { ChatCircleDots, MagnifyingGlass, User } from '@phosphor-icons/react'
import { api } from '../../services/apiClient'
import { useChat } from '../../context/ChatContext'
import styles from './MessengerFlyout.module.css'

function formatSnippetTime(isoString) {
  if (!isoString) return ''
  try {
    const d = new Date(isoString.includes('T') ? isoString : `${isoString.replace(' ', 'T')}Z`)
    const now = new Date()
    const diffSec = Math.floor((now.getTime() - d.getTime()) / 1000)

    if (diffSec < 60) return 'Just now'
    if (diffSec < 3600) return `${Math.floor(diffSec / 60)}m`
    if (diffSec < 86400) return `${Math.floor(diffSec / 3600)}h`
    if (diffSec < 172800) return 'Yesterday'
    return d.toLocaleDateString([], { month: 'short', day: 'numeric' })
  } catch {
    return ''
  }
}

function ConvAvatar({ src, name }) {
  const [failed, setFailed] = useState(false)
  useEffect(() => {
    setFailed(false)
  }, [src])

  if (!src || failed) {
    return <User size={22} weight="bold" />
  }

  return (
    <img
      src={src}
      alt={name ? `${name}'s profile picture` : ''}
      className={styles.convAvatar}
      onError={() => setFailed(true)}
    />
  )
}

export default function MessengerFlyout({ variant = 'desktop' }) {
  const { unreadCount, openConversationTab } = useChat()
  const [open, setOpen] = useState(false)
  const [conversations, setConversations] = useState([])
  const [search, setSearch] = useState('')
  const [loading, setLoading] = useState(false)
  const rootRef = useRef(null)

  const loadConversations = useCallback(async () => {
    setLoading(true)
    try {
      const res = await api.get('/api/chat/conversations')
      if (Array.isArray(res?.conversations)) {
        setConversations(res.conversations)
      }
    } catch {
      // Ignored
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    if (open) {
      loadConversations()
    }
  }, [open, loadConversations])

  // Handle outside click & escape key
  useEffect(() => {
    if (!open) return undefined

    const onPointerDown = (e) => {
      if (rootRef.current && !rootRef.current.contains(e.target)) {
        setOpen(false)
      }
    }
    const onKeyDown = (e) => {
      if (e.key === 'Escape') {
        setOpen(false)
      }
    }

    document.addEventListener('pointerdown', onPointerDown)
    document.addEventListener('keydown', onKeyDown)

    return () => {
      document.removeEventListener('pointerdown', onPointerDown)
      document.removeEventListener('keydown', onKeyDown)
    }
  }, [open])

  const filtered = conversations.filter((c) => {
    if (!search.trim()) return true
    const name = c.recipient?.full_name?.toLowerCase() || ''
    return name.includes(search.toLowerCase().trim())
  })

  const handleSelect = (conv) => {
    openConversationTab(conv)
    setOpen(false)
  }

  const badgeText = unreadCount > 0 ? (unreadCount > 99 ? '99+' : String(unreadCount)) : null

  return (
    <div ref={rootRef} className={styles.wrap}>
      <button
        type="button"
        className={styles.triggerBtn}
        onClick={() => setOpen((prev) => !prev)}
        aria-haspopup="dialog"
        aria-expanded={open}
        aria-label={`Chats${unreadCount > 0 ? `, ${unreadCount} unread` : ''}`}
        title="Messenger chats"
      >
        <ChatCircleDots size={variant === 'mobile' ? 24 : 26} weight="regular" aria-hidden="true" />
        {badgeText && <span className={styles.badge}>{badgeText}</span>}
      </button>

      {open && (
        <div className={styles.popover} role="dialog" aria-label="Chats menu">
          <div className={styles.header}>
            <div className={styles.headerTop}>
              <h3 className={styles.title}>Chats</h3>
            </div>
            <div className={styles.searchWrap}>
              <MagnifyingGlass size={16} className={styles.searchIcon} aria-hidden="true" />
              <input
                type="text"
                className={styles.searchInput}
                placeholder="Search Messenger…"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                aria-label="Search Messenger chats"
              />
            </div>
          </div>

          <div className={styles.listArea}>
            {loading ? (
              <div className={styles.emptyState}>
                <p>Loading chats…</p>
              </div>
            ) : filtered.length === 0 ? (
              <div className={styles.emptyState}>
                <h4>No chats found</h4>
                <p>
                  {search
                    ? 'No conversations matched your search.'
                    : 'Start a conversation with a donor or requester.'}
                </p>
              </div>
            ) : (
              filtered.map((conv) => {
                const isUnread = conv.unread_count > 0
                const name = conv.recipient?.full_name || 'Member'
                const avatar = conv.recipient?.profile_picture_url
                const lastMsg = conv.last_message

                return (
                  <button
                    key={conv.id}
                    type="button"
                    className={`${styles.convItem} ${isUnread ? styles.convItemUnread : ''}`}
                    onClick={() => handleSelect(conv)}
                  >
                    <div className={styles.convAvatarWrap}>
                      <ConvAvatar src={avatar} name={name} />
                    </div>
                    <div className={styles.convContent}>
                      <div className={styles.convTopLine}>
                        <span className={styles.convName}>{name}</span>
                        {lastMsg && (
                          <span className={styles.convTime}>
                            {formatSnippetTime(lastMsg.created_at)}
                          </span>
                        )}
                      </div>
                      <div className={styles.convBottomLine}>
                        <span
                          className={`${styles.convSnippet} ${
                            isUnread ? styles.convSnippetUnread : ''
                          }`}
                        >
                          {lastMsg ? lastMsg.body : 'Conversation started'}
                        </span>
                        {isUnread && <span className={styles.unreadDot} aria-hidden="true" />}
                      </div>
                    </div>
                  </button>
                )
              })
            )}
          </div>
        </div>
      )}
    </div>
  )
}
