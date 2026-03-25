// Customer chat widget bootstrap script.
// This script is standalone: it renders the floating button, panel UI,
// talks to the PHP customer API, and optionally connects to the WebSocket
// server for realtime updates (falling back to HTTP polling).
(() => {
  // Base URL for the customer API. Can be overridden via window.LC_CHAT_API
  // before this script is loaded.
  const API =
    window.LC_CHAT_API ||
    new URL("../api/chat.php", document.currentScript?.src || window.location.href).toString();

  // In-memory widget state (current chat, tags, unread counts, etc).
  const state = {
    chatId: null,
    chat: null,
    tags: [],
    selectedTagIds: new Set(),
    lastMessageId: 0,
    unreadCount: 0,
    pollingTimer: null,
    historyOpen: false,
    panelOpen: false,
    isTyping: false,
    typingTimeoutId: null,
  };

  // Small DOM helper used throughout the widget.
  function el(tag, attrs = {}, children = []) {
    const n = document.createElement(tag);
    Object.entries(attrs).forEach(([k, v]) => {
      if (k === "class") n.className = v;
      else if (k === "text") n.textContent = v;
      else if (k.startsWith("on") && typeof v === "function") n.addEventListener(k.slice(2), v);
      else n.setAttribute(k, String(v));
    });
    children.forEach((c) => n.appendChild(c));
    return n;
  }

  // Render a readable timestamp string from an ISO date.
  function fmtTime(iso) {
    try { return new Date(iso).toLocaleString(); } catch { return iso || ""; }
  }

  // Thin wrappers around fetch() for GET/POST calls to the customer API.
  async function apiGet(action, params = {}) {
    const sp = new URLSearchParams({ action, ...params });
    const res = await fetch(`${API}?${sp.toString()}`, { credentials: "same-origin" });
    const data = await res.json();
    if (!data.ok) {
      const err = new Error(data.error || "Request failed");
      err.statusCode = res.status;
      throw err;
    }
    return data;
  }

  async function apiPost(action, body = {}) {
    const res = await fetch(`${API}?action=${encodeURIComponent(action)}`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body),
      credentials: "same-origin",
    });
    const data = await res.json();
    if (!data.ok) {
      const err = new Error(data.error || "Request failed");
      err.statusCode = res.status;
      throw err;
    }
    return data;
  }

  async function apiPostForm(action, formData) {
    const res = await fetch(`${API}?action=${encodeURIComponent(action)}`, {
      method: "POST",
      body: formData,
      credentials: "same-origin",
    });
    const data = await res.json();
    if (!data.ok) {
      const err = new Error(data.error || "Request failed");
      err.statusCode = res.status;
      throw err;
    }
    return data;
  }

  // -------------------------------------------------------------------------
  // DOM construction
  // -------------------------------------------------------------------------
  const root = el("div", { id: "lc-root" });
  const historyPanel = el("div", { class: "lc-historyPanel", id: "lc-historyPanel" }, [
    el("div", { class: "lc-historyHeader", text: "Your chats" }),
    el("div", { class: "lc-historyList", id: "lc-historyList" }),
  ]);

  const panel = el("div", { class: "lc-panel", id: "lc-panel" });
  const headerLeft = el("div", { class: "lc-headerLeft" }, [
    el("div", { class: "lc-title", id: "lc-title", text: "Chat" }),
    el("div", { class: "lc-sub", id: "lc-sub", text: "" }),
  ]);
  const minimizeBtn = el("button", { class: "lc-miniBtn", text: "Minimize", onclick: () => closePanel() });
  const deleteBtn = el("button", { class: "lc-miniBtn", id: "lc-deleteBtn", text: "Delete", style: "display: none;", onclick: () => deleteChat() });
  const headerRight = el("div", { class: "lc-headerRight" }, [deleteBtn, minimizeBtn]);
  const header = el("div", { class: "lc-header" }, [headerLeft, headerRight]);
  const body = el("div", { class: "lc-body", id: "lc-body" });

  const tagsWrap = el("div", { class: "lc-tags", id: "lc-tagsWrap" }, [
    el("div", { class: "lc-tagsTitle", text: "Select the tags that best match your issue (required before chatting)" }),
    el("div", { class: "lc-tagRow", id: "lc-tagRow" }),
    el("div", { class: "lc-tagsActions" }, [
      el("button", { class: "lc-miniBtn lc-primary", id: "lc-saveTagsBtn", text: "Save tags", onclick: () => saveTags() }),
    ]),
  ]);

  const composer = el("form", { class: "lc-composer", id: "lc-composer", onsubmit: (e) => onSend(e) }, [
    el("input", { class: "lc-input", id: "lc-input", placeholder: "Type a message…", autocomplete: "off" }),
    el("button", { class: "lc-send", id: "lc-fileBtn", type: "button", text: "+", title: "Attach file", "aria-label": "Attach file", onclick: () => onPickFile() }),
    el("button", { class: "lc-send", id: "lc-send", type: "submit", text: "Send" }),
    el("input", { id: "lc-fileInput", type: "file", style: "display:none;" }),
  ]);

  const typingIndicator = el("div", { class: "lc-typingIndicator", id: "lc-typingIndicator", style: "display: none;" }, [
    el("div", { class: "lc-typingDots" }, [
      el("span"),
      el("span"),
      el("span"),
    ]),
    el("span", { text: "Employee is typing…" }),
  ]);

  panel.appendChild(header);
  panel.appendChild(body);
  panel.appendChild(tagsWrap);
  panel.appendChild(typingIndicator);
  panel.appendChild(composer);

  const historyBtn = el("button", { class: "lc-historyBtn", title: "Chat history", onclick: () => toggleHistory() }, [
    el("span", { text: "≡" }),
  ]);
  const resumeBtn = el("button", { class: "lc-resumeBtn", id: "lc-resumeBtn", onclick: () => openPanel() }, [
    el("span", { text: "Resume" }),
    el("span", { class: "lc-badge", id: "lc-badge", text: "0" }),
  ]);
  const fab = el("button", { class: "lc-fab", title: "Live chat", onclick: () => togglePanel() }, [
    el("span", { text: "Chat" }),
  ]);

  const fabRow = el("div", { class: "lc-fabRow" }, [historyBtn, resumeBtn, fab]);
  root.appendChild(historyPanel);
  root.appendChild(panel);
  root.appendChild(fabRow);
  document.body.appendChild(root);

  // Cache frequently used DOM nodes for faster updates.
  const ui = {
    historyPanel,
    historyList: historyPanel.querySelector("#lc-historyList"),
    panel,
    body,
    title: panel.querySelector("#lc-title"),
    sub: panel.querySelector("#lc-sub"),
    tagRow: panel.querySelector("#lc-tagRow"),
    tagsWrap,
    input: panel.querySelector("#lc-input"),
    fileBtn: panel.querySelector("#lc-fileBtn"),
    fileInput: panel.querySelector("#lc-fileInput"),
    sendBtn: panel.querySelector("#lc-send"),
    deleteBtn: panel.querySelector("#lc-deleteBtn"),
    resumeBtn,
    badge: resumeBtn.querySelector("#lc-badge"),
    typingIndicator,
  };

  // Show/hide the small "Resume" pill button next to the main FAB.
  function setResumeVisible(visible) {
    ui.resumeBtn.style.display = visible ? "flex" : "none";
  }

  // Update unread badge count and toggle visibility.
  function setUnreadCount(n) {
    state.unreadCount = n;
    ui.badge.textContent = String(n);
    setResumeVisible(n > 0);
  }

  // Open/close the main chat panel.
  function setPanelOpen(open) {
    state.panelOpen = open;
    ui.panel.classList.toggle("open", open);
    if (open) {
      setUnreadCount(0);
      ui.input.focus();
    }
  }

  function closePanel() {
    setPanelOpen(false);
  }

  async function openPanel() {
    setPanelOpen(true);
    // If the currently loaded chat is closed, don't reuse it - create new instead so the user cant have multiple chats open simultaneously.
    if (state.chatId && state.chat && state.chat.status === "closed") {
      state.chatId = null;
      state.chat = null;
      state.tags = [];
      state.selectedTagIds = new Set();
    }
    if (state.chatId) {
      await loadChat(state.chatId);
    } else {
      await ensureActiveChat();
    }
    await refreshHistory();
  }

  function togglePanel() {
    if (state.panelOpen) closePanel();
    else openPanel().catch(console.error);
  }

  // Open/close the chat history panel listing previous conversations.
  function toggleHistory() {
    state.historyOpen = !state.historyOpen;
    ui.historyPanel.classList.toggle("open", state.historyOpen);
    if (state.historyOpen) refreshHistory().catch(console.error);
  }

  // Remove all messages from the panel body.
  function clearMessages() {
    ui.body.innerHTML = "";
  }

  // Append a single message bubble to the panel.
  function addMessage(m, { bumpUnread } = { bumpUnread: false }) {
    const div = document.createElement("div");
    div.className =
      m.sender_type === "customer"
        ? "lc-msg me"
        : m.sender_type === "system"
          ? "lc-msg system"
          : "lc-msg";

    // For customers: a message is "read" by employee when `employee_read_at` is set to ensure and show the message is read.
    const isRead = m.employee_read_at !== null;
    
    // Determine sender name
    let senderName = m.sender_type;
    if (m.sender_type === 'system') {
      // Label system messages as Corenio Bot unless they were authored by a specific employee
      if (m.sender_employee_id) {
        senderName = window.LC_EMPLOYEE_NAMES?.[m.sender_employee_id] || 'System';
      } else {
        senderName = 'Corenio Bot';
      }
    } else if (m.sender_type === "employee" && m.employee_username) {
      senderName = m.employee_username;
    }

    const fileHtml = m.file_id
      ? renderFileMessageHtml(m)
      : "";

    div.innerHTML = `
      ${fileHtml || `<div>${escapeHtml(m.body)}</div>`}
      <div class="lc-msgMeta">${escapeHtml(senderName)} • ${escapeHtml(fmtTime(m.created_at))}${isRead && m.sender_type === "customer" ? ' <span class="lc-msgRead" title="Employee read this">✓</span>' : ''}</div>
    `;
    ui.body.appendChild(div);
    ui.body.scrollTop = ui.body.scrollHeight;

    const idNum = Number(m.id) || 0;
    state.lastMessageId = Math.max(state.lastMessageId, idNum);

    if (bumpUnread && !state.panelOpen) {
      setUnreadCount(state.unreadCount + 1);
    }
  }

  // Minimal HTML-escaping to safely render user content in innerHTML.
  function escapeHtml(s) {
    const d = document.createElement("div");
    d.textContent = s ?? "";
    return d.innerHTML;
  }

  function getFileDownloadUrl(fileId) {
    return `${API}?action=download_file&file_id=${encodeURIComponent(String(fileId))}`;
  }

  function getFileInlineUrl(fileId) {
    return `${API}?action=download_file&file_id=${encodeURIComponent(String(fileId))}&inline=1`;
  }

  function isImageMime(mime) {
    return typeof mime === "string" && mime.toLowerCase().startsWith("image/");
  }

  function renderFileMessageHtml(m) {
    const fileName = escapeHtml(m.file_name || "Download file");
    const sizeLabel = formatBytes(m.file_size_bytes);
    const downloadUrl = escapeHtml(getFileDownloadUrl(m.file_id));
    if (isImageMime(m.file_mime)) {
      const imgUrl = escapeHtml(getFileInlineUrl(m.file_id));
      return `
        <div class="lc-fileWrap">
          <a href="${downloadUrl}" target="_blank" rel="noopener noreferrer" class="lc-fileLink">${fileName}</a>
          <div class="lc-fileSize">${sizeLabel}</div>
          <a href="${imgUrl}" target="_blank" rel="noopener noreferrer">
            <img class="lc-fileImage" src="${imgUrl}" alt="${fileName}" loading="lazy" />
          </a>
        </div>
      `;
    }
    return `<div class="lc-fileWrap"><a href="${downloadUrl}" target="_blank" rel="noopener noreferrer" class="lc-fileLink">${fileName}</a> <span class="lc-fileSize">(${sizeLabel})</span></div>`;
  }

  function formatBytes(value) {
    const n = Number(value) || 0;
    if (n <= 0) return "0 B";
    if (n < 1024) return `${n} B`;
    if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`;
    return `${(n / (1024 * 1024)).toFixed(1)} MB`;
  }

  // Re-render the tag chips row based on current selection.
  function renderTags() {
    ui.tagRow.innerHTML = "";
    state.tags.forEach((t) => {
      const b = el("button", { class: "lc-tag", type: "button", text: "#" + t.name });
      if (state.selectedTagIds.has(Number(t.id))) b.classList.add("selected");
      b.addEventListener("click", () => {
        const id = Number(t.id);
        if (state.selectedTagIds.has(id)) state.selectedTagIds.delete(id);
        else state.selectedTagIds.add(id);
        renderTags();
      });
      ui.tagRow.appendChild(b);
    });
  }

  // Update the panel header (title + subtitle) with chat id/status/tags.
  function updateHeader() {
    if (!state.chat) {
      ui.title.textContent = "Chat";
      ui.sub.textContent = "";
      return;
    }
    const tags = (state.chat.tags || []).map((t) => t.name).join(", ");
    ui.title.textContent = `Chat #${state.chat.id}`;
    ui.sub.textContent = `${state.chat.status}${tags ? " • " + tags : ""}`;
  }

  // Enable/disable the composer depending on whether the chat is closed
  // and whether the customer has selected at least one tag to make sure they dont open a chat without one, so we know where their issue lies so we can match it to the right employee.
  function updateComposer() {
    const closed = state.chat && state.chat.status === "closed";
    const needsTags = state.chat && state.tags.length > 0 && state.selectedTagIds.size === 0;

    ui.input.disabled = !state.chat || closed || needsTags;
    ui.sendBtn.disabled = !state.chat || closed || needsTags;
    ui.fileBtn.disabled = !state.chat || closed || needsTags;
    ui.fileInput.disabled = !state.chat || closed || needsTags;
    
    // Show delete button only for closed chats
    ui.deleteBtn.style.display = closed ? "" : "none";

    if (closed) {
      ui.input.placeholder = "Chat is closed (read-only)";
    } else if (needsTags) {
      ui.input.placeholder = "Please select at least one tag to start chatting";
    } else {
      ui.input.placeholder = "Type a message…";
    }
  }

  function onPickFile() {
    if (ui.fileInput.disabled) return;
    ui.fileInput.click();
  }

  async function uploadFile(file) {
    if (!state.chatId || !state.chat) return;
    if (state.chat.status === "closed") return;
    if (!file) return;

    const form = new FormData();
    form.append("chat_id", String(state.chatId));
    form.append("file", file);
    const data = await apiPostForm("send_file", form);
    addMessage(data.message, { bumpUnread: false });
    await refreshHistory();
  }

  // Start a lightweight polling loop as a fallback when WS is unavailable.
  function startPolling() {
    if (state.pollingTimer) return;
    state.pollingTimer = setInterval(async () => {
      if (!state.chatId) return;
      try {
        const data = await apiGet("poll", { chat_id: state.chatId, since_id: state.lastMessageId });
        const prevStatus = state.chat?.status;
        state.chat = { ...state.chat, ...data.chat };
        updateHeader();
        updateComposer();
        (data.messages || []).forEach((m) => addMessage(m, { bumpUnread: true }));
        if (prevStatus === "closed" && state.chat.status !== "closed") {
          setUnreadCount(state.unreadCount + 1);
        }
        
        // Check employee typing status to let the customer know when an employee is responding, even if no new messages were sent.
        await checkEmployeeTyping();
      } catch (err) {
        // If chat was deleted (404), reset state and reload history
        if (err.statusCode === 404) {
          state.chatId = null;
          state.chat = null;
          state.tags = [];
          state.selectedTagIds = new Set();
          clearMessages();
          await refreshHistory();
        }
        // ignore other errors
      }
    }, 2000);
  }

  // Check if employee is typing in current chat
  async function checkEmployeeTyping() {
    if (!state.chatId) return;
    try {
      const data = await apiGet("get_typing_status", { chat_id: state.chatId });
      if (data.typing && data.typing.employee_typing) {
        ui.typingIndicator.style.display = "flex";
      } else {
        ui.typingIndicator.style.display = "none";
      }
    } catch {
      // ignore
    }
  }

  // Ensure there is an "active" chat for this browser/customer.
  // - reuses an existing open/taken chat when possible
  // - may prompt for a customer name once and persist it in localStorage
  async function ensureActiveChat() {
    let customerName = null;
    try {
      customerName = (localStorage.getItem("lc_customer_name") || "").trim() || null;
      if (!customerName) {
        const p = prompt("Your name (optional):");
        if (typeof p === "string" && p.trim()) {
          customerName = p.trim().slice(0, 128);
          localStorage.setItem("lc_customer_name", customerName);
        }
      }
    } catch {
      // ignore
    }

    const data = await apiPost("create_or_get", { customer_name: customerName });
    state.chatId = data.chat.id;
    state.chat = data.chat;
    state.tags = data.tags || [];
    state.selectedTagIds = new Set((state.chat.tags || []).map((t) => Number(t.id)));

    clearMessages();
    (data.messages || []).forEach((m) => addMessage(m, { bumpUnread: false }));

    renderTags();
    updateHeader();
    updateComposer();

    // Hide tag selector if tags have already been chosen for this chat, or if chat is closed (read-only)
    const isClosed = state.chat && state.chat.status === "closed";
    ui.tagsWrap.style.display = (isClosed || (state.chat.tags && state.chat.tags.length > 0)) ? 'none' : '';

    startPolling();
  }

  // Load an existing chat (from history list) into the main panel.
  async function loadChat(chatId) {
    const data = await apiGet("get_chat", { chat_id: chatId });
    state.chatId = data.chat.id;
    state.chat = data.chat;
    state.tags = data.tags || [];
    state.selectedTagIds = new Set((state.chat.tags || []).map((t) => Number(t.id)));
    state.lastMessageId = 0;

    clearMessages();
    (data.messages || []).forEach((m) => addMessage(m, { bumpUnread: false }));
    renderTags();
    updateHeader();
    updateComposer();

    // Hide tag selector if tags have already been chosen for this chat, or if chat is closed (read-only)
    const isClosed = state.chat && state.chat.status === "closed";
    ui.tagsWrap.style.display = (isClosed || (state.chat.tags && state.chat.tags.length > 0)) ? 'none' : '';

    startPolling();
    
    // Mark all employee messages as read by customer
    try {
      await apiPost("mark_read", { chat_id: chatId });
    } catch {
      // ignore
    }
  }

  // Persist currently selected tags back to the server.
  async function saveTags() {
    if (!state.chatId) return;
    const tagIds = Array.from(state.selectedTagIds);
    const data = await apiPost("set_tags", { chat_id: state.chatId, tag_ids: tagIds });
    state.chat.tags = data.tags || [];
    updateHeader();
    updateComposer();
    ui.tagsWrap.style.display = "none";
    await refreshHistory();
    // Reload the current chat to fetch any bot/system messages added after saving tags
    try { await loadChat(state.chatId); } catch (e) { /* ignore */ }
  }

  // Handle the "Send" form submission.
  // Prefers WebSockets for realtime, falls back to HTTP POST if needed.
  async function onSend(e) {
    e.preventDefault();
    if (!state.chatId || !state.chat) return;
    if (state.chat.status === "closed") return;
    const body = ui.input.value.trim();
    if (!body) return;
    ui.input.value = "";

    // Mark as not typing
    state.isTyping = false;
    if (state.typingTimeoutId) clearTimeout(state.typingTimeoutId);
    try {
      await apiPost("set_typing", { chat_id: state.chatId, is_typing: false });
    } catch {
      // ignore
    }

    const data = await apiPost("send", { chat_id: state.chatId, body });
    addMessage(data.message, { bumpUnread: false });
    await refreshHistory();
  }

  // Monitor typing in input
  ui.input.addEventListener("input", async () => {
    if (!state.chatId) return;

    // Mark as typing to show the typing indicator.
    if (!state.isTyping) {
      state.isTyping = true;
      try {
        await apiPost("set_typing", { chat_id: state.chatId, is_typing: true });
      } catch {
        // ignore
      }
    }

    // Reset timeout for stopping typing
    if (state.typingTimeoutId) clearTimeout(state.typingTimeoutId);
    state.typingTimeoutId = setTimeout(async () => {
      state.isTyping = false;
      try {
        await apiPost("set_typing", { chat_id: state.chatId, is_typing: false });
      } catch {
        // ignore
      }
    }, 3000); // Stop typing after 3 seconds of no input
  });

  ui.fileInput.addEventListener("change", async () => {
    const file = ui.fileInput.files && ui.fileInput.files[0] ? ui.fileInput.files[0] : null;
    if (!file) return;
    try {
      await uploadFile(file);
    } catch (err) {
      alert(err?.message || "Failed to upload file");
    } finally {
      ui.fileInput.value = "";
    }
  });

  // Delete the current chat from customer's view (soft delete).
  // Sends delete_chat request to API, then refreshes the chat list.
  // The chat remains visible to employees and data is preserved.
  async function deleteChat() {
    if (!state.chatId) return;
    
    try {
      // Call API to soft-delete the chat
      await apiPost("delete_chat", { chat_id: state.chatId });
      // Clear the current chat state
      state.chatId = null;
      state.chat = null;
      // Close the panel and refresh history to remove deleted chat from list
      setPanelOpen(false);
      await refreshHistory();
      updateComposer();
    } catch (err) {
      console.error("Error deleting chat:", err);
      alert("Failed to delete chat. Please try again.");
    }
  }

  // Refresh the history list (small panel with recent chats).
  async function refreshHistory() {
    try {
      const data = await apiGet("list_chats");
      const list = data.chats || [];
      ui.historyList.innerHTML = "";
      list.forEach((c) => {
        const item = document.createElement("div");
        item.className = "lc-historyItem";
        const name = c.customer_name || "You";
        const tags = (c.tags || []).map((t) => t.name).join(", ");
        item.innerHTML = `
          <div class="top">
            <div><strong>#${c.id}</strong> — ${escapeHtml(name)}</div>
            <div class="lc-pill ${escapeHtml(c.status)}">${escapeHtml(c.status)}</div>
          </div>
          <div class="meta">${escapeHtml(tags || "No tags")} • Updated: ${escapeHtml(fmtTime(c.updated_at))}</div>
        `;
        item.addEventListener("click", async () => {
          setPanelOpen(true);
          await loadChat(c.id);
        });
        ui.historyList.appendChild(item);
      });

      // If there's an active chat, show a small "Resume" button (without auto-creating new chats).
      const active = list.find((c) => c.status === "open" || c.status === "taken");
      if (active) {
        state.chatId = state.chatId || active.id;
        if (!state.panelOpen) setUnreadCount(Math.max(state.unreadCount, 1));
      }
    } catch {
      // ignore
    }
  }

  // Boot: load history only (do NOT create a new chat until the user opens it).
  (async () => {
    try {
      await refreshHistory();
      closePanel();
    } catch {
      // Widget still shows, but no backend yet.
      closePanel();
    }
  })();
})();

